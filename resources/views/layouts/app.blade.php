<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('app.name', 'Payment Orchestrator'))</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4f46e5;
            --primary-hover: #4338ca;
            --background: #f8fafc;
            --surface: #ffffff;
            --text-primary: #0f172a;
            --text-secondary: #64748b;
            --border: #e2e8f0;
            --success: #16a34a;
            --danger: #dc2626;
        }
        * { font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        body { background-color: var(--background); color: var(--text-primary); line-height: 1.6; }
        .bg-surface { background-color: var(--surface); }
        .border-custom { border-color: var(--border) !important; }
        .text-secondary-custom { color: var(--text-secondary) !important; }
        .link-primary { color: var(--primary); text-decoration: none; }
        .link-primary:hover { color: var(--primary-hover); text-decoration: underline; }
        .navbar-app {
            background-color: var(--surface);
            border-bottom: 1px solid var(--border);
        }
        .navbar-app .navbar-brand {
            color: var(--text-primary);
            font-weight: 700;
        }
        .workspace-badge {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            background: var(--background);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: .5rem .75rem;
        }
        .workspace-badge .workspace-label {
            display: block;
            font-size: .7rem;
            color: var(--text-secondary);
            line-height: 1.1;
        }
        .workspace-badge .workspace-name {
            display: block;
            font-weight: 600;
            font-size: .875rem;
            line-height: 1.2;
        }
        .badge-role {
            background: rgba(79, 70, 229, .1);
            color: var(--primary);
            font-weight: 600;
            font-size: .75rem;
        }
        .navbar-app .nav-link {
            color: var(--text-secondary);
            font-weight: 500;
            padding: .5rem .75rem;
            border-radius: 6px;
        }
        .navbar-app .nav-link:hover,
        .navbar-app .nav-link.active {
            color: var(--primary);
            background: rgba(79, 70, 229, .06);
        }
        .badge-status {
            display: inline-block;
            padding: .35rem .65rem;
            border-radius: 6px;
            font-size: .75rem;
            font-weight: 600;
        }
        .badge-status-active { background: rgba(22, 163, 74, .1); color: var(--success); }
        .badge-status-suspended { background: rgba(220, 38, 38, .1); color: var(--danger); }
        .badge-status-inactive { background: rgba(100, 116, 139, .12); color: var(--text-secondary); }
        .badge-status-pending { background: rgba(217, 119, 6, .12); color: var(--warning); }
        .badge-status-info { background: rgba(2, 132, 199, .1); color: var(--info); }
        .profile-dropdown { position: relative; display: inline-block; }
        .profile-menu {
            display: none;
            position: absolute;
            right: 0;
            top: 100%;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,.08);
            min-width: 180px;
            z-index: 1000;
            margin-top: 0.5rem;
        }
        .profile-dropdown:focus-within .profile-menu { display: block; }
        .profile-menu form button {
            background: none;
            border: none;
            color: var(--text-primary);
            cursor: pointer;
            width: 100%;
            text-align: left;
        }
        .profile-menu form button:hover { background-color: var(--background); }
        .alert { border-width: 1px; }
        .alert-success { background: rgba(22, 163, 74, .08); border-color: rgba(22, 163, 74, .25); color: #14532d; }
        .alert-danger { background: rgba(220, 38, 38, .08); border-color: rgba(220, 38, 38, .25); color: #7f1d1d; }
        .alert-warning { background: rgba(217, 119, 6, .08); border-color: rgba(217, 119, 6, .25); color: #78350f; }
        .alert-info { background: rgba(2, 132, 199, .08); border-color: rgba(2, 132, 199, .25); color: #0c4a6e; }
        .spinner-border { vertical-align: middle; }
    </style>
</head>
<body>
    <nav class="navbar navbar-app navbar-expand-lg sticky-top py-3">
        <div class="container">
            <a href="{{ route('dashboard') }}" class="navbar-brand d-flex align-items-center">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor"
                     viewBox="0 0 16 16" class="me-2" style="color: var(--primary);">
                    <path d="M.5 3 .0 5v2v7c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V7.5v-2L15.5 5v-2a.5.5 0 0 0-.5-.5h-1a.5.5 0 0 0 0 1h.5v2v7a1 1 0 0 1-1 1H2a1 1 0 0 1-1-1V7.5V4.5 3h1a.5.5 0 0 0 0-1H.5z"/>
                    <path d="M2 8h12v7a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V8zm2-6a.5.5 0 0 0 0 1h8a.5.5 0 0 0 0-2H4z"/>
                </svg>
                Payment Orchestrator
            </a>
            <ul class="navbar-nav flex-row gap-1 ms-lg-4">
                <li class="nav-item">
                    <a href="{{ route('dashboard') }}"
                       class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                        Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a href="{{ route('settings.workspace.edit') }}"
                       class="nav-link {{ request()->routeIs('settings.workspace*') ? 'active' : '' }}">
                        Workspace Settings
                    </a>
                </li>
                <li class="nav-item">
                    <a href="{{ route('settings.api-keys.index') }}"
                       class="nav-link {{ request()->routeIs('settings.api-keys*') ? 'active' : '' }}">
                        API Keys
                    </a>
                </li>
            </ul>
            <div class="ms-auto d-flex align-items-center gap-3">
                @if (auth()->check())
                    @if ($currentMerchant ?? null)
                        <div class="workspace-badge d-none d-md-inline-flex">
                            <div>
                                <span class="workspace-label">Current workspace</span>
                                <span class="workspace-name">{{ $currentMerchant->name }}</span>
                            </div>
                        </div>
                    @endif
                    <div class="profile-dropdown">
                        <button type="button" class="btn btn-outline-secondary d-flex align-items-center"
                                style="border-color: var(--border); border-radius: 8px; padding: .5rem .75rem;">
                            <span class="me-2 d-none d-sm-inline">{{ Auth::user()->name }}</span>
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none"
                                 stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                 stroke-linejoin="round" viewBox="0 0 24 24">
                                <polyline points="6 9 12 15 18 9"></polyline>
                            </svg>
                        </button>
                        <div class="profile-menu">
                            <a href="#" class="d-block px-3 py-2 small text-decoration-none">Profile</a>
                            <a href="{{ route('settings.workspace.edit') }}" class="d-block px-3 py-2 small text-decoration-none">Workspace Settings</a>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="d-block w-100 text-start px-3 py-2 small text-decoration-none">
                                    Logout
                                </button>
                            </form>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </nav>
    <div class="container py-4 py-lg-5">
        @yield('content')
    </div>
</body>
</html>
