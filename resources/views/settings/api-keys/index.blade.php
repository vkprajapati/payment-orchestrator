@extends('layouts.app')

@section('title', 'API Keys')

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
    @endphp

    <div class="py-4 py-lg-5">
        @if (session('status'))
            <x-alert type="success" dismissible>{{ session('status') }}</x-alert>
        @endif

        <div class="mb-4">
            <h1 class="h3 fw-bold mb-2">API Keys</h1>
            <p class="text-secondary-custom mb-0">Manage credentials for accessing the Payment API.</p>
        </div>

        <div class="row g-4">
            <div class="col-lg-4">
                <div class="card border border-custom rounded-3">
                    <div class="card-body p-4">
                        <h2 class="h6 fw-bold text-uppercase text-secondary-custom mb-4">Create API Key</h2>

                        <form method="POST" action="{{ route('settings.api-keys.store') }}" data-loading="Creating key…">
                            @csrf
                            <input type="hidden" name="scopes_submitted" value="1">

                            <div class="mb-3">
                                <label for="name" class="form-label">Name</label>
                                <input type="text" id="name" name="name" placeholder="Production Server"
                                       class="form-control @error('name') is-invalid @enderror"
                                       value="{{ old('name') }}" required>
                                <div class="form-text small">Example: Production Server, Mobile Application, CI/CD.</div>
                                @error('name')<div class="invalid-feedback d-block small mt-1">{{ $message }}</div>@enderror
                            </div>

                            <div class="mb-3">
                                <label for="label" class="form-label">Label <span class="text-secondary-custom">(optional)</span></label>
                                <input type="text" id="label" name="label" placeholder="eu-west-1 billing"
                                       class="form-control @error('label') is-invalid @enderror"
                                       value="{{ old('label') }}">
                                @error('label')<div class="invalid-feedback d-block small mt-1">{{ $message }}</div>@enderror
                            </div>

                            <div class="mb-3">
                                <label for="expires_at" class="form-label">Expires <span class="text-secondary-custom">(optional)</span></label>
                                <input type="date" id="expires_at" name="expires_at"
                                       class="form-control @error('expires_at') is-invalid @enderror"
                                       value="{{ old('expires_at') }}" min="{{ now()->toDateString() }}">
                                <div class="form-text small">The key stops authenticating after this date.</div>
                                @error('expires_at')<div class="invalid-feedback d-block small mt-1">{{ $message }}</div>@enderror
                            </div>

                            <fieldset class="mb-3">
                                <legend class="form-label mb-2">Scopes</legend>
                                <div class="form-text small mb-2">At least one scope is required. Each checked scope grants the key that permission.</div>
                                @error('scopes')<div class="small text-danger mb-2" role="alert">{{ $message }}</div>@enderror
                                @foreach ($scopeGroups as $groupName => $groupScopes)
                                    <div class="small fw-semibold text-secondary-custom mt-2 mb-1">{{ $groupName }}</div>
                                    @foreach ($groupScopes as $scope)
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="scopes[]"
                                                   id="scope-{{ str_replace(':', '-', $scope) }}" value="{{ $scope }}"
                                                   @checked(in_array($scope, old('scopes', $allScopes)))>
                                            <label class="form-check-label small" for="scope-{{ str_replace(':', '-', $scope) }}">
                                                <code>{{ $scope }}</code>
                                            </label>
                                        </div>
                                    @endforeach
                                @endforeach
                            </fieldset>

                            <button type="submit" class="btn btn-primary w-100">Create API Key</button>
                        </form>
                    </div>
                </div>
            </div>

                        <div class="col-lg-8">
                <div class="card border border-custom rounded-3">
                    <div class="card-body p-4">
                        <h2 class="h6 fw-bold text-uppercase text-secondary-custom mb-4">Your API Keys</h2>

                        @if ($apiKeys->isEmpty())
                            <x-empty-state title="No API keys yet"
                                           message="Create your first key to access the Payment API."
                                           icon="🔑">
                                <span class="text-secondary-custom small">The secret is shown once at creation — store it securely.</span>
                            </x-empty-state>
                        @else
                            <div class="list-group list-group-flush">
                                @foreach ($apiKeys as $apiKey)
                                    @php
                                        $status = $apiKey->isRevoked() ? 'Revoked' : ($apiKey->isExpired() ? 'Expired' : 'Active');
                                        $variant = $apiKey->isRevoked() ? 'suspended' : ($apiKey->isExpired() ? 'inactive' : 'active');
                                        $keyScopes = $apiKey->scopes ?? $allScopes;
                                    @endphp
                                    <div class="list-group-item px-0 py-3 {{ ! $loop->first ? 'border-top' : '' }}">
                                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                                            <div>
                                                <div class="fw-semibold">
                                                    {{ $apiKey->name }}
                                                    @if ($apiKey->label)
                                                        <span class="text-secondary-custom fw-normal">— {{ $apiKey->label }}</span>
                                                    @endif
                                                </div>
                                                <code class="small text-secondary-custom">{{ $apiKey->reference }}</code>
                                                <code class="small text-secondary-custom d-block">{{ $apiKey->key_prefix }}…</code>
                                            </div>
                                            <span class="badge-status badge-status-{{ $variant }}">{{ $status }}</span>
                                        </div>

                                        <div class="mt-2 d-flex flex-wrap gap-1" aria-label="Scopes for {{ $apiKey->name }}">
                                            @foreach ($keyScopes as $scope)
                                                <span class="badge rounded-pill text-bg-light border border-custom small fw-normal">{{ $scope }}</span>
                                            @endforeach
                                        </div>

                                        <dl class="row row-cols-sm-auto small text-secondary-custom mb-0 mt-2 gx-4">
                                            <div class="col-auto">
                                                <dt class="fw-normal">Created</dt>
                                                <dd class="mb-0">{{ $apiKey->created_at?->format('M j, Y') }}</dd>
                                            </div>
                                            <div class="col-auto">
                                                <dt class="fw-normal">Last Used</dt>
                                                <dd class="mb-0" title="{{ $apiKey->last_used_at?->toIso8601String() }}">
                                                    {{ $apiKey->last_used_at?->format('M j, Y') ?? 'Never' }}
                                                </dd>
                                            </div>
                                            <div class="col-auto">
                                                <dt class="fw-normal">Expires</dt>
                                                <dd class="mb-0">{{ $apiKey->expires_at?->format('M j, Y') ?? 'Never' }}</dd>
                                            </div>
                                            @if ($apiKey->revoked_at)
                                                <div class="col-auto">
                                                    <dt class="fw-normal">Revoked</dt>
                                                    <dd class="mb-0">{{ $apiKey->revoked_at->format('M j, Y') }}</dd>
                                                </div>
                                            @endif
                                        </dl>

                                        <div class="d-flex flex-wrap gap-2 mt-3">
                                            <a href="{{ route('settings.api-keys.show', $apiKey) }}" class="btn btn-outline-secondary btn-sm">
                                                Manage
                                            </a>
                                            @if ($apiKey->isActive())
                                                <x-confirm action="{{ route('settings.api-keys.rotate', $apiKey) }}"
                                                           method="POST"
                                                           message="Rotate this API key? The current key will be revoked immediately and a new secret will be shown once."
                                                           class="d-inline">
                                                    <button type="submit" class="btn btn-outline-primary btn-sm">Rotate</button>
                                                </x-confirm>
                                                <x-confirm action="{{ route('settings.api-keys.destroy', $apiKey) }}"
                                                           method="DELETE"
                                                           message="Revoke this API key? Requests using it will stop working. This cannot be undone."
                                                           class="d-inline">
                                                    <button type="submit" class="btn btn-outline-danger btn-sm">Revoke</button>
                                                </x-confirm>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
