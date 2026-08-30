<?php

namespace App\Actions\Merchants;

use App\Models\Merchant;

class UpdateMerchant
{
    /**
     * Update the merchant's editable workspace details.
     *
     * Only the name and slug are updated; the status and metadata
     * attributes are preserved. The caller is responsible for
     * authorization and validation.
     *
     * @param  array{name: string, slug: string}  $data
     */
    public function update(Merchant $merchant, array $data): Merchant
    {
        $merchant->fill([
            'name' => $data['name'],
            'slug' => $data['slug'],
        ])->save();

        return $merchant;
    }
}
