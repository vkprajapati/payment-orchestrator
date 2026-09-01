{{--
    Empty state for tables/lists that have no rows.

    Usage:
        <x-empty-state title="No payments yet" message="Create your first payment to see it here.">
            <a href="#" class="btn btn-primary">Create payment</a>
        </x-empty-state>

    @props:
        title    heading text (required)
        message  optional supporting paragraph
        icon     optional inline SVG / emoji / markup rendered above the title
--}}
@props([
    'title',
    'message' => null,
    'icon' => null,
])

<div class="text-center py-5">
    @if ($icon !== null)
        <div class="mb-3 text-secondary-custom" aria-hidden="true">{{ $icon }}</div>
    @endif
    <h2 class="h5 fw-bold mb-2">{{ $title }}</h2>
    @if ($message !== null)
        <p class="text-secondary-custom mb-4 mx-auto" style="max-width: 420px;">{{ $message }}</p>
    @endif
    @if (trim((string) $slot) !== '')
        <div>{{ $slot }}</div>
    @endif
</div>