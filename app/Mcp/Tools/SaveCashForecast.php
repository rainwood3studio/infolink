<?php

namespace App\Mcp\Tools;

use App\Domain\Finance\CashForecaster;
use App\Models\CashForecast;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('save_cash_forecast')]
#[Description('Calculate and save a month-end cash forecast snapshot with the app\'s method (high-confidence outstanding receivables at TAXED amounts + planned cash flows − monthly cost baseline). as_of defaults to the latest bank transaction date and opening_balance to the bank balance on that date. Idempotent on as_of + label: saving again replaces the snapshot Claude saved with the same as_of and label. Use a label (e.g. "長照延到11月") for what-if scenarios. Returns the monthly rows, min balance and year-end balance.')]
class SaveCashForecast extends WriteTool
{
    public function handle(Request $request, CashForecaster $forecaster): Response
    {
        if ($denied = $this->forbidden($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'as_of' => ['nullable', 'date_format:Y-m-d'],
            'label' => ['nullable', 'string', 'max:100'],
            'opening_balance' => ['nullable', 'integer'],
            'until' => ['nullable', 'regex:/^\d{4}-\d{2}(-\d{2})?$/'],
        ], [
            'as_of.date_format' => 'as_of must be YYYY-MM-DD.',
            'opening_balance.integer' => 'opening_balance must be a whole NTD integer.',
            'until.regex' => 'until must be YYYY-MM or YYYY-MM-DD.',
        ]);

        $asOf = isset($validated['as_of']) ? CarbonImmutable::parse($validated['as_of']) : null;
        $openingBalance = $validated['opening_balance'] ?? null;
        $until = $validated['until'] ?? null;
        $label = filled($validated['label'] ?? null) ? $validated['label'] : null;

        try {
            [$forecast, $result] = DB::transaction(function () use ($forecaster, $asOf, $openingBalance, $until, $label): array {
                $calculated = $forecaster->calculate($asOf, $openingBalance, $until);
                $existing = $this->findExisting($calculated->asOf, $label);

                if ($existing !== null) {
                    $existing->fill($calculated->toArray());
                    $existing->assumptions = [...$calculated->assumptions, 'label' => $label];
                    $existing->save();

                    return [$existing, 'updated'];
                }

                $forecast = $forecaster->snapshot($calculated->asOf, $openingBalance, $until, self::SOURCE);
                $forecast->assumptions = [...$forecast->assumptions, 'label' => $label];
                $forecast->save();

                return [$forecast, 'created'];
            });
        } catch (InvalidArgumentException $exception) {
            return Response::error($exception->getMessage());
        }

        $assumptions = $forecast->assumptions;

        return Response::json([
            'result' => $result,
            'id' => $forecast->id,
            'as_of' => $forecast->as_of->toDateString(),
            'label' => $label,
            'opening_balance' => $forecast->opening_balance,
            'opening_balance_source' => $assumptions['opening_balance_source'] ?? null,
            'min_balance' => $forecast->min_balance,
            'min_balance_month' => $forecast->min_balance_month,
            'year_end_balance' => $forecast->year_end_balance,
            'rows' => array_map(fn (array $row): array => [
                'month' => $row['month'],
                'inflow' => $row['inflow'],
                'outflow' => $row['outflow'],
                'balance' => $row['balance'],
            ], $forecast->rows),
            'excluded_low_confidence_taxed' => $assumptions['excluded_low_confidence']['amount_taxed'] ?? 0,
        ]);
    }

    /**
     * A snapshot Claude saved earlier for the same as_of and label (null label matches unlabelled ones).
     * System and manual snapshots are never overwritten.
     */
    protected function findExisting(CarbonImmutable $asOf, ?string $label): ?CashForecast
    {
        return CashForecast::query()
            ->fromSource(self::SOURCE)
            ->whereDate('as_of', $asOf->toDateString())
            ->when(
                $label === null,
                fn ($query) => $query->whereNull('assumptions->label'),
                fn ($query) => $query->where('assumptions->label', $label),
            )
            ->latest('id')
            ->first();
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'as_of' => $schema->string()->format('date')->description('Base date YYYY-MM-DD. Default: date of the latest bank transaction.'),
            'label' => $schema->string()->description('Scenario label, stored in assumptions.label; part of the idempotency key.'),
            'opening_balance' => $schema->integer()->description('Override the opening balance (NTD). Default: bank balance on as_of.'),
            'until' => $schema->string()->description('Last month (YYYY-MM) or date to forecast. Default: end of as_of\'s year.'),
        ];
    }
}
