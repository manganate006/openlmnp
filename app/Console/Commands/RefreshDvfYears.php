<?php

namespace App\Console\Commands;

use App\Services\OpenData\DvfClient;
use App\Services\OpenData\MarketValueEstimator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Relève les millésimes DVF publiés sur data.gouv.fr.
 *
 * DVF est republié deux fois par an. Tant que la liste vivait en configuration, un nouveau
 * millésime n'était utilisé que si quelqu'un pensait à éditer le fichier — et l'oubli ne se
 * voyait pas : l'estimation continuait de répondre, avec des données périmées.
 *
 * ⚠️ Le relevé se fait ICI, hors requête. `DvfClient::years()` ne doit jamais appeler le
 * réseau : c'est ce qui garantit que le seul appel sortant du logiciel reste déclenché par un
 * clic explicite de l'utilisateur.
 *
 * Ne fait rien si `DVF_ENABLED=false` : couper la fonctionnalité doit tout couper, y compris
 * une tâche planifiée qui, elle, sortirait sans que personne ne l'ait demandé.
 */
class RefreshDvfYears extends Command
{
    protected $signature = 'dvf:refresh-years';

    protected $description = 'Relève les millésimes DVF publiés sur data.gouv.fr';

    public function handle(): int
    {
        if (! MarketValueEstimator::enabled()) {
            $this->comment('Estimation DVF désactivée (DVF_ENABLED=false) — rien à relever.');

            return self::SUCCESS;
        }

        $previous = DvfClient::years();
        $discovered = DvfClient::discoverYears();

        if ($discovered === []) {
            // Pas bloquant : l'estimation continue sur le relevé précédent.
            $this->warn('Index DVF injoignable ou illisible — relevé précédent conservé.');

            return self::FAILURE;
        }

        DvfClient::storeYears($discovered);

        $added = array_diff($discovered, $previous);
        $removed = array_diff($previous, $discovered);

        $this->info('Millésimes DVF : '.implode(', ', $discovered));

        if ($added !== [] || $removed !== []) {
            $this->line('  nouveaux : '.(implode(', ', $added) ?: '—'));
            $this->line('  retirés  : '.(implode(', ', $removed) ?: '—'));
            Log::info('dvf:refresh-years — la liste des millésimes a changé', [
                'added' => array_values($added),
                'removed' => array_values($removed),
                'years' => $discovered,
            ]);
        }

        return self::SUCCESS;
    }
}
