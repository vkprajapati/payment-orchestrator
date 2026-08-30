@extends('layouts.app')

@section('title', 'API Keys')

@section('content')
    <div class="py-4 py-lg-5">
        @if (session('status'))
            <div class="alert alert-success py-2 px-3 mb-4" role="alert">
                {{ session('status') }}
            </div>
        @endif

        <div class="mb-4">
            <h1 class="h3 fw-bold mb-2">API Keys</h1>
            <p class="text-secondary-custom mb-0">Manage credentials for accessing the Payment API.</p>
        </div>

        <div class="row g-4">
            <div class="col-lg-4">
                <div class="card border border-custom rounded-3 h-100">
                    <div class="card-body p-4">
                        <h2 class="h6 fw-bold text-uppercase text-secondary-custom mb-4">Create API Key</h2>

                        <form method="POST" action="{{ route('settings.api-keys.store') }}">
                            @csrf

                            <div class="mb-4">
                                <label for="name" class="form-label">Name</label>
                                <input type="text" id="name" name="name" placeholder="Production Server"
                                       class="form-control @error('name') is-invalid @enderror"
                                       value="{{ old('name') }}" required>
                                <div class="form-text small">Example: Production Server, Mobile Application, CI/CD.</div>
                                @error('name')
                                    <div class="invalid-feedback d-block small mt-1">{{ $message }}</div>
                                @enderror
                            </div>

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
                            <p class="text-secondary-custom small mb-0">
                                No API keys yet. Create your first key to access the Payment API.
                            </p>
                        @else
                            <div class="list-group list-group-flush">
                                @foreach ($apiKeys as $apiKey)
                                    <div class="list-group-item px-0 py-3 {{ ! $loop->first ? 'border-top' : '' }}">
                                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                                            <div>
                                                <div class="fw-semibold">{{ $apiKey->name }}</div>
                                                <code class="small text-secondary-custom">{{ $apiKey->key_prefix }}...</code>
                                            </div>
                                            <span class="badge-status {{ $apiKey->isRevoked() ? 'badge-status-suspended' : 'badge-status-active' }}">
                                                {{ $apiKey->isRevoked() ? 'Revoked' : 'Active' }}
                                            </span>
                                        </div>
                                        <dl class="row row-cols-sm-auto small text-secondary-custom mb-0 mt-2 g-x-4">
                                            <div class="col-auto">
                                                <dt class="fw-normal">Created</dt>
                                                <dd class="mb-0">{{ $apiKey->created_at?->format('M j, Y') }}</dd>
                                            </div>
                                            <div class="col-auto">
                                                <dt class="fw-normal">Last Used</dt>
                                                <dd class="mb-0">{{ $apiKey->last_used_at?->format('M j, Y') ?? 'Never' }}</dd>
                                            </div>
                                            <div class="col-auto">
                                                <dt class="fw-normal">Expires</dt>
                                                <dd class="mb-0">{{ $apiKey->expires_at?->format('M j, Y') ?? 'Never' }}</dd>
                                            </div>
                                        </dl>
                                        @if (! $apiKey->isRevoked())
                                            <form method="POST" action="{{ route('settings.api-keys.destroy', $apiKey) }}"
                                                  class="mt-3 text-end"
                                                  onsubmit="return confirm('Revoke this API key? Requests using it will stop working.');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-outline-danger btn-sm">Revoke</button>
                                            </form>
                                        @endif
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
