<?php

namespace App\Filament\Support;

use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\TextColumn;

/**
 * NT$ formatting shared by every money column / entry / input. Amounts are whole TWD stored as integers.
 */
class Money
{
    public const string CURRENCY = 'TWD';

    /**
     * The `en` locale renders TWD as `NT$1,234,567`; `zh_TW` would render an ambiguous `$`.
     */
    public const string LOCALE = 'en';

    public static function column(TextColumn $column): TextColumn
    {
        return $column->money(self::CURRENCY, locale: self::LOCALE, decimalPlaces: 0)->alignEnd();
    }

    public static function entry(TextEntry $entry): TextEntry
    {
        return $entry->money(self::CURRENCY, locale: self::LOCALE, decimalPlaces: 0);
    }

    public static function input(TextInput $input): TextInput
    {
        return $input->integer()->prefix('NT$');
    }

    public static function format(int|float|null $amount): ?string
    {
        if ($amount === null) {
            return null;
        }

        return 'NT$'.number_format($amount);
    }
}
