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
// Fichiers temporaires d'upload : Livewire ne les purge qu'au démarrage d'un upload
// suivant, ce qui laisse une copie en clair indéfiniment si l'utilisateur s'arrête là.
Schedule::command('openlmnp:cleanup-uploads')->daily();

// Journal MCP : purge au-delà de MCP_AUDIT_RETENTION jours (voir McpAuditLog::prunable()).
Schedule::command('model:prune', ['--model' => [\App\Models\McpAuditLog::class, \App\Models\AiChatReport::class]])->daily();

// Archives de justificatifs laissées par l'outil MCP export_documents (lien valable 1 h).
Schedule::command('openlmnp:purge-exports')->daily();
// Relève les millésimes DVF publiés (data.gouv.fr en republie deux par an). Hors requête :
// `DvfClient::years()` ne fait AUCUN appel réseau, l'appel sortant reste déclenché par un clic.
// Sans effet si DVF_ENABLED=false.
Schedule::command('dvf:refresh-years')->weeklyOn(1, '05:00');
// Carte des loyers (ANIL) : QUOTIDIENNE avec --if-missing, pas annuelle — une planification
// purement annuelle laisserait le repère invisible pendant des mois sur toute instance
// déployée entre deux publications. --if-missing DÉCOUVRE automatiquement le millésime
// suivant sur l'API data.gouv.fr (RentMapDiscovery) : table vide → import du millésime de
// repli ; sinon → tente courant+1, mais SEULEMENT à partir du 1er novembre de cette année-là
// (heure de Paris) — le reste de l'année, ce passage quotidien ne fait AUCUN appel réseau.
// Plus besoin de mettre à jour VINTAGE ni les 4 URLs dans le code à chaque nouvelle
// publication (décembre). Un jour de réseau coupé, ou un millésime pas encore publié, ne
// boucle pas : retenté le lendemain, jamais dans la même exécution. Sans effet si
// RENT_MAP_ENABLED=false. Voir RefreshRentMap pour le détail.
Schedule::command('openlmnp:refresh-rent-map --if-missing')->dailyAt('05:30');
// Fichiers déposés pour import (CSV Airbnb, tableurs) et jamais consommés : ils portent les
// noms des voyageurs et les montants. Les pages d'import les suppriment dès l'import terminé,
// cette purge ramasse les aperçus abandonnés en route.
Schedule::command('openlmnp:cleanup-imports')->daily();

// Purge du journal d'usage IA au-delà de AI_USAGE_RETENTION jours.
Schedule::command('openlmnp:ai-usage-cleanup')->daily();
