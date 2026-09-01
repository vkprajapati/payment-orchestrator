{{--
    Domain status badge for payment/refund lifecycle values.

    Maps the stored status string onto the shared badge variants using the
    existing domain terminology — no new status semantics are introduced.
    The label text (not just color) carries the state for accessibility.

    Usage:
        <x-status-badge status="succeeded" />

    @props:
        status  a PaymentStatus / RefundStatus string value
--}}
@props([
    'status',
])

@php
    $map = [
        'pending' => ['variant' => 'pending', 'label' => 'Pending'],
        'processing' => ['variant' => 'info', 'label' => 'Processing'],
        'succeeded' => ['variant' => 'active', 'label' => 'Succeeded'],
        'failed' => ['variant' => 'revoked', 'label' => 'Failed'],
        'cancelled' => ['variant' => 'inactive', 'label' => 'Cancelled'],
        'refunded' => ['variant' => 'info', 'label' => 'Refunded'],
        'partially_refunded' => ['variant' => 'info', 'label' => 'Partially refunded'],
    ];

    $meta = $map[$status] ?? [
        'variant' => 'inactive',
        'label' => ucfirst(str_replace('_', ' ', (string) $status)),
    ];
@endphp

<x-badge :variant="$meta['variant']">{{ $meta['label'] }}</x-badge>