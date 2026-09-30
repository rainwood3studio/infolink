@php
    use App\Filament\Pages\ServerDisks;

    $percent = (float) $getState();
    $color = match (true) {
        $percent >= ServerDisks::DANGER_PERCENT => 'var(--danger-500)',
        $percent >= ServerDisks::WARNING_PERCENT => 'var(--warning-500)',
        default => 'var(--success-500)',
    };
@endphp

<div style="display: flex; align-items: center; gap: 0.625rem; min-width: 12rem; padding: 0 0.75rem;">
    <div
        role="meter"
        aria-valuenow="{{ $percent }}"
        aria-valuemin="0"
        aria-valuemax="100"
        style="flex: 1; height: 0.5rem; border-radius: 9999px; background: color-mix(in oklab, var(--gray-400) 25%, transparent); overflow: hidden;"
    >
        <div style="width: {{ min(100, max(0, $percent)) }}%; height: 100%; border-radius: 9999px; background: {{ $color }};"></div>
    </div>
    <span style="min-width: 3.25rem; text-align: right; font-variant-numeric: tabular-nums; font-size: 0.875rem; {{ $percent >= ServerDisks::WARNING_PERCENT ? 'font-weight: 600;' : '' }}">
        {{ number_format($percent, 1) }}%
    </span>
</div>
