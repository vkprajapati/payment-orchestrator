@extends('layouts.app')

@section('title', $context === 'rotated' ? 'API Key Rotated' : 'API Key Created')

@section('content')
    <div class="py-4 py-lg-5">
        <div class="card border border-custom rounded-3 mx-auto" style="max-width: 680px;">
            <div class="card-body p-4 p-lg-5">
                @if ($context === 'rotated')
                    <h1 class="h4 fw-bold mb-2">API key rotated</h1>
                    <p class="text-secondary-custom small">
                        A replacement key for <span class="fw-semibold">{{ $apiKey->name }}</span> was created
                        and the previous key has been revoked. Any request using the old key now receives a
                        generic authentication error.
                    </p>
                @else
                    <h1 class="h4 fw-bold mb-2">Your API key</h1>
                    <p class="text-secondary-custom small">
                        Here is the API key for <span class="fw-semibold">{{ $apiKey->name }}</span>.
                    </p>
                @endif

                <div class="alert alert-warning d-flex align-items-start gap-2 py-3" role="alert">
                    <span aria-hidden="true" class="fw-bold">!</span>
                    <div>
                        <strong>Copy this secret now. It will not be shown again.</strong><br>
                        It cannot be retrieved later. If it is lost, rotate the key to generate a new one.
                    </div>
                </div>

                <div class="border border-custom rounded-3 bg-surface p-3 mb-2">
                    <code id="raw-key-value" class="user-select-all d-block text-break">{{ $rawKey }}</code>
                </div>

                <button type="button" class="btn btn-outline-secondary btn-sm mb-4"
                        data-copy-target="#raw-key-value"
                        aria-live="polite">
                    Copy secret
                </button>

                <div>
                    <a href="{{ route('settings.api-keys.index') }}" class="btn btn-primary">Done</a>
                </div>
            </div>
        </div>
    </div>
@endsection
