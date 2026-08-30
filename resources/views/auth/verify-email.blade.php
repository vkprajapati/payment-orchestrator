@extends('layouts.guest')

@section('title', 'Verify Email')

@section('content')
    <div class="text-center mb-5">
        <div class="d-inline-flex align-items-center justify-content-center mb-4"
             style="width: 64px; height: 64px; background: rgba(22,163,74,.1); border-radius: 50%;">
            <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round"
                 stroke-linejoin="round" style="color: var(--success);" viewBox="0 0 24 24">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                <polyline points="22 4 12 14.01l-6-6"></polyline>
            </svg>
        </div>
        <h1 class="h3 fw-bold mb-2">Verify your email address</h1>
        <p class="text-secondary-custom">
            Please check your email for a verification link.
            If you didn't receive it, click below to resend.
        </p>
    </div>

    @if (session('status'))
        <div class="alert alert-success py-2 px-3 mb-4" role="alert">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger py-2 px-3 mb-4" role="alert">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('verification.send') }}">
        @csrf
        <button type="submit" class="btn btn-primary mb-4">Resend verification email</button>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="mb-4">
        @csrf
        <button type="submit" class="btn btn-outline-secondary w-100">Logout</button>
    </form>

    <p class="text-center text-secondary-custom small mb-0">
        <a href="{{ url('/') }}" class="link-primary">Back to home</a>
    </p>
@endsection
