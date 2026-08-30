<?php

namespace App\Http\Controllers;

use App\Actions\Merchants\UpdateMerchant;
use App\Http\Requests\UpdateMerchantRequest;
use App\Services\Merchants\CurrentMerchant;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class MerchantSettingsController extends Controller
{
    public function __construct(
        protected CurrentMerchant $currentMerchant,
        protected UpdateMerchant $updateMerchant,
    ) {}

    /**
     * Show the workspace settings form for the current merchant.
     */
    public function edit(): View
    {
        $merchant = $this->currentMerchant->get();

        abort_if($merchant === null, 404, 'No workspace is available.');

        $this->authorize('update', $merchant);

        return view('settings.workspace', compact('merchant'));
    }

    /**
     * Update the current merchant's workspace settings.
     */
    public function update(UpdateMerchantRequest $request): RedirectResponse
    {
        $merchant = $this->currentMerchant->get();

        abort_if($merchant === null, 404, 'No workspace is available.');

        $this->updateMerchant->update($merchant, $request->validated());

        return redirect()
            ->route('settings.workspace.edit')
            ->with('status', 'Workspace settings updated successfully.');
    }
}
