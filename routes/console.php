<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Audit retention: prune expired audit events once per day in the
// low-traffic window. The prune action computes its cutoff once per run,
// so events written concurrently are never affected; withoutOverlapping
// merely avoids pointless overlap between consecutive runs on slow days.
Schedule::command('audit:prune')
    ->daily()
    ->at('02:00')
    ->withoutOverlapping();
