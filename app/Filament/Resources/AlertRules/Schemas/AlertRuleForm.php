<?php

namespace App\Filament\Resources\AlertRules\Schemas;

use App\Domain\Alerts\RuleEvaluator;
use App\Enums\Category;
use App\Enums\InsightSeverity;
use App\Enums\NotificationChannel;
use App\Models\MetricDefinition;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The key is fixed after creation (the seeder matches on it). Changing the fingerprint template changes which open
 * insights the rule auto-resolves, so it is editable but warned about.
 */
class AlertRuleForm
{
    public const array OPERATORS = ['<' => '<', '<=' => '≤', '>' => '>', '>=' => '≥'];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('規則')
                    ->schema([
                        TextInput::make('key')
                            ->label('代碼')
                            ->required()
                            ->maxLength(255)
                            ->regex('/^[a-z0-9_.-]+$/')
                            ->unique(ignoreRecord: true)
                            ->disabledOn('edit'),
                        TextInput::make('name')
                            ->label('名稱')
                            ->required()
                            ->maxLength(255),
                        Toggle::make('is_active')
                            ->label('啟用')
                            ->default(true)
                            ->inline(false),
                        Select::make('severity')
                            ->label('嚴重度')
                            ->options(InsightSeverity::class)
                            ->default(InsightSeverity::Warning)
                            ->required(),
                        Select::make('category')
                            ->label('分類')
                            ->options(Category::class)
                            ->default(Category::Company)
                            ->required(),
                        CheckboxList::make('notify_channels')
                            ->label('通知管道')
                            ->options(NotificationChannel::class)
                            ->columns(2),
                        Textarea::make('description')
                            ->label('說明')
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
                    ->columns(3),
                Section::make('條件')
                    ->description('指標規則：用指標最新一期的值和門檻比較（嚴重門檻用同一個比較方向）。規則類別：門檻的意義見各規則說明（例如天數、筆數）。')
                    ->schema([
                        Select::make('metric_key')
                            ->label('指標')
                            ->options(fn (): array => MetricDefinition::query()->orderBy('sort')->orderBy('key')->get()
                                ->mapWithKeys(fn (MetricDefinition $definition): array => [$definition->key => "{$definition->name}（{$definition->key}）"])
                                ->all())
                            ->searchable()
                            ->requiredWithout('query_class'),
                        Select::make('query_class')
                            ->label('規則類別')
                            ->options(RuleEvaluator::queryRuleOptions())
                            ->helperText('選了規則類別就不看「指標」')
                            ->requiredWithout('metric_key'),
                        Select::make('operator')
                            ->label('比較')
                            ->options(self::OPERATORS),
                        TextInput::make('threshold')
                            ->label('門檻')
                            ->numeric(),
                        TextInput::make('critical_threshold')
                            ->label('嚴重門檻')
                            ->numeric(),
                        KeyValue::make('params')
                            ->label('參數')
                            ->keyLabel('參數')
                            ->valueLabel('值')
                            ->helperText('例如 dimension_prefix=project:、increase_threshold=10、days=30、suggestion=建議動作')
                            ->columnSpanFull(),
                    ])
                    ->columns(3),
                Section::make('文字範本')
                    ->schema([
                        TextInput::make('title_template')
                            ->label('標題範本')
                            ->required()
                            ->maxLength(255)
                            ->helperText('用 {變數} 帶入數字，例如 {label} {amount} 萬已逾期 {days} 天'),
                        TextInput::make('fingerprint_template')
                            ->label('fingerprint 範本')
                            ->required()
                            ->maxLength(255)
                            ->helperText('第一個 { 之前的部分決定自動結案的範圍；修改後，舊 fingerprint 的注意事項不會再被自動結案'),
                    ])
                    ->columns(2),
            ]);
    }
}
