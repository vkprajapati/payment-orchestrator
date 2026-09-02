@extends('layouts.app')

@section('title', 'API Key — '.$apiKey->name)

@section('content')
    @php
        $allScopes = App\Enums\ApiKeyScope::values();
        $scopeGroups = [
            'Account' => ['account:read'],
            'Payments' => ['payments:read', 'payments:write', 'payments:process'],
            'Refunds' => ['refunds:read', 'refunds:write'],
            'API Keys' => ['api_keys:read', 'api_keys:write'],
            'Audit' => ['audit:read'],
        ];
        $status = $apiKey->isRevoked() ? 'Revoked' : ($apiKey->isExpired() ? 'Expired' : 'Active');
        $variant = $apiKey->isRevoked() ? 'suspended' : ($apiKey->isExpired() ? 'inactive' : 'active');
        $keyScopes = $apiKey->scopes ?? $allScopes;
    @endphp

    <div class="py-4 py-lg-5">
        <a href="{{ route('settings.api-keys.index') }}" class="link-primary small d-inline-block mb-3">&larr; All API keys</a>

        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-4">
            <div>
                <h1 class="h3 fw-bold mb-1">{{ $apiKey->name }}</h1>
                <code class="small text-secondary-custom">{{ $apiKey->reference }}</code>
            </div>
            <span class="badge-status badge-status-{{ $variant }}">{{ $status }}</span>
        </div>

        <div class="row g-4">
            <div class="col-lg-5">
                <div class="card border border-custom rounded-3 h-100">
                    <div class="card-body p-4">
                        <h2 class="h6 fw-bold text-uppercase text-secondary-custom mb-4">Key Details</h2>

                        <dl class="row small mb-0">
                            <dt class="col-5 fw-normal text-secondary-custom">Label</dt>
                            <dd class="col-7">{{ $apiKey->label ?? '—' }}</dd>
                            <dt class="col-5 fw-normal text-secondary-custom">Created</dt>
                            <dd class="col-7">{{ $apiKey->created_at?->format('M j, Y H:i') }}</dd>
                            <dt class="col-5 fw-normal text-secondary-custom">Last Used</dt>
                            <dd class="col-7" title="{{ $apiKey->last_used_at?->toIso8601String() }}">
                                {{ $apiKey->last_used_at?->format('M j, Y H:i') ?? 'Never' }}
                            </dd>
                            <dt class="col-5 fw-normal text-secondary-custom">Expires</dt>
                            <dd class="col-7">{{ $apiKey->expires_at?->format('M j, Y') ?? 'Never' }}</dd>
                            @if ($apiKey->revoked_at)
                                <dt class="col-5 fw-normal text-secondary-custom">Revoked</dt>
                                <dd class="col-7">{{ $apiKey->revoked_at->format('M j, Y H:i') }}</dd>
                            @endif
                        </dl>

                        <h3 class="h6 fw-bold text-uppercase text-secondary-custom mt-4 mb-2">Current Scopes</h3>
                        <div class="d-flex flex-wrap gap-1" aria-label="Current scopes">
                            @foreach ($keyScopes as $scope)
                                <span class="badge rounded-pill text-bg-light border border-custom small fw-normal">{{ $scope }}</span>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                @if ($apiKey->isActive())
                    <div class="card border border-custom rounded-3 mb-4">
                        <div class="card-body p-4">
                            <h2 class="h6 fw-bold text-uppercase text-secondary-custom mb-2">Rotate Key</h2>
                            <p class="small text-secondary-custom">
                                Rotation revokes the current key immediately, creates a replacement with the
                                same name, label, and scopes, and shows the new secret <strong>once</strong>.
                                Update every integration that uses this key.
                            </p>
                            <x-confirm action="{{ route('settings.api-keys.rotate', $apiKey) }}"
                                       method="POST"
                                       message="Rotate this API key? The current key will be revoked immediately and a new secret will be shown once."
                                       data-loading="Rotating…">
                                <button type="submit" class="btn btn-outline-primary btn-sm">Rotate Key</button>
                            </x-confirm>
                        </div>
                    </div>

                    <div class="card border border-custom rounded-3 mb-4">
                        <div class="card-body p-4">
                            <h2 class="h6 fw-bold text-uppercase text-secondary-custom mb-2">Revoke Key</h2>
                            <p class="small text-secondary-custom">
                                Revoking permanently disables this key. Requests using it will receive a
                                generic authentication error. This cannot be undone.
                            </p>
                            <x-confirm action="{{ route('settings.api-keys.destroy', $apiKey) }}"
                                       method="DELETE"
                                       message="Revoke this API key? Requests using it will stop working. This cannot be undone."
                                       data-loading="Revoking…">
                                <button type="submit" class="btn btn-outline-danger btn-sm">Revoke Key</button>
                            </x-confirm>
                        </div>
                    </div>
                @else
                    <x-alert type="info">
                        This key is {{ strtolower($status) }} and can no longer authenticate. Its history is
                        preserved for auditing.
                    </x-alert>
                @endif

                <div class="card border border-custom rounded-3">
                    <div class="card-body p-4">
                        <h2 class="h6 fw-bold text-uppercase text-secondary-custom mb-2">Update Scopes</h2>
                        <p class="small text-secondary-custom">
                            Replaces the key's scopes. The secret and hash are never changed by a scope
                            update. Changes apply immediately to all requests using this key.
                        </p>

                        <form method="POST" action="{{ route('settings.api-keys.scopes', $apiKey) }}" data-loading="Updating…">
                            @csrf
                            @method('PUT')

                            @error('scopes')<div class="small text-danger mb-2" role="alert">{{ $message }}</div>@enderror

                            @foreach ($scopeGroups as $groupName => $groupScopes)
                                <div class="small fw-semibold text-secondary-custom mt-2 mb-1">{{ $groupName }}</div>
                                @foreach ($groupScopes as $scope)
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="scopes[]"
                                               id="edit-scope-{{ str_replace(':', '-', $scope) }}" value="{{ $scope }}"
                                               @checked(in_array($scope, old('scopes', $keyScopes)))>
                                        <label class="form-check-label small" for="edit-scope-{{ str_replace(':', '-', $scope) }}">
                                            <code>{{ $scope }}</code>
                                        </label>
                                    </div>
                                @endforeach
                            @endforeach

                            <button type="submit" class="btn btn-primary btn-sm mt-3">Save Scopes</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
