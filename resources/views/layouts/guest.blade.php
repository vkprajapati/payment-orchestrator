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
        :root { --primary:#4f46e5; --primary-hover:#4338ca; --background:#f8fafc; --surface:#fff; --text-primary:#0f172a; --text-secondary:#64748b; --border:#e2e8f0; --success:#16a34a; --danger:#dc2626; }
        * { font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; }
        body { background-color:var(--background); color:var(--text-primary); line-height:1.6; }
        .bg-primary-custom { background:linear-gradient(135deg,#1e293b 0%,#0f172a 100%); }
        .bg-surface { background-color:var(--surface); }
        .form-control:focus { border-color:var(--primary); box-shadow:0 0 0 .25rem rgba(79,70,229,.15); }
        .form-check-input:checked { background-color:var(--primary); border-color:var(--primary); }
        .btn-primary { background:var(--primary); border:1px solid var(--primary); font-weight:500; border-radius:8px; padding:.75rem 1.5rem; width:100%; transition:background .2s,border-color .2s; }
        .btn-primary:hover { background:var(--primary-hover); border-color:var(--primary-hover); }
        .btn-primary:disabled { opacity:.7; cursor:not-allowed; }
        .link-primary:hover { color:var(--primary-hover); text-decoration:underline; }
        .text-primary-custom { color:var(--primary)!important; }
        .text-secondary-custom { color:var(--text-secondary)!important; }
        .feature-icon { width:18px; height:18px; }
    </style>
</head>
<body>
    <div class="container-fluid min-vh-100 p-0">
        <div class="row g-0">
            <div class="col-lg-6 d-none d-lg-flex flex-column bg-primary-custom text-white p-5">
                <div class="mb-4">
                    <div class="d-flex align-items-center">
                        <div class="bg-primary rounded-2 d-inline-flex align-items-center justify-content-center me-2" style="width:36px;height:36px;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16"><path d="M.5 3 .0 5v2v7c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V7.5v-2L15.5 5v-2a.5.5 0 0 0-.5-.5h-1a.5.5 0 0 0 0 1h.5v2v7a1 1 0 0 1-1 1H2a1 1 0 0 1-1-1V7.5V4.5 3h1a.5.5 0 0 0 0-1H.5z"/><path d="M2 8h12v7a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V8zm2-6a.5.5 0 0 0 0 1h8a.5.5 0 0 0 0-2H4z"/></svg>
                        </div>
                        <span class="fs-4 fw-bold">Payment Orchestrator</span>
                    </div>
                </div>
                <h1 class="display-5 fw-bold mb-3">Build reliable payment infrastructure.</h1>
                <p class="fs-6 mb-5 opacity-75" style="max-width:520px;">One API for multiple payment providers. Route, monitor, and manage payments from a single unified platform.</p>
                <div class="mt-auto">
                    <div class="d-flex align-items-start mb-4"><div class="me-3 mt-1"><svg class="feature-icon text-white" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></div><div><h3 class="h6 fw-semibold mb-1 text-white">Unified payment API</h3><p class="small opacity-75 mb-0">A single integration point for all your payment providers.</p></div></div>
                    <div class="d-flex align-items-start mb-4"><div class="me-3 mt-1"><svg class="feature-icon text-white" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l4-4a1 1 0 0 0-1.4-1.4l-4 4v-4a1 1 0 0 0-1-1h-4a1 1 0 0 0 0 2h3.59l-9.59 9.59a1 1 0 0 0 1.41 1.41l9.59-9.59V12h4a1 1 0 0 0 0-2V6z"></path></svg></div><div><h3 class="h6 fw-semibold mb-1 text-white">Provider integrations</h3><p class="small opacity-75 mb-0">Connect to Stripe, PayPal, and dozens of other providers.</p></div></div>
                    <div class="d-flex align-items-start mb-4 mb-lg-0"><div class="me-3 mt-1"><svg class="feature-icon text-white" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s8-6 22-0v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2z"></path><polyline points="12 12 18 18"></polyline><polyline points="12 12 6 18"></polyline></svg></div><div><h3 class="h6 fw-semibold mb-1 text-white">Payment observability</h3><p class="small opacity-75 mb-0">Real-time monitoring, logs, and insights into every transaction.</p></div></div>
                </div>
            </div>
            <div class="col-lg-6 d-flex align-items-center justify-content-center p-4 p-lg-5 bg-surface">
                <div class="w-100" style="max-width:420px;">
                    @yield('content')
                </div>
            </div>
        </div>
    </div>
</body>
</html>