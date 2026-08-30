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
    </style>
</head>
<body>
    <div class="container-fluid min-vh-100 p-0">
        <div class="container py-4 py-lg-5">
            @yield('content')
        </div>
    </div>
</body>
</html>
