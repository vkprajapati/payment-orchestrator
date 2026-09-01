{{--
    Status / role badge with a conventional styling map.

    Usage:
        <x-badge variant="active">Active</x-badge>
        <x-badge variant="revoked">Revoked</x-badge>

    @props:
        variant  active|success|suspended|revoked|expired|inactive|pending|info|warning|primary
--}}
@props([
    'variant' => 'inactive',
])

@php
    $classes = [
        'active' => 'badge-status-active',
        'success' => 'badge-status-active',
        'suspended' => 'badge-status-suspended',
        'revoked' => 'badge-status-suspended',
        'expired' => 'badge-status-suspended',
        'inactive' => 'badge-status-inactive',
        'pending' => 'badge-status-pending',
        'warning' => 'badge-status-pending',
        'info' => 'badge-status-info',
        'primary' => 'badge-role',
    ];
@endphp

<span class="badge-status {{ $classes[$variant] ?? 'badge-status-inactive' }}">
    {{ $slot }}
</span>