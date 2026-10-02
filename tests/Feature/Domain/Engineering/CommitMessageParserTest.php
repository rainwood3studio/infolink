<?php

use App\Domain\Engineering\CommitMessageParser;
use App\Enums\CommitType;

test('parses the conventional type, scope, Redmine issues and the Claude trailer', function () {
    $parsed = (new CommitMessageParser)->parse("feat(pos): 交易作廢也要選作廢原因 (#2881)\n\n見 #2876、#12。\n\nCo-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>");

    expect($parsed)->toMatchArray([
        'subject' => 'feat(pos): 交易作廢也要選作廢原因 (#2881)',
        'type' => CommitType::Feat,
        'scope' => 'pos',
        'redmine_issue_ids' => [2881, 2876],
        'is_ai_assisted' => true,
    ]);
});

test('maps prefix variants and leaves free-form subjects as other', function (string $subject, CommitType $type) {
    expect((new CommitMessageParser)->parse($subject)['type'])->toBe($type);
})->with([
    ['fixed(media): close status', CommitType::Fix],
    ['fix: 開立發票明細缺 key 防呆', CommitType::Fix],
    ['refine dashboard', CommitType::Refactor],
    ['Merge branch \'develop\'', CommitType::Other],
    ['Edwardyi', CommitType::Other],
]);
