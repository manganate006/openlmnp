<?php

namespace App\Console\Commands;

use App\Models\RentMapIndicator;
use App\Services\OpenData\RentMapDiscovery;
use App\Services\OpenData\RentMapSource;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Télécharge et importe la « Carte des loyers » (ANIL, data.gouv.fr) : un repère de loyer
 * d'annonce par commune, pour les 4 types de bien publiés par la source.
 *
 * ⚠️ Contrairement à `dvf:refresh-years`, cette commande FAIT un appel réseau non déclenché
 * par un clic — c'est voulu : planifiée hors requête, elle ne révèle jamais rien d'un
 * utilisateur (elle télécharge le jeu entier, pas une commune choisie).
 * `RENT_MAP_ENABLED=false` la coupe entièrement, y compris planifiée.
 *
 * Deux modes bien distincts :
 *
 * — **Sans option** : télécharge et remplace les 4 types depuis les URLs FIGÉES dans
 *   `RentMapSource::TYPES`, au millésime `RentMapSource::VINTAGE` — un rafraîchissement manuel
 *   de secours, type par type, tolérant (un téléchargement en échec laisse ce seul type
 *   inchangé, les 3 autres sont quand même tentés).
 *
 * — **`--if-missing`** (planification QUOTIDIENNE, 2026-09-30) : DÉCOUVRE automatiquement le
 *   millésime suivant sur l'API data.gouv.fr plutôt que d'exiger un relevé manuel des 4 URLs à
 *   chaque nouvelle publication (décembre). Le millésime de référence est celui RÉELLEMENT en
 *   base (`RentMapIndicator::currentVintage()`), pas une constante :
 *     - table VIDE (instance neuve) → import du millésime de repli `RentMapSource::VINTAGE` ;
 *       découverte tentée d'abord, URLs figées seulement si l'API ne répond pas — une instance
 *       neuve reste fonctionnelle même si data.gouv.fr est en panne ;
 *     - table à jour → tente le millésime SUIVANT (courant + 1), mais SEULEMENT à partir du
 *       1er novembre de cette année-là (heure de Paris) — avant cette date, AUCUN appel réseau :
 *       la source republie en décembre, interroger l'API tous les jours de l'année ne servirait
 *       qu'à solliciter data.gouv.fr pour rien ;
 *     - jeu suivant introuvable (pas encore publié) → rien ne change, retenté le lendemain ;
 *     - jeu trouvé → l'EN-TÊTE des 4 CSV est vérifié AVANT tout remplacement (`headerMatches()`) ;
 *       si un seul ne correspond pas au format attendu, rien n'est remplacé et un avertissement
 *       clair est journalisé — mieux vaut un millésime ancien qu'une table à moitié réécrite ;
 *     - sinon, les 4 types sont remplacés dans UNE SEULE transaction (delete puis insert par
 *       type, les 4 d'affilée) : soit elle committe et le nouveau millésime est intégralement en
 *       place, soit elle échoue et l'ancien reste intact — jamais d'état intermédiaire visible.
 *
 * Usage : php artisan openlmnp:refresh-rent-map [--dry-run] [--if-missing]
 */
class RefreshRentMap extends Command
{
    protected $signature = 'openlmnp:refresh-rent-map
        {--dry-run : Télécharger et vérifier le format, sans rien écrire en base}
        {--if-missing : Découvrir et importer le millésime suivant si celui en base est dépassé (sinon ne fait rien)}';

    protected $description = "Télécharge et importe la « Carte des loyers » (ANIL) : un repère de loyer d'annonce non meublé par commune";

    /** Lignes insérées par lot — un compromis entre le nombre de requêtes SQL et la mémoire. */
    private const CHUNK_SIZE = 500;

    /**
     * Budget du téléchargement d'UN CSV (plusieurs Mo, ~35 000 lignes) — distinct du timeout
     * de découverte (`opendata.rent_map.timeout`), qui ne porte que sur le petit appel JSON.
     */
    private const DOWNLOAD_TIMEOUT = 300;

    public function handle(): int
    {
        if (! RentMapSource::enabled()) {
            $this->comment('Carte des loyers désactivée (RENT_MAP_ENABLED=false) — rien à importer.');

            return self::SUCCESS;
        }

        if ($this->option('if-missing')) {
            return $this->handleIfMissing();
        }

        return $this->downloadAndImportTolerant(
            array_map(fn (array $meta) => $meta['url'], RentMapSource::TYPES),
            RentMapSource::VINTAGE,
        );
    }

    /**
     * `--if-missing` : table vide → bootstrap sur le millésime de repli ; sinon → tente le
     * millésime suivant, sous condition de date. Voir le docblock de classe pour le détail.
     */
    private function handleIfMissing(): int
    {
        $currentVintage = RentMapIndicator::currentVintage();

        if ($currentVintage === null) {
            return $this->bootstrap();
        }

        return $this->tryNextVintage($currentVintage);
    }

    /**
     * Table vide (instance neuve, ou jamais encore importée avec succès) : importe le
     * millésime de repli, en essayant D'ABORD la découverte — les URLs figées dans le code ne
     * servent que si l'API data.gouv.fr ne répond pas.
     */
    private function bootstrap(): int
    {
        $this->line('Carte des loyers : table vide — import du millésime de repli '.RentMapSource::VINTAGE.'.');

        $urls = RentMapDiscovery::discoverUrls(RentMapSource::VINTAGE);

        if ($urls === null) {
            $this->comment('  API data.gouv.fr injoignable, ou millésime introuvable — repli sur les URLs figées dans le code.');
            $urls = array_map(fn (array $meta) => $meta['url'], RentMapSource::TYPES);
        }

        return $this->downloadAndImportTolerant($urls, RentMapSource::VINTAGE);
    }

    /**
     * Table déjà importée : tente le millésime suivant, seulement à partir du 1er novembre de
     * cette année-là (heure de Paris). Le reste de l'année, AUCUN appel réseau.
     */
    private function tryNextVintage(int $currentVintage): int
    {
        $candidate = $currentVintage + 1;

        if (! $this->discoveryWindowOpen($candidate)) {
            $this->comment("Carte des loyers : millésime {$currentVintage} en base ; {$candidate} pas encore recherché (à partir du 1er novembre {$candidate}, heure de Paris).");

            return self::SUCCESS;
        }

        $this->line("Carte des loyers : recherche du millésime {$candidate} sur data.gouv.fr...");
        $urls = RentMapDiscovery::discoverUrls($candidate);

        if ($urls === null) {
            $this->comment("  Millésime {$candidate} introuvable ou incomplet — {$currentVintage} conservé, nouvel essai demain.");

            return self::SUCCESS;
        }

        return $this->replaceVintage($candidate, $urls, $currentVintage);
    }

    /** Vrai à partir du 1er novembre de `$candidateVintage`, heure de Paris. */
    private function discoveryWindowOpen(int $candidateVintage): bool
    {
        $opensOn = Carbon::create($candidateVintage, 11, 1, 0, 0, 0, 'Europe/Paris');

        return Carbon::now('Europe/Paris')->greaterThanOrEqualTo($opensOn);
    }

    /**
     * Télécharge les 4 CSV d'un millésime DÉCOUVERT, vérifie leur en-tête AVANT tout
     * remplacement, puis remplace les 4 types en une seule transaction. Ne touche à la base
     * QUE si les 4 téléchargements ET les 4 en-têtes sont valides.
     *
     * @param  array<string, string>  $urls  clé = RentMapSource::TYPES, valeur = URL du CSV
     */
    private function replaceVintage(int $vintage, array $urls, int $previousVintage): int
    {
        $tmpFiles = [];

        foreach ($urls as $type => $url) {
            $tmp = storage_path('app/private/rent-map/discovery-'.$type.'.csv');
            if (! is_dir(dirname($tmp))) {
                @mkdir(dirname($tmp), 0775, true);
            }

            try {
                Http::timeout(self::DOWNLOAD_TIMEOUT)->sink($tmp)->get($url)->throw();
            } catch (\Throwable $e) {
                $this->warn("  Téléchargement du millésime {$vintage} ({$type}) échoué : {$e->getMessage()} — {$previousVintage} conservé.");
                Log::warning('openlmnp:refresh-rent-map — téléchargement du nouveau millésime échoué', [
                    'vintage' => $vintage,
                    'type' => $type,
                    'error' => $e->getMessage(),
                ]);
                @unlink($tmp);
                $this->cleanupTmpFiles($tmpFiles);

                return self::FAILURE;
            }

            $tmpFiles[$type] = $tmp;
        }

        foreach ($tmpFiles as $type => $path) {
            if (! RentMapSource::headerMatches($path)) {
                $label = RentMapSource::TYPES[$type]['label'] ?? $type;
                $this->warn("  Format inattendu pour « {$label} » dans le millésime {$vintage} — rien n'est remplacé, {$previousVintage} conservé.");
                Log::warning('openlmnp:refresh-rent-map — en-tête inattendu, millésime conservé', [
                    'vintage' => $vintage,
                    'type' => $type,
                    'previous_vintage' => $previousVintage,
                ]);
                $this->cleanupTmpFiles($tmpFiles);

                return self::SUCCESS;
            }
        }

        $total = 0;
        DB::transaction(function () use ($tmpFiles, $vintage, &$total): void {
            foreach ($tmpFiles as $type => $path) {
                DB::table('rent_map_indicators')->where('property_type', $type)->delete();

                $batch = [];
                $now = now();

                foreach (RentMapSource::rows($path) as $row) {
                    $batch[] = $row + [
                        'property_type' => $type,
                        'vintage' => $vintage,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    $total++;

                    if (count($batch) >= self::CHUNK_SIZE) {
                        DB::table('rent_map_indicators')->insert($batch);
                        $batch = [];
                    }
                }

                if ($batch !== []) {
                    DB::table('rent_map_indicators')->insert($batch);
                }
            }
        });

        $this->cleanupTmpFiles($tmpFiles);
        $this->info("Carte des loyers : millésime {$vintage} importé ({$total} ligne(s) au total) — {$previousVintage} remplacé.");

        return self::SUCCESS;
    }

    /**
     * Rafraîchissement TOLÉRANT type par type : chaque téléchargement/import en échec laisse
     * les lignes déjà en base pour ce type INCHANGÉES plutôt que de les supprimer, et les 3
     * autres types sont quand même tentés. Utilisé sans option (URLs figées) et par
     * `bootstrap()` (table vide, URLs découvertes ou, à défaut, figées).
     *
     * @param  array<string, string>  $urls  clé = RentMapSource::TYPES, valeur = URL du CSV
     */
    private function downloadAndImportTolerant(array $urls, int $vintage): int
    {
        $totalImported = 0;
        $anyFailure = false;

        foreach ($urls as $type => $url) {
            $label = RentMapSource::TYPES[$type]['label'] ?? $type;

            $this->line("Téléchargement — {$label}...");

            $tmp = storage_path('app/private/rent-map/'.$type.'.csv');
            if (! is_dir(dirname($tmp))) {
                @mkdir(dirname($tmp), 0775, true);
            }

            try {
                Http::timeout(self::DOWNLOAD_TIMEOUT)->sink($tmp)->get($url)->throw();
            } catch (\Throwable $e) {
                $this->warn("  {$label} : {$e->getMessage()} — ignoré, les lignes déjà en base ne sont pas touchées.");
                Log::warning('openlmnp:refresh-rent-map — téléchargement échoué', [
                    'type' => $type,
                    'error' => $e->getMessage(),
                ]);
                $anyFailure = true;
                @unlink($tmp);

                continue;
            }

            try {
                if ($this->option('dry-run')) {
                    $count = iterator_count(RentMapSource::rows($tmp));
                    $this->line("  {$label} : {$count} ligne(s) lues (dry-run, rien d'écrit).");
                    @unlink($tmp);

                    continue;
                }

                $count = $this->importType($type, $vintage, $tmp);
            } catch (\Throwable $e) {
                $this->warn("  {$label} : format illisible ({$e->getMessage()}) — les lignes déjà en base ne sont pas touchées.");
                Log::warning('openlmnp:refresh-rent-map — import échoué', [
                    'type' => $type,
                    'error' => $e->getMessage(),
                ]);
                $anyFailure = true;
                @unlink($tmp);

                continue;
            }

            $totalImported += $count;
            $this->info("  {$label} : {$count} commune(s) importée(s).");
            @unlink($tmp);
        }

        if ($this->option('dry-run')) {
            return $anyFailure ? self::FAILURE : self::SUCCESS;
        }

        $this->info("Carte des loyers {$vintage} : {$totalImported} ligne(s) au total.");

        return $anyFailure ? self::FAILURE : self::SUCCESS;
    }

    /** Remplace les lignes d'UN type de bien, dans une transaction — jamais d'état à moitié écrit. */
    private function importType(string $type, int $vintage, string $path): int
    {
        $count = 0;

        DB::transaction(function () use ($type, $vintage, $path, &$count): void {
            DB::table('rent_map_indicators')->where('property_type', $type)->delete();

            $batch = [];
            $now = now();

            foreach (RentMapSource::rows($path) as $row) {
                $batch[] = $row + [
                    'property_type' => $type,
                    'vintage' => $vintage,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $count++;

                if (count($batch) >= self::CHUNK_SIZE) {
                    DB::table('rent_map_indicators')->insert($batch);
                    $batch = [];
                }
            }

            if ($batch !== []) {
                DB::table('rent_map_indicators')->insert($batch);
            }
        });

        return $count;
    }

    /** @param  array<string, string>  $tmpFiles */
    private function cleanupTmpFiles(array $tmpFiles): void
    {
        foreach ($tmpFiles as $path) {
            @unlink($path);
        }
    }
}
