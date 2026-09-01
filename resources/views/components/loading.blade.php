{{--
    Inline loading indicator for asynchronous operations.

    Usage:
        <button class="btn btn-primary" type="submit" disabled>
            <x-loading label="Creating…" />
        </button>

    @props:
        label  optional accessible label shown alongside the spinner
--}}
@props([
    'label' => 'Loading…',
])

<span class="spinner-border spinner-border-sm me-2" aria-hidden="true" role="status">
    <span class="visually-hidden">{{ $label }}</span>
</span>
<span>{{ $label }}</span>