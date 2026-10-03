<?php

namespace App\Mcp\Tools;

use App\Domain\Reports\ReportService;
use App\Enums\ReportType;
use App\Mcp\Tools\Concerns\WriteToolHelpers;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('save_report')]
#[Description('Save a narrative report (Markdown) with the metric values it cites. Periodic types (daily_brief, dev_review, weekly_redmine, weekly_company, monthly_finance) are one per period: period_start is normalised (weekly → Monday, monthly → 1st) and saving again for the same type + period overwrites the report. adhoc reports are upserted by external_key when given, otherwise always new. notify=true asks the app to push a summary (the app decides channel and dedup).')]
class SaveReport extends WriteTool
{
    use WriteToolHelpers;

    public function handle(Request $request, ReportService $reports): Response
    {
        if ($denied = $this->forbidden($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'type' => ['required', Rule::in(self::enumValues(ReportType::class))],
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'metrics_snapshot' => ['nullable', 'array'],
            'vault_ref' => ['nullable', 'string', 'max:255'],
            'notify' => ['nullable', 'boolean'],
            'external_key' => ['nullable', 'string', 'max:255'],
        ], [
            'type.required' => 'Pass `type`: one of '.implode(', ', self::enumValues(ReportType::class)).'.',
            'type.in' => 'type must be one of: '.implode(', ', self::enumValues(ReportType::class)).'.',
            'period_start.required' => 'Pass `period_start` (YYYY-MM-DD): the day, the Monday of the week, or the 1st of the month the report covers.',
            'body.required' => 'Pass the report `body` as Markdown.',
            'metrics_snapshot.array' => 'metrics_snapshot must be a JSON object of the metric values the report cites, e.g. {"cash.balance": 1072776}.',
        ]);

        $externalKey = $validated['external_key'] ?? null;
        unset($validated['external_key']);

        $report = $reports->save([...$validated, 'notify' => (bool) ($validated['notify'] ?? false)], self::SOURCE, $externalKey);

        return Response::json([
            'result' => $report->wasRecentlyCreated ? 'created' : 'updated',
            'id' => $report->id,
            'type' => $report->type->value,
            'period_start' => $report->period_start->toDateString(),
            'period_end' => $report->period_end?->toDateString(),
            'external_key' => $report->external_key,
            'notify' => $report->notify,
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->enum(self::enumValues(ReportType::class))->required(),
            'period_start' => $schema->string()->format('date')->description('YYYY-MM-DD; normalised to Monday (weekly) or the 1st (monthly).')->required(),
            'period_end' => $schema->string()->format('date')->description('Defaults to the end of the day/week/month.'),
            'title' => $schema->string()->required(),
            'body' => $schema->string()->description('Full report in Markdown.')->required(),
            'metrics_snapshot' => $schema->object()->description('Metric values cited, e.g. {"cash.balance": 1072776, "delivery.verifying.others": 58}.'),
            'vault_ref' => $schema->string()->description('Path of the matching vault note.'),
            'notify' => $schema->boolean()->description('Push a summary notification (the app handles delivery).')->default(false),
            'external_key' => $schema->string()->description('adhoc only: idempotency key; ignored for periodic types.'),
        ];
    }
}
