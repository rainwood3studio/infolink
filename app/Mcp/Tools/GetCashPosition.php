<?php

namespace App\Mcp\Tools;

use App\Domain\Finance\FinancePosition;
use App\Models\CashForecast;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get_cash_position')]
#[Description('Current cash position: latest bank balance (NTD, integer), recurring monthly cost, runway in months, outstanding receivables (taxed; high/low confidence; overdue), the live month-end forecast to year end, and how it differs from the last saved forecast snapshot. Amounts are whole NTD.')]
class GetCashPosition extends ReadTool
{
    public function handle(Request $request, FinancePosition $financePosition): Response
    {
        if ($denied = $this->forbidden($request)) {
            return $denied;
        }

        $position = $financePosition->current();

        if ($position === null) {
            return Response::json(['available' => false, 'reason' => 'No bank transactions imported yet.']);
        }

        $forecast = $position['forecast'];
        $previous = CashForecast::query()->latest('as_of')->latest('id')->first();

        return Response::json([
            'available' => true,
            'as_of' => $position['as_of']->toDateString(),
            'balance' => $position['balance'],
            'monthly_cost' => $position['monthly_cost'],
            'runway_months' => $position['runway_months'],
            'receivables' => [
                'outstanding_taxed_high_confidence' => $position['outstanding_taxed'],
                'outstanding_taxed_low_confidence' => $position['low_confidence_taxed'],
                'overdue_taxed' => $position['overdue_taxed'],
            ],
            'forecast' => [
                'min_balance_90d' => $position['forecast_min_90d'],
                'year_end_balance' => $forecast->yearEndBalance,
                'min_balance' => $forecast->minBalance,
                'min_balance_month' => $forecast->minBalanceMonth,
                'rows' => array_map(fn (array $row): array => array_diff_key($row, ['items' => true]), $forecast->rows),
                'method' => $forecast->assumptions['method'] ?? null,
            ],
            'previous_snapshot' => $previous === null ? null : [
                'as_of' => $previous->as_of->toDateString(),
                'year_end_balance' => $previous->year_end_balance,
                'year_end_change' => $forecast->yearEndBalance - $previous->year_end_balance,
            ],
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
