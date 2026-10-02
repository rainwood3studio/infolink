<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Conventional-commit type parsed from a commit subject (`feat(pos): …`). Subjects without a prefix are Other.
 */
enum CommitType: string implements HasColor, HasLabel
{
    case Feat = 'feat';
    case Fix = 'fix';
    case Refactor = 'refactor';
    case Perf = 'perf';
    case Test = 'test';
    case Docs = 'docs';
    case Chore = 'chore';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Feat => '新功能',
            self::Fix => '修正',
            self::Refactor => '重構',
            self::Perf => '效能',
            self::Test => '測試',
            self::Docs => '文件',
            self::Chore => '雜項',
            self::Other => '其他',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Feat => 'success',
            self::Fix => 'danger',
            self::Refactor, self::Perf => 'info',
            self::Test => 'warning',
            self::Docs, self::Chore, self::Other => 'gray',
        };
    }

    /**
     * Map a raw prefix (including common variants like `fixed`, `feature`, `build`, `ci`, `style`) to a type.
     */
    public static function fromPrefix(string $prefix): self
    {
        return match (strtolower($prefix)) {
            'feat', 'feature', 'add' => self::Feat,
            'fix', 'fixed', 'fixes', 'bugfix', 'hotfix', 'revert' => self::Fix,
            'refactor', 'refine' => self::Refactor,
            'perf' => self::Perf,
            'test', 'tests' => self::Test,
            'docs', 'doc' => self::Docs,
            'chore', 'build', 'ci', 'style', 'deps' => self::Chore,
            default => self::Other,
        };
    }
}
