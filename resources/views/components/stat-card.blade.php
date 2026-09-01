{{--
    Overview stat card for dashboard/summary surfaces.

    Usage:
        <x-stat-card label="Total payments" value="6" hint="All time" />

    @props:
        label  small uppercase caption (required)
        value  the metric value (required; rendered as-is)
        hint   optional supporting line under the value
--}}
@props([
    'label',
    'value',
    'hint' => null,
])

<div class="card border border-custom h-100" {{ $attributes }}>
    <div class="card-body p-4">
        <p class="text-secondary-custom small fw-medium mb-2 text-uppercase">{{ $label }}</p>
        <p class="h2 fw-bold mb-0" aria-label="{{ $label }}: {{ $value }}">{{ $value }}</p>
        @if ($hint !== null)
            <p class="text-secondary-custom small mt-2 mb-0">{{ $hint }}</p>
        @endif
    </div>
</div>