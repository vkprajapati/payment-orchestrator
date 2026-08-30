@extends('layouts.app')

@section('title', 'Workspace Settings')

@section('content')
    <div class="py-4 py-lg-5">
        @if (session('status'))
            <div class="alert alert-success py-2 px-3 mb-4" role="alert">
                {{ session('status') }}
            </div>
        @endif

        <div class="mb-4">
            <h1 class="h3 fw-bold mb-2">Settings</h1>
            <p class="text-secondary-custom mb-0">Manage your workspace configuration.</p>
        </div>

        <div class="card border border-custom rounded-3" style="max-width: 680px;">
            <div class="card-body p-4 p-lg-5">
                <h2 class="h6 fw-bold text-uppercase text-secondary-custom mb-4">Workspace Information</h2>

                <form method="POST" action="{{ route('settings.workspace.update') }}">
                    @csrf
                    @method('PUT')

                    <div class="mb-4">
                        <label for="name" class="form-label">Workspace Name</label>
                        <input type="text" id="name" name="name"
                               class="form-control @error('name') is-invalid @enderror"
                               value="{{ old('name', $merchant->name) }}" required>
                        @error('name')
                            <div class="invalid-feedback d-block small mt-1">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-4">
                        <label for="slug" class="form-label">Workspace Slug</label>
                        <input type="text" id="slug" name="slug"
                               class="form-control @error('slug') is-invalid @enderror"
                               value="{{ old('slug', $merchant->slug) }}" required>
                        <div class="form-text small">
                            Lowercase letters, numbers, and hyphens only. Example: <code>acme-inc</code>
                        </div>
                        @error('slug')
                            <div class="invalid-feedback d-block small mt-1">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-4">
                        <label class="form-label d-block mb-1">Status</label>
                        <span class="badge-status badge-status-{{ $merchant->status }}">
                            {{ ucfirst($merchant->status) }}
                        </span>
                        <div class="form-text small">Status cannot be changed from this page.</div>
                    </div>

                    <div class="mb-5">
                        <label class="form-label d-block mb-1">Created</label>
                        <p class="text-secondary-custom small mb-0">{{ $merchant->created_at?->format('F j, Y') }}</p>
                    </div>

                    <div class="d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection