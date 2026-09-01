<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Audit two-stage retention lifecycle, run once per day in the
// low-traffic window, in order:
//   01:00  audit:archive — soft-delete (archive) active events older than
//                          the retention cutoff.
//   02:00  audit:prune    — permanently delete archived events older than
//                          the prune cutoff (a full additional window past
//                          their archive time — never the same day they
//                          were archived).
// Both actions compute their cutoff once per run, so events written
// concurrently are never affected; withoutOverlapping merely avoids
// pointless overlap between consecutive runs on slow days.
Schedule::command('audit:archive')
    ->daily()
    ->at('01:00')
    ->withoutOverlapping();

Schedule::command('audit:prune')
    ->daily()
    ->at('02:00')
    ->withoutOverlapping();
