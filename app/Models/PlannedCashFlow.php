<?php

namespace App\Models;

use App\Models\Concerns\HasSource;
use Database\Factories\PlannedCashFlowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A one-off expected cash movement that is not a receivable (e.g. a VAT payment or a bonus).
 * `amount` is signed: negative = outflow, positive = inflow.
 */
#[Fillable(['flow_on', 'amount', 'description'])]
class PlannedCashFlow extends Model
{
    /** @use HasFactory<PlannedCashFlowFactory> */
    use HasFactory, HasSource;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'flow_on' => 'date',
            'amount' => 'integer',
        ];
    }
}
