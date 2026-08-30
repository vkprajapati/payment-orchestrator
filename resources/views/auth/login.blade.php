@extends('layouts.guest')

@section('title', 'Login')

@section('content')
    <div class="text-center mb-5">
        <h1 class="h3 fw-bold mb-2">Welcome back</h1>
        <p class="text-secondary-custom">Sign in to your account to continue.</p>
    </div>

    <form method="POST" action="{{ route('login.store') }}">
        @csrf

        @if ($errors->any())
            <div class="alert alert-danger py-2 px-3 mb-4" role="alert">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="mb-4">
            <label for="email" class="form-label">Email address</label>
            <input type="email" class="form-control @error('email') is-invalid @enderror"
                   id="email" name="email" value="{{ old('email') }}" required autofocus>
            @error('email')
                <div class="invalid-feedback d-block small mt-1">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-4">
            <label for="password" class="form-label">Password</label>
            <input type="password" class="form-control @error('password') is-invalid @enderror"
                   id="password" name="password" required autocomplete="current-password">
            @error('password')
                <div class="invalid-feedback d-block small mt-1">{{ $message }}</div>
            @enderror
        </div>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="remember" name="remember">
                <label class="form-check-label" for="remember">Remember me</label>
            </div>
            @if (Route::has('password.request'))
                <a class="link-primary small" href="{{ route('password.request') }}">Forgot password?</a>
            @endif
        </div>

        <button type="submit" class="btn btn-primary mb-4">Sign in</button>

        <p class="text-center text-secondary-custom small mb-0">
            Don't have an account?
            <a href="{{ route('register') }}" class="link-primary">Create account</a>
        </p>
    </form>
@endsection
