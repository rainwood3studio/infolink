<?php

namespace App\Filament\Resources\ApiTokens\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

/**
 * Issue a token, then show its plaintext exactly once in a follow-up modal.
 */
class ApiTokenActions
{
    public const string ABILITY_READ = 'read';

    public const string ABILITY_WRITE = 'write';

    /**
     * One token per caller; the name becomes the `actor` on everything the caller writes.
     *
     * @var list<string>
     */
    public const array PRESET_NAMES = ['claude-cli', 'vault-agent', 'launchd-brief'];

    public static function create(): Action
    {
        return Action::make('createToken')
            ->label('建立 Token')
            ->icon(Heroicon::OutlinedPlus)
            ->modalHeading('建立 API Token')
            ->modalSubmitActionLabel('建立')
            ->schema([
                TextInput::make('name')
                    ->label('名稱（呼叫者）')
                    ->helperText('建議：'.implode('、', self::PRESET_NAMES).'。寫入的資料會以此名稱記為寫入者。')
                    ->datalist(self::PRESET_NAMES)
                    ->required()
                    ->maxLength(100),
                CheckboxList::make('abilities')
                    ->label('權限')
                    ->options([
                        self::ABILITY_READ => 'read — 讀取',
                        self::ABILITY_WRITE => 'write — 寫入（自動包含 read）',
                    ])
                    ->helperText('勾選 write 時會一併授予 read。')
                    ->default([self::ABILITY_READ])
                    ->required(),
                DatePicker::make('expires_on')
                    ->label('到期日（選填）')
                    ->helperText('留空表示永不到期；到期日當天 23:59 失效。')
                    ->minDate(today())
                    ->native(false),
            ])
            ->action(function (array $data, Page $livewire): void {
                $expiresAt = filled($data['expires_on'] ?? null) ? Carbon::parse($data['expires_on'])->endOfDay() : null;

                $newToken = auth()->user()->createToken($data['name'], self::normalizeAbilities($data['abilities']), $expiresAt);

                $livewire->replaceMountedAction('showToken', [
                    'name' => $newToken->accessToken->name,
                    'token' => $newToken->plainTextToken,
                ]);
            });
    }

    public static function showToken(): Action
    {
        return Action::make('showToken')
            ->modalHeading(fn (array $arguments): string => 'Token「'.($arguments['name'] ?? '').'」已建立')
            ->modalDescription('完整 token 只會顯示這一次，關閉後無法再查看，請立即複製保存。')
            ->modalIcon(Heroicon::OutlinedKey)
            ->modalIconColor('warning')
            ->schema(fn (array $arguments): array => [
                TextEntry::make('token')
                    ->label('Token')
                    ->state($arguments['token'] ?? null)
                    ->fontFamily('mono')
                    ->copyable()
                    ->copyMessage('已複製 token'),
                TextEntry::make('command')
                    ->label('Claude CLI 註冊指令')
                    ->state(self::claudeCommand($arguments['token'] ?? ''))
                    ->fontFamily('mono')
                    ->copyable()
                    ->copyMessage('已複製指令'),
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('我已複製，關閉');
    }

    /**
     * `write` implies `read`; unknown abilities are dropped.
     *
     * @param  array<int, string>  $abilities
     * @return list<string>
     */
    public static function normalizeAbilities(array $abilities): array
    {
        if (in_array(self::ABILITY_WRITE, $abilities, true)) {
            $abilities[] = self::ABILITY_READ;
        }

        return array_values(array_intersect([self::ABILITY_READ, self::ABILITY_WRITE], $abilities));
    }

    public static function claudeCommand(string $token): string
    {
        return 'claude mcp add --transport http --scope user infolink '.url('/mcp/infolink')
            .' --header "Authorization: Bearer '.$token.'"';
    }
}
