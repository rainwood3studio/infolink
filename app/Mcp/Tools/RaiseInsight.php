<?php

namespace App\Mcp\Tools;

use App\Domain\Insights\InsightService;
use App\Enums\Category;
use App\Enums\InsightKind;
use App\Enums\InsightSeverity;
use App\Mcp\Tools\Concerns\WriteToolHelpers;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('raise_insight')]
#[Description('Create or refresh an insight (something to KNOW: risk, anomaly, reminder, opportunity, observation). Deduplicated by `fingerprint` while unresolved: raising the same fingerprint again updates the content and last_seen_at instead of creating a new one; escalating severity reopens it for notification. Fingerprint format `<category>-<subject>:<identifier>`, e.g. receivable-overdue:長照-期中款, delivery-stalled:tcsb-5f-b2c, cash-low:2026-11. Check list_insights first. severity=critical is pushed as a notification by the app.')]
class RaiseInsight extends WriteTool
{
    use WriteToolHelpers;

    public const string FINGERPRINT_PATTERN = '/^[a-z0-9]+(?:[-_][a-z0-9]+)*:\S.*$/u';

    public function handle(Request $request, InsightService $insights): Response
    {
        if ($denied = $this->forbidden($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'fingerprint' => ['required', 'string', 'max:255', 'regex:'.self::FINGERPRINT_PATTERN],
            'kind' => ['required', Rule::in(self::enumValues(InsightKind::class))],
            'severity' => ['required', Rule::in(self::enumValues(InsightSeverity::class))],
            'category' => ['required', Rule::in(self::enumValues(Category::class))],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'evidence' => ['nullable', 'array'],
            'expires_at' => ['nullable', 'date'],
            'vault_ref' => ['nullable', 'string', 'max:255'],
        ], [
            'fingerprint.required' => 'Pass a `fingerprint` `<category>-<subject>:<identifier>`, e.g. receivable-overdue:長照-期中款.',
            'fingerprint.regex' => 'fingerprint must look like `<category>-<subject>:<identifier>` — lowercase ASCII prefix, a colon, then the identifier (e.g. receivable-overdue:長照-期中款, delivery-stalled:tcsb-5f-b2c, cash-low:2026-11).',
            'kind.in' => 'kind must be one of: '.implode(', ', self::enumValues(InsightKind::class)).'.',
            'severity.in' => 'severity must be one of: '.implode(', ', self::enumValues(InsightSeverity::class)).'.',
            'category.in' => 'category must be one of: '.implode(', ', self::enumValues(Category::class)).'.',
            'title.required' => 'Pass a one-line `title` with the key number, e.g. 長照期中款 47.25 萬逾期 3 天.',
            'evidence.array' => 'evidence must be a JSON object (metric values, issue ids, transaction ids…).',
        ]);

        try {
            $insight = $insights->raise($validated, self::SOURCE);
        } catch (InvalidArgumentException $exception) {
            return Response::error($exception->getMessage());
        }

        return Response::json([
            'result' => $insight->wasRecentlyCreated ? 'created' : 'updated',
            'id' => $insight->id,
            'fingerprint' => $insight->fingerprint,
            'status' => $insight->status->value,
            'severity' => $insight->severity->value,
            'first_seen_at' => $insight->first_seen_at?->toIso8601String(),
            'last_seen_at' => $insight->last_seen_at?->toIso8601String(),
            'hint' => 'If someone must act on this, create_action_item with insight_id.',
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'fingerprint' => $schema->string()->description('Dedup key `<category>-<subject>:<identifier>`, e.g. receivable-overdue:長照-期中款, delivery-offflow:tcsb-5f-b2c, cash-low:2026-11.')->required(),
            'kind' => $schema->string()->enum(self::enumValues(InsightKind::class))->required(),
            'severity' => $schema->string()->enum(self::enumValues(InsightSeverity::class))->description('critical is pushed as a notification; use sparingly.')->required(),
            'category' => $schema->string()->enum(self::enumValues(Category::class))->required(),
            'title' => $schema->string()->description('One line with the key number, e.g. 長照期中款 47.25 萬逾期 3 天.')->required(),
            'body' => $schema->string()->description('Markdown: evidence, numbers, suggested action.'),
            'evidence' => $schema->object()->description('Structured references: metric values, Redmine issue ids, transaction/receivable ids.'),
            'expires_at' => $schema->string()->format('date-time')->description('Auto-resolve after this time (for "this week" reminders).'),
            'vault_ref' => $schema->string()->description('Related vault note path.'),
        ];
    }
}
