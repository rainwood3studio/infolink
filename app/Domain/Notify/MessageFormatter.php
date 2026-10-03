<?php

namespace App\Domain\Notify;

use App\Enums\InsightSeverity;
use App\Enums\ReportType;
use App\Models\Insight;
use App\Models\Report;

/**
 * Turns insights and reports into short plain-text messages (Traditional Chinese) for LINE and the bell.
 */
class MessageFormatter
{
    /**
     * Upper bound for a whole LINE message built here (well under LINE's own 5000).
     */
    public const int MAX_MESSAGE_LENGTH = 1000;

    public const int REPORT_SUMMARY_LINES = 5;

    /**
     * Upper bound for a one-pager pushed in full (the weekly review), still under LINE's 5000.
     */
    public const int ONE_PAGER_MAX_LENGTH = 3500;

    public const int INSIGHT_EXCERPT_LINES = 3;

    public const int INSIGHT_EXCERPT_LENGTH = 300;

    /**
     * e.g. 「🔴 嚴重：長照期中款 47.25 萬已逾期 3 天」.
     */
    public function insightHeadline(Insight $insight): string
    {
        return $this->severityIcon($insight->severity).' '.$insight->severity->getLabel().'：'.$insight->title;
    }

    /**
     * The first few lines of the insight body as plain text, or null when there is no body.
     */
    public function insightExcerpt(Insight $insight): ?string
    {
        $lines = array_slice($this->plainLines((string) $insight->body), 0, self::INSIGHT_EXCERPT_LINES);

        return $lines === [] ? null : $this->truncate(implode("\n", $lines), self::INSIGHT_EXCERPT_LENGTH);
    }

    public function insightText(Insight $insight): string
    {
        return $this->compose($this->insightHeadline($insight), $this->insightExcerpt($insight), $this->insightUrl($insight));
    }

    public function reportHeadline(Report $report): string
    {
        return '📊 '.$report->title;
    }

    /**
     * The report's first heading / bullet lines as plain text (falls back to its first lines).
     */
    public function reportSummary(Report $report): ?string
    {
        $lines = $this->summaryLines((string) $report->body, self::REPORT_SUMMARY_LINES);

        return $lines === [] ? null : implode("\n", $lines);
    }

    /**
     * The LINE text of a report. A weekly review is pushed as its whole one-pager (it is read on the phone, where
     * the app itself is not reachable); every other report as a short summary.
     */
    public function reportText(Report $report): string
    {
        if ($report->type === ReportType::WeeklyCompany && ($onePager = $this->onePager($report)) !== null) {
            return $this->compose($this->reportHeadline($report), $onePager, $this->reportUrl($report), self::ONE_PAGER_MAX_LENGTH);
        }

        return $this->compose($this->reportHeadline($report), $this->reportSummary($report), $this->reportUrl($report));
    }

    /**
     * The part of the report body before its first horizontal rule (`---`), as plain text that keeps its
     * structure: headings become 「■ …」 after a blank line, list items 「• …」. Null when that part is empty.
     */
    public function onePager(Report $report): ?string
    {
        $head = preg_split('/^\h*(?:-{3,}|\*{3,}|_{3,})\h*$/mu', (string) $report->body, 2)[0] ?? '';
        $lines = [];

        foreach ($this->contentLines($head) as $line) {
            if (preg_match('/^#{1,6}\s+(.+)$/u', $line, $matches) === 1) {
                $lines[] = '';
                $lines[] = '■ '.$this->stripInline($matches[1]);
            } elseif (preg_match('/^(?:[-*+]|\d+[.)])\s+(?:\[[ xX]\]\s+)?(.+)$/u', $line, $matches) === 1) {
                $lines[] = '• '.$this->stripInline($matches[1]);
            } elseif (($plain = $this->stripInline($line)) !== '') {
                $lines[] = $plain;
            }
        }

        $text = trim(implode("\n", $lines));

        return $text === '' ? null : $text;
    }

    public function insightUrl(Insight $insight): string
    {
        return $this->adminUrl("insights/{$insight->getKey()}");
    }

    public function reportUrl(Report $report): string
    {
        return $this->adminUrl("reports/{$report->getKey()}");
    }

    public function actionItemsUrl(): string
    {
        return $this->adminUrl('action-items');
    }

    /**
     * Up to `$limit` heading / list-item lines of a Markdown document, stripped to plain text. Headings become
     * 「■ …」 and list items 「• …」. A document without either yields its first plain lines instead.
     *
     * @return list<string>
     */
    public function summaryLines(string $markdown, int $limit): array
    {
        $summary = [];

        foreach ($this->contentLines($markdown) as $line) {
            if (preg_match('/^#{1,6}\s+(.+)$/u', $line, $matches) === 1) {
                $summary[] = '■ '.$this->stripInline($matches[1]);
            } elseif (preg_match('/^(?:[-*+]|\d+[.)])\s+(?:\[[ xX]\]\s+)?(.+)$/u', $line, $matches) === 1) {
                $summary[] = '• '.$this->stripInline($matches[1]);
            }

            if (count($summary) >= $limit) {
                break;
            }
        }

        if ($summary === []) {
            $summary = array_slice($this->plainLines($markdown), 0, $limit);
        }

        return array_values(array_filter($summary, fn (string $line): bool => ! in_array($line, ['■ ', '• ', ''], true)));
    }

    /**
     * Every non-empty line of a Markdown document as plain text, without block markers.
     *
     * @return list<string>
     */
    public function plainLines(string $markdown): array
    {
        $lines = [];

        foreach ($this->contentLines($markdown) as $line) {
            $line = preg_replace('/^(?:#{1,6}|[-*+]|\d+[.)])\s+(?:\[[ xX]\]\s+)?/u', '', $line) ?? $line;
            $line = $this->stripInline($line);

            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * Cut to at most `$max` characters (not display width), ending in 「…」 when shortened.
     */
    public function truncate(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, max(0, $max - 1))).'…';
    }

    /**
     * Headline, optional body and link, capped at `$maxLength`; only the body is shortened.
     */
    protected function compose(string $headline, ?string $body, string $url, int $maxLength = self::MAX_MESSAGE_LENGTH): string
    {
        $headline = $this->truncate($headline, 200);

        if (blank($body)) {
            return $headline."\n".$url;
        }

        $budget = $maxLength - mb_strlen($headline) - mb_strlen($url) - 4;

        return $headline."\n\n".$this->truncate($body, $budget)."\n\n".$url;
    }

    /**
     * Trimmed, non-empty lines of Markdown, skipping code fences, tables, rules and quotes' markers.
     *
     * @return list<string>
     */
    protected function contentLines(string $markdown): array
    {
        $lines = [];
        $inFence = false;

        foreach (preg_split('/\R/u', $markdown) ?: [] as $line) {
            $line = trim($line);

            if (str_starts_with($line, '```') || str_starts_with($line, '~~~')) {
                $inFence = ! $inFence;

                continue;
            }

            if ($inFence || $line === '' || str_starts_with($line, '|') || preg_match('/^([-*_=])\1{2,}$/', $line) === 1) {
                continue;
            }

            $lines[] = trim(preg_replace('/^>\s*/u', '', $line) ?? $line);
        }

        return array_values(array_filter($lines, fn (string $line): bool => $line !== ''));
    }

    protected function stripInline(string $text): string
    {
        $text = preg_replace('/!\[([^\]]*)\]\([^)]*\)/u', '$1', $text) ?? $text;
        $text = preg_replace('/\[([^\]]+)\]\([^)]*\)/u', '$1', $text) ?? $text;
        $text = preg_replace('/\[\[(?:[^\]|]+\|)?([^\]]+)\]\]/u', '$1', $text) ?? $text;
        $text = preg_replace('/(\*\*|__|~~)(.+?)\1/u', '$2', $text) ?? $text;
        $text = preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/u', '$1', $text) ?? $text;
        $text = preg_replace('/(?<!\w)_(?!\s)(.+?)(?<!\s)_(?!\w)/u', '$1', $text) ?? $text;
        $text = preg_replace('/`([^`]*)`/u', '$1', $text) ?? $text;
        $text = strip_tags($text);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    protected function severityIcon(InsightSeverity $severity): string
    {
        return match ($severity) {
            InsightSeverity::Critical => '🔴',
            InsightSeverity::Warning => '🟡',
            InsightSeverity::Info => '🔵',
        };
    }

    protected function adminUrl(string $path): string
    {
        return rtrim((string) config('app.url'), '/').'/admin/'.$path;
    }
}
