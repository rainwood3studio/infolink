<?php

namespace App\Mcp\Tools;

use App\Enums\ReportType;
use App\Mcp\Tools\Concerns\PresentsRecords;
use App\Models\Report;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get_report')]
#[Description(<<<'TEXT'
Fetch one saved report in full: the Markdown `body` and the `metrics_snapshot` (the metric values the report cited when it was written, as saved by save_report).
Identify it either by `id` (from list_reports), or by `type` + `period_start` (YYYY-MM-DD; weekly reports start on Monday, monthly on the 1st). If several reports share a type and period (possible for adhoc), the newest is returned.
TEXT)]
class GetReport extends ReadTool
{
    use PresentsRecords;

    public function handle(Request $request): Response
    {
        if ($denied = $this->forbidden($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'id' => ['nullable', 'integer', 'required_without_all:type,period_start'],
            'type' => ['nullable', Rule::enum(ReportType::class), 'required_without:id', 'required_with:period_start'],
            'period_start' => ['nullable', 'date', 'required_without:id', 'required_with:type'],
        ], [
            'id.required_without_all' => 'Pass either `id`, or `type` and `period_start`.',
        ]);

        $report = isset($validated['id'])
            ? Report::query()->find($validated['id'])
            : Report::query()
                ->where('type', $validated['type'])
                ->whereDate('period_start', $validated['period_start'])
                ->latest('id')
                ->first();

        if ($report === null) {
            return Response::error('Report not found.');
        }

        return Response::json([
            'id' => $report->id,
            'type' => $report->type->value,
            'title' => $report->title,
            'period_start' => static::date($report->period_start),
            'period_end' => static::date($report->period_end),
            'created_at' => static::dateTime($report->created_at),
            'updated_at' => static::dateTime($report->updated_at),
            'source' => $report->source->value,
            'actor' => $report->actor,
            'notified_at' => static::dateTime($report->notified_at),
            'vault_ref' => $report->vault_ref,
            'body' => $report->body,
            'metrics_snapshot' => $report->metrics_snapshot,
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()
                ->description('Report id. Alternatively pass `type` + `period_start`.'),
            'type' => $schema->string()
                ->enum(array_column(ReportType::cases(), 'value'))
                ->description('Report type; use together with `period_start`.'),
            'period_start' => $schema->string()->format('date')
                ->description('Period start date (YYYY-MM-DD); use together with `type`.'),
        ];
    }
}
