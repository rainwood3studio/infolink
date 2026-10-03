@php
    use App\Filament\Pages\ProjectPnl;
    use App\Filament\Resources\GithubRepos\GithubRepoResource;
@endphp

<x-filament::section compact>
    <x-slot name="heading">沒對應的 repo</x-slot>
    <x-slot name="description">這些 repo 這段期間有 commit，但沒指定專案或業務機會，所以上面的數字少算了它們。同一個 repo 的不同分支屬於不同專案時，用「分支對應」。</x-slot>
    <x-slot name="afterHeader">
        <a href="{{ GithubRepoResource::getUrl('index') }}" class="pp-link" style="font-size: 0.875rem;">到 GitHub repo 設定對應 →</a>
    </x-slot>

    <div style="overflow-x: auto;">
        <table class="pp-table">
            <thead>
                <tr>
                    <th scope="col">Repo</th>
                    <th scope="col">投入人天</th>
                    <th scope="col">誰（人天）</th>
                    <th scope="col" class="pp-end">commit</th>
                    <th scope="col">分支（commit 數）</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($pnl['unmapped'] as $repo)
                    <tr wire:key="pp-repo-{{ $repo['repo_id'] }}">
                        <td class="pp-wrap"><a href="{{ $repo['url'] }}" target="_blank" rel="noopener" class="pp-link" style="font-weight: 600;">{{ $repo['full_name'] }}</a></td>
                        <td class="pp-num">
                            <span style="font-weight: 600;">{{ ProjectPnl::days($repo['person_days']) }}</span>
                            <span class="pp-sub">{{ ProjectPnl::percent($repo['share']) }}</span>
                        </td>
                        <td class="pp-wrap pp-num">{{ ProjectPnl::peopleLine($repo['by_person']) }}</td>
                        <td class="pp-end pp-num">{{ number_format($repo['commits']) }}</td>
                        <td class="pp-wrap pp-num">
                            @foreach ($repo['branches'] as $branch => $count)
                                <span style="white-space: nowrap;">{{ $branch === '' ? '（未知分支）' : $branch }} <span class="pp-muted">{{ $count }}</span></span>@if (! $loop->last)<span class="pp-muted">、</span>@endif
                            @endforeach
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-filament::section>
