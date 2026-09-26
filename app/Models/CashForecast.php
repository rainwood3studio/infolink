<?php

namespace App\Models;

use App\Models\Concerns\HasSource;
use Database\Factories\CashForecastFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An immutable forecast snapshot; every recalculation stores a new row so forecasts can be compared over time.
 */
#[Fillable(['as_of', 'opening_balance', 'rows', 'assumptions', 'min_balance', 'min_balance_month', 'year_end_balance'])]
class CashForecast extends Model
{
    /** @use HasFactory<CashForecastFactory> */
    use HasFactory, HasSource;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'as_of' => 'date',
            'opening_balance' => 'integer',
            'rows' => 'array',
            'assumptions' => 'array',
            'min_balance' => 'integer',
            'year_end_balance' => 'integer',
        ];
    }
}
