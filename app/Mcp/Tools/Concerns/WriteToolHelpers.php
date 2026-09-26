<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\Receivable;
use BackedEnum;

/**
 * Small formatting and lookup helpers shared by the write tools.
 */
trait WriteToolHelpers
{
    /**
     * The backing values of an enum, for `in:` rules, schema enums and error messages.
     *
     * @param  class-string<BackedEnum>  $enum
     * @return list<string>
     */
    protected static function enumValues(string $enum): array
    {
        return array_map(fn (BackedEnum $case): string => (string) $case->value, $enum::cases());
    }

    /**
     * Candidates most similar to the needle (substring matches first, then by edit distance).
     *
     * @param  iterable<string>  $candidates
     * @return list<string>
     */
    protected static function closeMatches(string $needle, iterable $candidates, int $limit = 5): array
    {
        $needle = mb_strtolower($needle);
        $scored = [];

        foreach ($candidates as $candidate) {
            $lower = mb_strtolower($candidate);
            $distance = levenshtein($needle, $lower);

            if (str_contains($lower, $needle) || str_contains($needle, $lower)) {
                $distance = min($distance, 1);
            }

            $scored[$candidate] = $distance;
        }

        asort($scored);

        $threshold = max(3, intdiv(strlen($needle), 2));

        return array_slice(array_keys(array_filter($scored, fn (int $distance): bool => $distance <= $threshold)), 0, $limit);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function presentReceivable(Receivable $receivable): array
    {
        $receivable->loadMissing(['customer', 'project']);

        return [
            'id' => $receivable->id,
            'external_key' => $receivable->external_key,
            'source' => $receivable->source->value,
            'customer' => $receivable->customer?->short_name,
            'project' => $receivable->project?->name,
            'item' => $receivable->item,
            'amount_untaxed' => $receivable->amount_untaxed,
            'tax_rate' => (float) $receivable->tax_rate,
            'amount_taxed' => $receivable->amount_taxed,
            'expected_on' => $receivable->expected_on?->toDateString(),
            'confidence' => $receivable->confidence?->value,
            'status' => $receivable->status?->value,
            'is_overdue' => $receivable->is_overdue,
            'invoiced_on' => $receivable->invoiced_on?->toDateString(),
            'received_on' => $receivable->received_on?->toDateString(),
            'is_recurring' => $receivable->is_recurring,
            'notes' => $receivable->notes,
            'vault_ref' => $receivable->vault_ref,
        ];
    }
}
