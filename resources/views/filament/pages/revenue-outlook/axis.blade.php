{{-- Month labels under a chart whose plot ends at $bottom, and the bracket over the months that rely on the renewal assumption. --}}
@foreach ($months as $index => $month)
    @php $number = (int) substr($month['month'], 5); @endphp
    <text class="ro-tick" x="{{ $centerX($index) }}" y="{{ $bottom + 17 }}" text-anchor="middle">{{ $number }}月</text>
    @if ($index === 0 || $number === 1)
        <text class="ro-tick" x="{{ $centerX($index) }}" y="{{ $bottom + 31 }}" text-anchor="middle">{{ substr($month['month'], 0, 4) }}</text>
    @endif
@endforeach

@if ($assumedIndex !== null)
    @php
        $bracketFrom = round($left + $band * $assumedIndex + 5, 1);
        $bracketTo = $width - $right - 5;
    @endphp
    <path d="M{{ $bracketFrom }} {{ $bottom + 38 }} v5 H{{ $bracketTo }} v-5" fill="none" stroke="currentColor" stroke-width="1" opacity="0.45" />
    <text class="ro-tick" x="{{ ($bracketFrom + $bracketTo) / 2 }}" y="{{ $bottom + 57 }}" text-anchor="middle">經常性收入是假設維運合約續約</text>
@endif
