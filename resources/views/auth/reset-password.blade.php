@extends('layouts.guest')

@section('title', 'Reset Password')

@section('content')
    <div class="text-center mb-5">
        <h1 class="h3 fw-bold mb-2">Reset your password</h1>
        <p class="text-secondary-custom">Enter your new password below.</p>
    </div>

    <form method="POST" action="{{ route('password.update') }}">
        @csrf

        <input type="hidden" name="token" value="{{ $request->route('token') }}">

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
                   id="email" name="email" value="{{ $request->email ?? old('email') }}" required>
            @error('email')
                <div class="invalid-feedback d-block small mt-1">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3">
            <label for="password" class="form-label">New password</label>
            <input type="password" class="form-control @error('password') is-invalid @enderror"
                   id="password" name="password" required autocomplete="new-password">
            @error('password')
                <div class="invalid-feedback d-block small mt-1">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-4">
            <label for="password_confirmation" class="form-label">Confirm password</label>
            <input type="password" class="form-control"
                   id="password_confirmation" name="password_confirmation" required autocomplete="new-password">
        </div>

        <small class="text-secondary-custom d-block mb-4">
            At least 8 characters. Use mixed case, numbers, and symbols for a stronger password.
        </small>

        <button type="submit" class="btn btn-primary mb-4">Reset password</button>

        <p class="text-center text-secondary-custom small mb-0">
            <a href="{{ route('login') }}" class="link-primary">Back to login</a>
        </p>
    </form>
@endsection
