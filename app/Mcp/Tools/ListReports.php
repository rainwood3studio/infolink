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

#[Name('list_reports')]
#[Description(<<<'TEXT'
List saved reports (newest period first) without their bodies: `id`, `type` (daily_brief, weekly_redmine, weekly_company, monthly_finance, adhoc), `title`, `period_start`/`period_end` (dates; weekly periods start on Monday), `excerpt` (first ~200 characters of the Markdown body as plain text) and `created_at`.
Use it to find last week's / last month's report to compare against, then fetch the full text with get_report.
`from`/`to` filter on period_start (inclusive). `limit` defaults to 10 (max 100).
TEXT)]
class ListReports extends ReadTool
{
    use PresentsRecords;

    public const int DEFAULT_LIMIT = 10;

    public const int MAX_LIMIT = 100;

    public function handle(Request $request): Response
    {
        if ($denied = $this->forbidden($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'type' => ['nullable', Rule::enum(ReportType::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
        ]);

        $query = Report::query()
            ->when($validated['type'] ?? null, fn ($query, string $type) => $query->where('type', $type))
            ->when($validated['from'] ?? null, fn ($query, string $from) => $query->whereDate('period_start', '>=', $from))
            ->when($validated['to'] ?? null, fn ($query, string $to) => $query->whereDate('period_start', '<=', $to));

        $total = (clone $query)->count();

        $reports = $query
            ->orderByDesc('period_start')
            ->orderByDesc('id')
            ->limit($validated['limit'] ?? self::DEFAULT_LIMIT)
            ->get(['id', 'type', 'title', 'period_start', 'period_end', 'body', 'created_at']);

        return Response::json([
            'total' => $total,
            'returned' => $reports->count(),
            'reports' => $reports->map(fn (Report $report): array => static::presentReportSummary($report))->all(),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()
                ->enum(array_column(ReportType::cases(), 'value'))
                ->description('Only reports of this type.'),
            'from' => $schema->string()->format('date')
                ->description('Earliest period_start (YYYY-MM-DD, inclusive).'),
            'to' => $schema->string()->format('date')
                ->description('Latest period_start (YYYY-MM-DD, inclusive).'),
            'limit' => $schema->integer()->min(1)->max(self::MAX_LIMIT)
                ->description('Maximum reports to return. Default 10.'),
        ];
    }
}
