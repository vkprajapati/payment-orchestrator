<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\PaymentServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    PaymentServiceProvider::class,
];
