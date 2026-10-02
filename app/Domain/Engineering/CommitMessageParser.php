<?php

namespace App\Domain\Engineering;

use App\Enums\CommitType;
use Illuminate\Support\Str;

/**
 * Extracts the conventional-commit type/scope, Redmine issue references (`#2881`) and the AI-assisted marker
 * (a `Co-Authored-By: Claude` trailer) from a commit message or PR title.
 */
class CommitMessageParser
{
    /** Redmine ids are 3+ digits; shorter `#n` references are almost always GitHub PR numbers. */
    public const int MIN_ISSUE_ID = 100;

    /**
     * @return array{subject: string, type: CommitType, scope: ?string, redmine_issue_ids: list<int>, is_ai_assisted: bool}
     */
    public function parse(string $message): array
    {
        $subject = trim(Str::before(str_replace("\r\n", "\n", $message), "\n"));
        $type = CommitType::Other;
        $scope = null;

        if (preg_match('/^([a-zA-Z]+)(?:\(([^)]{1,64})\))?!?\s*[:：]/u', $subject, $matches) === 1) {
            $type = CommitType::fromPrefix($matches[1]);
            $scope = ($matches[2] ?? '') !== '' ? $matches[2] : null;
        } elseif (preg_match('/^(add|fix|fixed|refine|update)\b/i', $subject, $matches) === 1) {
            $type = CommitType::fromPrefix($matches[1]);
        }

        return [
            'subject' => Str::limit($subject, 497),
            'type' => $type,
            'scope' => $scope,
            'redmine_issue_ids' => $this->issueIds($message),
            'is_ai_assisted' => preg_match('/^Co-Authored-By:\s*Claude\b/mi', $message) === 1,
        ];
    }

    /**
     * @return list<int>
     */
    public function issueIds(string $text): array
    {
        preg_match_all('/(?<![\w\/&])#(\d{3,6})\b/u', $text, $matches);

        return collect($matches[1])
            ->map(fn (string $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id >= self::MIN_ISSUE_ID)
            ->unique()
            ->values()
            ->all();
    }
}
