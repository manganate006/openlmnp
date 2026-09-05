<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('app:auto-update')->hourly();
// ⚠️ AVANT la purge, pas après : dans l'ordre inverse, le compte serait supprimé avant que
// son rappel ne parte. N'écrit qu'aux bacs à sable ayant laissé une adresse avec consentement.
Schedule::command('openlmnp:demo-expiry-notify')->hourly();
Schedule::command('openlmnp:demo-cleanup')->hourly();
// Check-in anonyme quotidien (télémétrie opt-out) — compte les instances self-hosted.
Schedule::command('app:instance-checkin')->daily();
// Relève les millésimes DVF publiés (data.gouv.fr en republie deux par an). Hors requête :
// `DvfClient::years()` ne fait AUCUN appel réseau, l'appel sortant reste déclenché par un clic.
// Sans effet si DVF_ENABLED=false.
Schedule::command('dvf:refresh-years')->weeklyOn(1, '05:00');
