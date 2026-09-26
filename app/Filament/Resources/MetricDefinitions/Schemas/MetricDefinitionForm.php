<?php

namespace App\Filament\Resources\MetricDefinitions\Schemas;

use App\Enums\Category;
use App\Enums\MetricDirection;
use App\Enums\MetricUnit;
use App\Enums\PeriodType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Key, unit, period and calculator are structural (code and stored values depend on them), so they are
 * fixed after creation; name, description, thresholds and pinning stay editable.
 */
class MetricDefinitionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('定義')
                    ->schema([
                        TextInput::make('key')
                            ->label('代碼')
                            ->required()
                            ->maxLength(255)
                            ->regex('/^[a-z0-9_.-]+$/')
                            ->helperText('小寫英數、底線、連字號與點，例如 saas.trial_customers')
                            ->unique(ignoreRecord: true)
                            ->disabledOn('edit'),
                        TextInput::make('name')
                            ->label('名稱')
                            ->required()
                            ->maxLength(255),
                        Select::make('category')
                            ->label('分類')
                            ->options(Category::class)
                            ->required(),
                        Select::make('unit')
                            ->label('單位')
                            ->options(MetricUnit::class)
                            ->required()
                            ->disabledOn('edit'),
                        Select::make('period_type')
                            ->label('週期')
                            ->options(PeriodType::class)
                            ->required()
                            ->disabledOn('edit'),
                        TextEntry::make('calculator')
                            ->label('計算器')
                            ->placeholder('無（外部寫入／手動補值）')
                            ->hiddenOn('create'),
                        Textarea::make('description')
                            ->label('定義說明')
                            ->helperText('Claude 會讀這段文字，請精確描述指標的計算方式與範圍')
                            ->rows(4)
                            ->columnSpanFull(),
                    ])
                    ->columns(3),
                Section::make('門檻與顯示')
                    ->schema([
                        Select::make('better')
                            ->label('方向')
                            ->options(MetricDirection::class)
                            ->default(MetricDirection::None)
                            ->required(),
                        TextInput::make('target')
                            ->label('目標值')
                            ->numeric(),
                        TextInput::make('warn_threshold')
                            ->label('警告門檻')
                            ->numeric(),
                        TextInput::make('critical_threshold')
                            ->label('嚴重門檻')
                            ->numeric(),
                        Toggle::make('is_pinned')
                            ->label('釘選到總覽')
                            ->inline(false),
                        TextInput::make('sort')
                            ->label('排序')
                            ->integer()
                            ->default(0),
                    ])
                    ->columns(3),
            ]);
    }
}
