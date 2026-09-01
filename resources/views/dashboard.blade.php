@extends('layouts.app')

@section('title', 'Dashboard')

@php
    $healthLabels = [
        'retention_config_invalid' => 'Retention configuration is invalid.',
        'database_unavailable' => 'Audit storage is temporarily unavailable.',
        'stale_events_present' => 'Events older than the retention window are still active — archiving may be behind schedule.',
    ];

    $outcomeVariants = [
        'success' => 'active',
        'failure' => 'revoked',
        'rejected' => 'pending',
    ];

    $humanEvent = static fn (string $event): string => ucfirst(str_replace(['.', '_'], ' ', $event));
@endphp

@section('content')
    <div class="py-4 py-lg-5">
        @if ($merchant === null)
            <div class="text-center py-5">
                <div class="mb-3">
                    <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" fill="none"
                         stroke="var(--text-secondary)" stroke-width="1.5" stroke-linecap="round"
                         stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M3 21h18M5 21V7l7-4 7 4v14M9 9h1m4 0h1M9 13h1m4 0h1M9 17h1m4 0h1"/>
                    </svg>
                </div>
                <h1 class="h4 fw-bold mb-2">No workspace available</h1>
                <p class="text-secondary-custom mb-0 mx-auto" style="max-width: 420px;">
                    You are not a member of any merchant yet. A workspace administrator can
                    invite you, or a merchant can be created for your account.
                </p>
            </div>
        @else
            <div class="border-bottom pb-4 mb-4">
                <h1 class="h2 fw-bold mb-2">{{ $merchant->name }}</h1>
                <div class="d-flex align-items-center gap-2">
                    <code class="text-secondary-custom">{{ $merchant->slug }}</code>
                    @if ($merchant->pivot?->role !== null)
                        <span class="badge badge-role">{{ ucfirst($merchant->pivot->role) }}</span>
                    @endif
                </div>
                <p class="text-secondary-custom small mb-0 mt-2">Operational overview.</p>
            </div>

            @if ($dashboard !== null && $dashboard->hasNoActivity())
                <x-empty-state
                    title="No activity yet"
                    message="Once your integration creates payments, refunds, and audit events, their activity and health will appear here.">
                    <a href="{{ route('settings.api-keys.index') }}" class="btn btn-primary">Create an API key</a>
                </x-empty-state>
            @endif

            <h2 class="visually-hidden">Overview</h2>
            <div class="row g-4 mb-4">
                <div class="col-sm-6 col-lg-3">
                    @if ($dashboard !== null && $dashboard->paymentsAvailable())
                        <x-stat-card label="Payments" :value="$dashboard->paymentTotal()" hint="All time" />
                    @else
                        <x-stat-card label="Payments" value="—" hint="Temporarily unavailable" />
                    @endif
                </div>
                <div class="col-sm-6 col-lg-3">
                    @if ($dashboard !== null && $dashboard->paymentsAvailable())
                        <x-stat-card label="Succeeded" :value="$dashboard->succeededPayments()" hint="Completed payments" />
                    @else
                        <x-stat-card label="Succeeded" value="—" hint="Temporarily unavailable" />
                    @endif
                </div>
                <div class="col-sm-6 col-lg-3">
                    @if ($dashboard !== null && $dashboard->paymentsAvailable())
                        <x-stat-card label="Failed" :value="$dashboard->failedPayments()" hint="Did not complete" />
                    @else
                        <x-stat-card label="Failed" value="—" hint="Temporarily unavailable" />
                    @endif
                </div>
                <div class="col-sm-6 col-lg-3">
                    @if ($dashboard !== null && $dashboard->paymentsAvailable())
                        <x-stat-card label="In flight" :value="$dashboard->inFlightPayments()" hint="Pending or processing" />
                    @else
                        <x-stat-card label="In flight" value="—" hint="Temporarily unavailable" />
                    @endif
                </div>
            </div>
            <h2 class="visually-hidden">Summaries</h2>
            <div class="row g-4 mb-4">
                <div class="col-lg-6">
                    <div class="card border border-custom h-100">
                        <div class="card-body p-4">
                            <h3 class="h6 fw-bold text-uppercase text-secondary-custom mb-4">Payment summary</h3>
                            @if ($dashboard !== null && $dashboard->paymentsAvailable())
                                <ul class="list-group list-group-flush">
                                    @foreach (\App\Enums\PaymentStatus::cases() as $status)
                                        <li class="list-group-item px-0 py-2 bg-transparent d-flex justify-content-between align-items-center">
                                            <x-status-badge :status="$status->value" />
                                            <span class="fw-semibold">{{ $dashboard->paymentCount($status) }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @else
                                <p class="text-secondary-custom small mb-0">Payment metrics are temporarily unavailable.</p>
                            @endif
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card border border-custom h-100">
                        <div class="card-body p-4">
                            <h3 class="h6 fw-bold text-uppercase text-secondary-custom mb-4">Refund summary</h3>
                            @if ($dashboard !== null && $dashboard->refundsAvailable())
                                <ul class="list-group list-group-flush">
                                    @foreach (\App\Enums\RefundStatus::cases() as $status)
                                        <li class="list-group-item px-0 py-2 bg-transparent d-flex justify-content-between align-items-center">
                                            <x-status-badge :status="$status->value" />
                                            <span class="fw-semibold">{{ $dashboard->refundCount($status) }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @else
                                <p class="text-secondary-custom small mb-0">Refund metrics are temporarily unavailable.</p>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
            <div class="row g-4">
                <div class="col-lg-7">
                    <div class="card border border-custom h-100">
                        <div class="card-body p-4">
                            <h3 class="h6 fw-bold text-uppercase text-secondary-custom mb-4">Recent activity</h3>
                            @if ($dashboard === null || $dashboard->recentActivity === null)
                                <p class="text-secondary-custom small mb-0">Activity is temporarily unavailable.</p>
                            @elseif ($dashboard->recentActivity->isEmpty())
                                <p class="text-secondary-custom small mb-0">No audit activity recorded yet.</p>
                            @else
                                <ul class="list-group list-group-flush">
                                    @foreach ($dashboard->recentActivity as $activity)
                                        <li class="list-group-item px-0 py-2 {{ ! $loop->first ? 'border-top' : '' }}">
                                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                                                <span class="fw-semibold">{{ $humanEvent($activity->event->value) }}</span>
                                                @if ($activity->outcome !== null)
                                                    <x-badge :variant="$outcomeVariants[$activity->outcome->value] ?? 'inactive'">
                                                        {{ ucfirst($activity->outcome->value) }}
                                                    </x-badge>
                                                @endif
                                            </div>
                                            <div class="small text-secondary-custom mt-1">
                                                @if ($activity->payment_reference !== null)
                                                    <code>pay: {{ $activity->payment_reference }}</code>
                                                @endif
                                                @if ($activity->refund_reference !== null)
                                                    <code>refund: {{ $activity->refund_reference }}</code>
                                                @endif
                                                <time datetime="{{ $activity->performed_at?->toISOString() }}"
                                                      title="{{ $activity->performed_at?->format('M j, Y H:i:s') }} UTC">
                                                    {{ $activity->performed_at?->diffForHumans() }}
                                                </time>
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="card border border-custom h-100">
                        <div class="card-body p-4">
                            <h3 class="h6 fw-bold text-uppercase text-secondary-custom mb-4">Audit pipeline health</h3>
                            @if ($auditHealth === null)
                                <p class="text-secondary-custom small mb-0">Health status is temporarily unavailable.</p>
                            @else
                                <div class="d-flex align-items-center gap-2 mb-3">
                                    @if ($auditHealth->healthy)
                                        <x-badge variant="active">Healthy</x-badge>
                                    @elseif ($auditHealth->reason === 'stale_events_present')
                                        <x-badge variant="pending">Attention required</x-badge>
                                    @else
                                        <x-badge variant="revoked">Unhealthy</x-badge>
                                    @endif
                                    @if ($auditHealth->reason !== null && isset($healthLabels[$auditHealth->reason]))
                                        <span class="small text-secondary-custom">{{ $healthLabels[$auditHealth->reason] }}</span>
                                    @endif
                                </div>
                                <dl class="row row-cols-1 g-2 small mb-0">
                                    <div class="col d-flex justify-content-between">
                                        <dt class="fw-normal text-secondary-custom">Retention window</dt>
                                        <dd class="mb-0">
                                            @if ($auditHealth->retentionConfigValid)
                                                {{ $auditHealth->retentionDays }} days
                                            @else
                                                Invalid
                                            @endif
                                        </dd>
                                    </div>
                                    <div class="col d-flex justify-content-between">
                                        <dt class="fw-normal text-secondary-custom">Events past retention</dt>
                                        <dd class="mb-0">{{ $auditHealth->staleCount ?? 'Unavailable' }}</dd>
                                    </div>
                                    <div class="col d-flex justify-content-between">
                                        <dt class="fw-normal text-secondary-custom">Archived events</dt>
                                        <dd class="mb-0">{{ $auditHealth->archivedCount ?? 'Unavailable' }}</dd>
                                    </div>
                                    <div class="col d-flex justify-content-between">
                                        <dt class="fw-normal text-secondary-custom">Newest event</dt>
                                        <dd class="mb-0">
                                            @if ($auditHealth->newestEventAt !== null)
                                                <time datetime="{{ $auditHealth->newestEventAt->toISOString() }}">
                                                    {{ $auditHealth->newestEventAt->diffForHumans() }}
                                                </time>
                                            @else
                                                None
                                            @endif
                                        </dd>
                                    </div>
                                </dl>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>
@endsection