<?php

namespace App\Http\Controllers;

use App\Actions\Audit\GetAuditHealth;
use App\Actions\Dashboard\GetMerchantDashboard;
use App\Services\Merchants\CurrentMerchant;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        protected CurrentMerchant $currentMerchant,
        protected GetMerchantDashboard $dashboard,
        protected GetAuditHealth $auditHealth,
    ) {}

    /**
     * The V1 merchant dashboard.
     *
     * The `merchant` middleware has already established the session
     * merchant context; the dashboard snapshot and the operational audit
     * health are computed by their respective actions (both aggregate-only,
     * fail-safe, and audit-recursion free). The view receives immutable
     * result objects — no business logic lives in Blade.
     */
    public function index(): View
    {
        $merchant = $this->currentMerchant->get();

        // Preserved behavior: a user without any merchant membership still
        // gets a rendered dashboard with its empty state (HTTP 200) — the
        // `merchant` middleware deliberately lets these requests through.
        if ($merchant === null) {
            return view('dashboard', [
                'merchant' => null,
                'dashboard' => null,
                'auditHealth' => null,
            ]);
        }

        return view('dashboard', [
            'merchant' => $merchant,
            'dashboard' => $this->dashboard->execute($merchant),
            'auditHealth' => $this->auditHealth->execute(),
        ]);
    }
}
