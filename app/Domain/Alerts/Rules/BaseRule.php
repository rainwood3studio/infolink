<?php

namespace App\Domain\Alerts\Rules;

use App\Models\AlertRule;

/**
 * Shared helpers: the default owned fingerprint (derived from the template), params, thresholds and number formatting.
 */
abstract class BaseRule implements Rule
{
    /**
     * `receivable-overdue:{id}` owns the prefix `receivable-overdue:*`; a template without placeholders owns exactly itself.
     *
     * @return list<string>
     */
    public function ownedFingerprints(AlertRule $rule): array
    {
        return [self::ownedPattern($rule->fingerprint_template)];
    }

    public static function ownedPattern(string $template): string
    {
        $placeholder = strpos($template, '{');

        return $placeholder === false ? $template : substr($template, 0, $placeholder).'*';
    }

    protected function param(AlertRule $rule, string $key, mixed $default = null): mixed
    {
        $value = $rule->params[$key] ?? null;

        return blank($value) ? $default : $value;
    }

    /**
     * Params edited in the admin arrive as strings ("false", "0"), so booleans are parsed leniently.
     */
    protected function boolParam(AlertRule $rule, string $key, bool $default): bool
    {
        $value = $this->param($rule, $key);

        return $value === null ? $default : filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    protected function threshold(AlertRule $rule, float $default): float
    {
        return $rule->threshold !== null ? (float) $rule->threshold : $default;
    }

    protected function criticalThreshold(AlertRule $rule): ?float
    {
        return $rule->critical_threshold !== null ? (float) $rule->critical_threshold : null;
    }

    /**
     * Strict comparison of `$value` against `$threshold` with the rule's operator (`<`, `<=`, `>`, `>=`).
     */
    protected static function breaches(string $operator, float $value, ?float $threshold): bool
    {
        if ($threshold === null) {
            return false;
        }

        return match ($operator) {
            '<' => $value < $threshold,
            '<=' => $value <= $threshold,
            '>' => $value > $threshold,
            '>=' => $value >= $threshold,
            default => false,
        };
    }

    /**
     * NT$ in 萬 with 2 decimals: 472,500 → "47.25".
     */
    public static function wan(int|float $amount): string
    {
        return number_format($amount / 10_000, 2);
    }

    /**
     * Thousands separators, up to 2 decimals, no trailing zeros: 1234.5 → "1,234.5".
     */
    public static function number(int|float $value): string
    {
        $formatted = number_format($value, 2);

        return str_contains($formatted, '.') ? rtrim(rtrim($formatted, '0'), '.') : $formatted;
    }

    /**
     * "+12" / "-3" / "0".
     */
    public static function signed(int|float $value): string
    {
        return ($value > 0 ? '+' : '').self::number($value);
    }
}
