<?php

namespace App\Console\Commands;

use App\Support\DossierEventScrubber;
use Illuminate\Console\Command;

/**
 * Expurge les reliquats du journal des dossiers liés laissés par les suppressions de
 * compte d'avant le correctif RGPD du 2026-10-17 (lignes d'un dossier supprimé,
 * adresses d'invités dont le compte n'existe plus). Idempotente : une seconde passe
 * ne trouve plus rien. La règle elle-même vit dans `DossierEventScrubber`.
 */
class DossierEventsPurgeCommand extends Command
{
    protected $signature = 'openlmnp:dossier-events-purge
        {--dry-run : Compte ce qui serait supprimé ou expurgé, sans rien modifier}';

    protected $description = 'Supprime ou expurge les données personnelles restées dans le journal des dossiers liés après une suppression de compte';

    public function handle(DossierEventScrubber $scrubber): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $report = $scrubber->purgeLeftovers($dryRun);

        $this->line(($dryRun ? 'Simulation, rien n\'est modifié.' : 'Journal des dossiers liés expurgé.'));
        $this->line("Événements de dossiers supprimés : {$report['orphan_events_deleted']}");
        $this->line("Invitations acceptées par un compte supprimé : {$report['invitations_deleted']}");
        $this->line("Événements expurgés (adresse ou compte disparu) : {$report['events_redacted']}");

        return self::SUCCESS;
    }
}
