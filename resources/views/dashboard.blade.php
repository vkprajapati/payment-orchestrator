@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <div class="py-4 py-lg-5">
        @if ($currentMerchant ?? null)
            <!-- Current Merchant -->
            <div class="border-bottom pb-4 mb-5">
                <h1 class="h2 fw-bold mb-2">{{ $currentMerchant->name }}</h1>
                <div class="d-flex align-items-center gap-2">
                    <code class="text-secondary-custom">{{ $currentMerchant->slug }}</code>
                    <span class="badge badge-role">{{ ucfirst($currentMerchant->pivot?->role ?? '') }}</span>
                </div>
            </div>

            <!-- Welcome -->
            <div class="mb-5">
                <h2 class="h5 fw-bold mb-2">Welcome back, {{ Auth::user()->name }} 👋</h2>
                <p class="text-secondary-custom">
                    Your payment infrastructure dashboard is ready.
                    Configure your providers and start orchestrating payments.
                </p>
            </div>

            <!-- Stats Cards -->
            <div class="row g-4 mb-5">
                <div class="col-sm-6 col-lg-4">
                    <div class="card border border-custom h-100">
                        <div class="card-body p-4">
                            <p class="text-secondary-custom small fw-medium mb-2 text-uppercase">Payments</p>
                            <p class="h2 fw-bold mb-0">0</p>
                            <p class="text-secondary-custom small mt-2 mb-0">Total processed</p>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-4">
                    <div class="card border border-custom h-100">
                        <div class="card-body p-4">
                            <p class="text-secondary-custom small fw-medium mb-2 text-uppercase">Providers</p>
                            <p class="h2 fw-bold mb-0">0</p>
                            <p class="text-secondary-custom small mt-2 mb-0">Connected providers</p>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-4">
                    <div class="card border border-custom h-100">
                        <div class="card-body p-4">
                            <p class="text-secondary-custom small fw-medium mb-2 text-uppercase">Success Rate</p>
                            <p class="h2 fw-bold mb-0">--</p>
                            <p class="text-secondary-custom small mt-2 mb-0">No data yet</p>
                        </div>
                    </div>
                </div>
            </div>
        @else
            <!-- No Merchant Empty State -->
            <div class="text-center py-5">
                <div class="mb-3">
                    <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" fill="none"
                         stroke="var(--text-secondary)" stroke-width="1.5" stroke-linecap="round"
                         stroke-linejoin="round" viewBox="0 0 24 24">
                        <path d="M3 21h18M5 21V7l7-4 7 4v14M9 9h1m4 0h1M9 13h1m4 0h1M9 17h1m4 0h1"/>
                    </svg>
                </div>
                <h2 class="h4 fw-bold mb-2">No workspace available</h2>
                <p class="text-secondary-custom mb-0 mx-auto" style="max-width: 420px;">
                    You are not a member of any merchant yet. A workspace administrator can
                    invite you, or a merchant can be created for your account.
                </p>
            </div>
        @endif
    </div>
@endsection
