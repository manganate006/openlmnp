<?php

namespace App\Console\Commands;

use App\Models\Property;
use App\Models\User;
use App\Support\AiAccess;
use App\Services\Ai\Analysis\AnalysisResult;
use App\Services\Ai\Analysis\DocumentFile;
use App\Services\Ai\DocumentAnalysisService;
use App\Services\Ai\Exceptions\AiException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use FilesystemIterator;
use Throwable;

/**
 * Comparaison de modèles sur un jeu de justificatifs RÉELS.
 *
 * Aucun classement public ne mesure « facture française -> JSON structuré » :
 * cette commande est le seul moyen honnête de choisir un modèle par défaut.
 * Elle rejoue le même corpus sur plusieurs modèles et compare champ par champ
 * avec la vérité terrain.
 *
 * ⚠️ MANUELLE, jamais en intégration continue : elle émet de vrais appels,
 * facturés, avec une vraie clé. Le corpus vit dans `tests/Fixtures/ai/real/`,
 * gitignoré, car il contient des documents personnels.
 *
 * Un fichier par justificatif :
 *   tests/Fixtures/ai/real/facture-edf.pdf
 *   tests/Fixtures/ai/real/facture-edf.json   <- vérité terrain
 *
 * La vérité terrain reprend les clés de AnalysisResult::values :
 *   {"document_type": "expense_invoice",
 *    "values": {"amount": 14320, "expense_date": "2026-02-04", "category": "energy"}}
 */
class AiEvalDocumentsCommand extends Command
{
    protected $signature = 'ai:eval-documents
        {--models= : Slugs à comparer, séparés par des virgules (défaut : le modèle configuré)}
        {--user= : Email du compte sous lequel exécuter (défaut : le premier compte non démo)}
        {--corpus=tests/Fixtures/ai/real : Dossier du jeu d\'évaluation}';

    protected $description = 'Compare plusieurs modèles sur un jeu de justificatifs réels (manuel, facturé, clé requise)';

    public function handle(DocumentAnalysisService $service): int
    {
        if (! config('ai.enabled') || blank(config('ai.api_key'))) {
            $this->error('AI_ENABLED et AI_API_KEY doivent être renseignés : cette commande émet de vrais appels.');

            return self::FAILURE;
        }

        $corpus = base_path((string) $this->option('corpus'));
        $documents = $this->collectDocuments($corpus);

        if ($documents === []) {
            $this->error("Aucun document dans {$corpus}.");
            $this->line('Déposez-y des justificatifs réels et, pour chacun, un .json de vérité terrain.');

            return self::FAILURE;
        }

        $user = $this->resolveUser();

        if ($user === null) {
            return self::FAILURE;
        }

        // Les scopes d'isolation se désactivent hors session : on authentifie
        // explicitement, comme le fait le transport stdio du serveur MCP.
        Auth::setUser($user);

        $property = Property::first();

        if ($property === null) {
            $this->error("Le compte {$user->email} n'a aucun bien : impossible d'analyser.");

            return self::FAILURE;
        }

        $models = $this->resolveModels($user);

        $this->info(sprintf(
            '%d document(s) × %d modèle(s) = %d appels facturés.',
            count($documents),
            count($models),
            count($documents) * count($models),
        ));

        if (! $this->confirm('Lancer ?', false)) {
            return self::SUCCESS;
        }

        $resultats = [];

        foreach ($models as $model) {
            $resultats[$model] = $this->evaluateModel($service, $model, $documents, $property);
        }

        $this->renderSummary($resultats);

        return self::SUCCESS;
    }

    /** @param array<int, string> $documents */
    private function evaluateModel(DocumentAnalysisService $service, string $model, array $documents, Property $property): array
    {
        $this->newLine();
        $this->line("── <fg=cyan>{$model}</>");

        config(['ai.models.documents' => $model]);

        $champsJustes = 0;
        $champsTotal = 0;
        $typesJustes = 0;
        $jsonValide = 0;
        $dureeTotale = 0;

        foreach ($documents as $chemin) {
            $attendu = $this->groundTruth($chemin);

            if ($attendu === null) {
                $this->warn('  ' . basename($chemin) . ' — pas de vérité terrain, ignoré');

                continue;
            }

            $debut = microtime(true);

            try {
                $result = $service->analyze(
                    DocumentFile::fromContents((string) file_get_contents($chemin), basename($chemin)),
                    $property,
                );
                $jsonValide++;
            } catch (AiException|Throwable $e) {
                $this->line('  <fg=red>✗</> ' . basename($chemin) . ' — ' . $e->getMessage());

                continue;
            }

            $dureeTotale += (int) ((microtime(true) - $debut) * 1000);

            [$justes, $total, $ecarts] = $this->compare($result, $attendu);
            $champsJustes += $justes;
            $champsTotal += $total;

            if ($result->documentType === ($attendu['document_type'] ?? null)) {
                $typesJustes++;
            }

            $couleur = $justes === $total ? 'green' : 'yellow';
            $this->line("  <fg={$couleur}>{$justes}/{$total}</> " . basename($chemin)
                . ($ecarts === [] ? '' : ' — ' . implode(', ', $ecarts)));
        }

        return [
            'exactitude' => $champsTotal > 0 ? round($champsJustes / $champsTotal * 100, 1) : 0.0,
            'champs' => "{$champsJustes}/{$champsTotal}",
            'types' => $typesJustes,
            'json_valide' => $jsonValide,
            'latence_ms' => $jsonValide > 0 ? (int) round($dureeTotale / $jsonValide) : 0,
        ];
    }

    /**
     * Compare champ par champ avec la vérité terrain.
     *
     * @return array{0: int, 1: int, 2: array<int, string>}
     */
    private function compare(AnalysisResult $result, array $attendu): array
    {
        $justes = 0;
        $total = 0;
        $ecarts = [];

        // ⚠️ Les LIGNES comptent autant que l'en-tête sur un document multi-lignes :
        // un relevé annuel n'a d'intérêt que par son détail mensuel, un tableau
        // d'amortissement par ses échéances. Ne comparer que `values` reviendrait à
        // déclarer un modèle parfait alors qu'il n'a lu aucune ligne.
        //
        // On compare des AGRÉGATS et non ligne à ligne : le nombre de lignes et la
        // somme des montants disent l'essentiel, sans qu'il faille écrire douze lignes
        // de vérité terrain à la main pour chaque document.
        foreach (['rows_count' => count($result->rows),
                  'rows_amount' => collect($result->rows)->sum('amount')] as $champ => $obtenu) {
            if (! array_key_exists($champ, $attendu)) {
                continue;
            }

            $total++;

            if ((int) $obtenu === (int) $attendu[$champ]) {
                $justes++;

                continue;
            }

            $ecarts[] = "{$champ}: " . var_export($obtenu, true) . ' ≠ ' . var_export($attendu[$champ], true);
        }

        foreach ((array) ($attendu['values'] ?? []) as $champ => $valeur) {
            $total++;
            $obtenu = $result->values[$champ] ?? null;

            if ((string) $obtenu === (string) $valeur) {
                $justes++;

                continue;
            }

            $ecarts[] = "{$champ}: " . var_export($obtenu, true) . ' ≠ ' . var_export($valeur, true);
        }

        return [$justes, $total, $ecarts];
    }

    /**
     * Tous les justificatifs du corpus, sous-dossiers COMPRIS.
     *
     * ⚠️ Un `glob()` plat imposait un corpus à plat, alors qu'un jeu réel est rangé par
     * fournisseur (`_admin/data/electricite-primeo/…`). Le forcer aurait obligé soit à
     * dupliquer les fichiers — des documents personnels, en deux exemplaires — soit à
     * bricoler des liens symboliques. On descend, et la vérité terrain reste à côté de
     * son document, là où elle se relit.
     *
     * @return array<int, string>
     */
    private function collectDocuments(string $corpus): array
    {
        if (! is_dir($corpus)) {
            return [];
        }

        $extensions = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
        $trouves = [];

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($corpus, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($it as $fichier) {
            if ($fichier->isFile() && in_array(strtolower($fichier->getExtension()), $extensions, true)) {
                $trouves[] = $fichier->getPathname();
            }
        }

        // Ordre stable : deux campagnes doivent se comparer ligne à ligne.
        sort($trouves);

        return $trouves;
    }

    private function groundTruth(string $chemin): ?array
    {
        $json = preg_replace('/\.[^.]+$/', '.json', $chemin);

        if ($json === null || ! is_file($json)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($json), associative: true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Modèles à comparer : ceux passés en option, ou le modèle EFFECTIF de
     * l'instance — qui peut venir du fournisseur configuré plutôt que d'une
     * variable d'environnement.
     *
     * @return array<int, string>
     */
    private function resolveModels(User $user): array
    {
        $option = (string) $this->option('models');

        if (blank($option)) {
            return [AiAccess::for($user)->model('documents')];
        }

        return array_values(array_filter(array_map('trim', explode(',', $option))));
    }

    private function resolveUser(): ?User
    {
        $email = $this->option('user');

        if (filled($email)) {
            $user = User::where('email', $email)->first();

            if ($user === null) {
                $this->error("Aucun compte pour {$email}.");
            }

            return $user;
        }

        $user = User::where('is_demo', false)
            ->where('email', '!=', config('demo.email'))
            ->first();

        if ($user === null) {
            $this->error('Aucun compte réel sur cette instance : précisez --user.');
        }

        return $user;
    }

    private function renderSummary(array $resultats): void
    {
        $this->newLine();
        $this->info('Résultats');

        $this->table(
            ['Modèle', 'Exactitude', 'Champs justes', 'Types justes', 'JSON valide', 'Latence moy.'],
            collect($resultats)->map(fn (array $r, string $model) => [
                $model,
                $r['exactitude'] . ' %',
                $r['champs'],
                $r['types'],
                $r['json_valide'],
                $r['latence_ms'] . ' ms',
            ])->values()->all(),
        );

        $this->newLine();
        $this->line('Coût réel de la campagne : page Administration → Intelligence artificielle.');
        $this->line('Critère de bascule : plus de 5 points d\'écart d\'exactitude justifient de changer');
        $this->line('AI_MODEL_DOCUMENTS — une variable d\'environnement, sans déploiement.');
    }
}
