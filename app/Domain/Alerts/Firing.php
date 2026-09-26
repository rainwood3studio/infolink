<?php

namespace App\Domain\Alerts;

use App\Enums\InsightKind;
use App\Enums\InsightSeverity;

/**
 * One thing a rule found wrong. The evaluator renders the rule's `title_template` / `fingerprint_template` with
 * `vars` (`{name}` placeholders) unless `title` / `fingerprint` are given explicitly, and raises it as an insight.
 */
final readonly class Firing
{
    /**
     * @param  array<string, string|int|float>  $vars  Template variables.
     * @param  string  $body  Markdown with the numbers and a suggested action.
     * @param  array<string, mixed>  $evidence
     * @param  InsightSeverity|null  $severity  Null means the rule's own severity.
     * @param  string|null  $fingerprint  Overrides the rendered fingerprint template (for side findings of a rule).
     * @param  string|null  $title  Overrides the rendered title template.
     */
    public function __construct(
        public array $vars,
        public string $body,
        public array $evidence = [],
        public ?InsightSeverity $severity = null,
        public InsightKind $kind = InsightKind::Risk,
        public ?string $fingerprint = null,
        public ?string $title = null,
    ) {}
}
