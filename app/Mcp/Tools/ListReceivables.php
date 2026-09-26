<?php

namespace App\Mcp\Tools;

use App\Enums\Confidence;
use App\Enums\ReceivableStatus;
use App\Mcp\Tools\Concerns\PresentsRecords;
use App\Models\Receivable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list_receivables')]
#[Description(<<<'TEXT'
List receivables (expected customer payments), ordered by `expected_on`.
Amounts are whole NTD: `untaxed` is before 5% VAT, `taxed` includes it (cash actually received is the taxed amount). `tax_rate` is a fraction (0.05).
`status`: planned (not invoiced yet), invoiced, received, cancelled; the filter also accepts `outstanding` (planned + invoiced, the default) and `all`.
`days_overdue` is days past `expected_on` for outstanding receivables, else 0. `confidence` high/low: only high-confidence money counts in the cash forecast and the AR headline.
Recurring receivables (`is_recurring`, monthly maintenance fees) are excluded unless `include_recurring` is true, matching the AR metrics.
`customer` matches the customer short name or full name (partial). `external_key` is the idempotency key used by upsert_receivable.
`totals` sums the returned rows (taxed and untaxed), with high/low-confidence and overdue taxed subtotals.
TEXT)]
class ListReceivables extends ReadTool
{
    use PresentsRecords;

    public const string STATUS_OUTSTANDING = 'outstanding';

    public const string STATUS_ALL = 'all';

    public const int MAX_ROWS = 300;

    public function handle(Request $request): Response
    {
        if ($denied = $this->forbidden($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', self::statuses())],
            'customer' => ['nullable', 'string', 'max:255'],
            'overdue_only' => ['nullable', 'boolean'],
            'include_recurring' => ['nullable', 'boolean'],
        ]);

        $status = $validated['status'] ?? self::STATUS_OUTSTANDING;

        $receivables = Receivable::query()
            ->with(['customer', 'project'])
            ->when($status === self::STATUS_OUTSTANDING, fn (Builder $query) => $query->outstanding())
            ->when(! in_array($status, [self::STATUS_OUTSTANDING, self::STATUS_ALL], true), fn (Builder $query) => $query->where('status', $status))
            ->when($validated['overdue_only'] ?? false, fn (Builder $query) => $query->overdue())
            ->when(! ($validated['include_recurring'] ?? false), fn (Builder $query) => $query->where('is_recurring', false))
            ->when(filled($validated['customer'] ?? null), fn (Builder $query) => $query->whereHas('customer', fn (Builder $customer) => $customer
                ->whereLike('short_name', '%'.$validated['customer'].'%')
                ->orWhereLike('name', '%'.$validated['customer'].'%')))
            ->orderBy('expected_on')
            ->orderBy('id')
            ->limit(self::MAX_ROWS)
            ->get();

        return Response::json([
            'filters' => [
                'status' => $status,
                'customer' => $validated['customer'] ?? null,
                'overdue_only' => (bool) ($validated['overdue_only'] ?? false),
                'include_recurring' => (bool) ($validated['include_recurring'] ?? false),
            ],
            'totals' => self::totals($receivables),
            'receivables' => $receivables->map(fn (Receivable $receivable): array => static::presentReceivable($receivable))->all(),
        ]);
    }

    /**
     * @param  Collection<int, Receivable>  $receivables
     * @return array{count: int, untaxed: int, taxed: int, high_confidence_taxed: int, low_confidence_taxed: int, overdue_taxed: int}
     */
    public static function totals(Collection $receivables): array
    {
        return [
            'count' => $receivables->count(),
            'untaxed' => (int) $receivables->sum('amount_untaxed'),
            'taxed' => (int) $receivables->sum('amount_taxed'),
            'high_confidence_taxed' => (int) $receivables->where('confidence', Confidence::High)->sum('amount_taxed'),
            'low_confidence_taxed' => (int) $receivables->where('confidence', Confidence::Low)->sum('amount_taxed'),
            'overdue_taxed' => (int) $receivables->filter(fn (Receivable $receivable): bool => $receivable->is_overdue)->sum('amount_taxed'),
        ];
    }

    /**
     * @return list<string>
     */
    protected static function statuses(): array
    {
        return [...array_column(ReceivableStatus::cases(), 'value'), self::STATUS_OUTSTANDING, self::STATUS_ALL];
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->enum(self::statuses())
                ->description('Default `outstanding` (planned + invoiced). `all` for every status.'),
            'customer' => $schema->string()
                ->description('Partial match on customer short name or full name, e.g. 長照 or 墊腳石.'),
            'overdue_only' => $schema->boolean()
                ->description('Only outstanding receivables past expected_on. Default false.'),
            'include_recurring' => $schema->boolean()
                ->description('Include recurring monthly fees. Default false.'),
        ];
    }
}
