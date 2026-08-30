@extends('layouts.app')

@section('title', 'API Key Created')

@section('content')
    <div class="py-4 py-lg-5">
        <div class="card border border-custom rounded-3 mx-auto" style="max-width: 680px;">
            <div class="card-body p-4 p-lg-5">
                <h1 class="h4 fw-bold mb-2">Your API key</h1>
                <p class="text-secondary-custom small">
                    Here is the API key for <span class="fw-semibold">{{ $apiKey->name }}</span>.
                </p>

                <div class="alert alert-warning d-flex align-items-start gap-2 py-3" role="alert">
                    <div>
                        <strong>Copy this key now.</strong><br>
                        It will not be shown again and cannot be retrieved later.
                    </div>
                </div>

                <div class="border border-custom rounded-3 bg-surface p-3 mb-4">
                    <code class="user-select-all d-block text-break">{{ $rawKey }}</code>
                </div>

                <a href="{{ route('settings.api-keys.index') }}" class="btn btn-primary">Done</a>
            </div>
        </div>
    </div>
@endsection
