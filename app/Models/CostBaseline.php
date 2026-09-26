<?php

namespace App\Models;

use App\Models\Concerns\HasSource;
use Database\Factories\CostBaselineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Versioned recurring monthly cost; forecasts use the latest effective one.
 */
#[Fillable(['effective_from', 'monthly_cost', 'breakdown'])]
class CostBaseline extends Model
{
    /** @use HasFactory<CostBaselineFactory> */
    use HasFactory, HasSource;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'monthly_cost' => 'integer',
            'breakdown' => 'array',
        ];
    }
}
