<?php

namespace App\Domain\Notify;

use App\Enums\InsightSeverity;
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

    public function reportText(Report $report): string
    {
        return $this->compose($this->reportHeadline($report), $this->reportSummary($report), $this->reportUrl($report));
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
     * Headline, optional body and link, capped at {@see MAX_MESSAGE_LENGTH}; only the body is shortened.
     */
    protected function compose(string $headline, ?string $body, string $url): string
    {
        $headline = $this->truncate($headline, 200);

        if (blank($body)) {
            return $headline."\n".$url;
        }

        $budget = self::MAX_MESSAGE_LENGTH - mb_strlen($headline) - mb_strlen($url) - 4;

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
