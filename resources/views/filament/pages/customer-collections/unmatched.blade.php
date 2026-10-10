@php
    use App\Filament\Pages\CustomerCollections;
    use App\Filament\Resources\Customers\CustomerResource;
@endphp

<x-filament::section compact>
    <x-slot name="heading">對不到客戶的入帳</x-slot>
    <x-slot name="description">這些戶名不是任何客戶的名稱、簡稱或銀行入帳戶名。到 <a href="{{ CustomerResource::getUrl('index') }}" class="cc-link" style="text-decoration: underline;">客戶</a> 把戶名加進「銀行入帳戶名」，下次就會對上。</x-slot>

    <table class="cc-table">
        <thead>
            <tr>
                <th>銀行戶名</th>
                <th class="cc-end">筆數</th>
                <th class="cc-end">合計（含稅）</th>
                <th class="cc-end">第一筆</th>
                <th class="cc-end">最近一筆</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($unmatched as $line)
                <tr wire:key="cc-unmatched-{{ md5($line['counterparty']) }}">
                    <td>{{ $line['counterparty'] }}</td>
                    <td class="cc-end cc-num">{{ $line['count'] }}</td>
                    <td class="cc-end cc-num">{{ CustomerCollections::money($line['total']) }}</td>
                    <td class="cc-end cc-num">{{ CustomerCollections::dateLabel($line['first_on']) }}</td>
                    <td class="cc-end cc-num">{{ CustomerCollections::dateLabel($line['last_on']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</x-filament::section>
