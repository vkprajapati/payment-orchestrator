{{--
    Generic alert with a conventional styling map.

    Usage:
        <x-alert type="success">Saved.</x-alert>
        <x-alert type="error" title="Something went wrong">Please retry.</x-alert>

    @props:
        type        success|error|danger|warning|info  (default: info)
        title       optional bold heading text
        dismissible  if truthy, renders a close button (JS in app.js wires it up)
--}}
@props([
    'type' => 'info',
    'title' => null,
    'dismissible' => false,
])

@php
    $styles = [
        'success' => 'alert-success',
        'error' => 'alert-danger',
        'danger' => 'alert-danger',
        'warning' => 'alert-warning',
        'info' => 'alert-info',
    ];

    $icon = [
        'success' => '✓',
        'error' => '✕',
        'danger' => '✕',
        'warning' => '!',
        'info' => 'i',
    ];
@endphp

<div class="alert {{ $styles[$type] ?? 'alert-info' }} @if ($dismissible) alert-dismissible fade show @endif"
     role="alert"
     @if ($title !== null) aria-label="{{ $title }}" @endif>
    @if ($title !== null)
        <strong>{{ $title }}</strong>
        @if (trim((string) $slot) !== '')<br>@endif
    @endif
    {{-- Icon for visual scanning (not read by screen readers; sr-only handled by label above). --}}
    <span aria-hidden="true" class="me-1 fw-bold">{{ $icon[$type] ?? 'i' }}</span>
    {{ $slot }}
    @if ($dismissible)
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    @endif
</div>