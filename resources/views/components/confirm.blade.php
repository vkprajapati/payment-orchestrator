{{--
    Confirmation wrapper for destructive forms.

    Renders a form whose submit requires a plain-text confirmation dialog.
    The confirm() wiring lives in resources/js/app.js (data-confirm attribute).

    Usage:
        <x-confirm action="{{ route('...') }}" method="DELETE"
                   message="Revoke this API key?">
            <button class="btn btn-outline-danger btn-sm">Revoke</button>
        </x-confirm>

    @props:
        action     form action URL (required)
        method     HTTP verb (default POST; DELETE/PUT supported)
        message    confirmation text shown to the user (required)
        id         optional form id
--}}
@props([
    'action',
    'method' => 'POST',
    'message',
    'id' => null,
])

@php
    $spoofedMethod = in_array(strtoupper($method), ['DELETE', 'PUT', 'PATCH'], true)
        ? strtoupper($method)
        : null;
    $httpMethod = $spoofedMethod !== null ? 'POST' : strtoupper($method);
@endphp

<form method="{{ strtolower($httpMethod) }}"
      action="{{ $action }}"
      @if ($id !== null) id="{{ $id }}" @endif
      data-confirm="{{ $message }}">
    @csrf
    @if ($spoofedMethod !== null)
        @method($spoofedMethod)
    @endif
    {{ $slot }}
</form>