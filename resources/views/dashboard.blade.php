@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <div class="py-4 py-lg-5">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-5">
            <div>
                <h1 class="h4 fw-bold mb-1">Payment Orchestrator</h1>
                <p class="text-secondary-custom small mb-0">Your payment infrastructure dashboard</p>
            </div>
            <div class="profile-dropdown">
                <button type="button" class="btn btn-outline-secondary d-flex align-items-center"
                        style="border-color: var(--border); border-radius: 8px; padding: .5rem .75rem;">
                    <span class="me-2 d-none d-sm-inline">{{ Auth::user()->name }}</span>
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round"
                         stroke-linejoin="round" viewBox="0 0 24 24">
                        <polyline points="6 9 12 15 18 9"></polyline>
                    </svg>
                </button>
                <div class="profile-menu">
                    <a href="#" class="d-block px-3 py-2 small text-decoration-none">Profile</a>
                    <a href="#" class="d-block px-3 py-2 small text-decoration-none">Settings</a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="d-block w-100 text-start px-3 py-2 small text-decoration-none">
                            Logout
                        </button>
                    </form>
                </div>
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
    </div>
@endsection
