@extends('layouts.guest')

@section('title', 'Forgot Password')

@section('content')
    <div class="text-center mb-5">
        <h1 class="h3 fw-bold mb-2">Forgot your password?</h1>
        <p class="text-secondary-custom">
            Enter your email address and we'll send you a password reset link.
        </p>
    </div>

    <form method="POST" action="{{ route('password.email') }}">
        @csrf

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

        <div class="mb-4">
            <label for="email" class="form-label">Email address</label>
            <input type="email" class="form-control @error('email') is-invalid @enderror"
                   id="email" name="email" value="{{ old('email') }}" required autofocus>
            @error('email')
                <div class="invalid-feedback d-block small mt-1">{{ $message }}</div>
            @enderror
        </div>

        <button type="submit" class="btn btn-primary mb-4">Send reset link</button>

        <p class="text-center text-secondary-custom small mb-0">
            <a href="{{ route('login') }}" class="link-primary">Back to login</a>
        </p>
    </form>
@endsection
