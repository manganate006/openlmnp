<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Csv\DossierArchive;
use Illuminate\Console\Command;

/**
 * Relit une archive produite par `openlmnp:export-dossier`.
 *
 * Deux garde-fous, et ils ne sont pas décoratifs :
 *   - `schema_version` : une archive produite par une version plus récente est REFUSÉE,
 *     jamais relue « au mieux » ;
 *   - contrôle d'appartenance : l'archive porte l'e-mail de son propriétaire, et importer
 *     dans un autre compte demande un `--force` explicite.
 *
 * L'import est ADDITIF : il ne remplace rien. Rejouer deux fois la même archive crée deux
 * fois les biens — un dédoublonnage silencieux serait pire, il déciderait à la place de
 * l'utilisateur de ce qui est « le même » bien.
 */
class ImportDossierCommand extends Command
{
    protected $signature = 'openlmnp:import-dossier
                            {email : Compte de destination}
                            {file : Fichier JSON produit par openlmnp:export-dossier}
                            {--force : Importer même si l\'archive appartient à un autre compte}
                            {--dry-run : Contrôler l\'archive sans rien écrire}';

    protected $description = 'Importe un dossier complet depuis une archive JSON versionnée';

    public function handle(DossierArchive $archive): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error("Aucun compte pour {$this->argument('email')}.");

            return self::FAILURE;
        }

        $file = $this->argument('file');

        if (! is_file($file)) {
            $this->error("Fichier introuvable : {$file}");

            return self::FAILURE;
        }

        $payload = json_decode((string) file_get_contents($file), true);

        if (! is_array($payload)) {
            $this->error('Archive illisible : le fichier n\'est pas du JSON valide.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            return $this->report($payload, $user);
        }

        try {
            $counts = $archive->import($user, $payload, (bool) $this->option('force'));
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Dossier importé dans le compte {$user->email}.");
        $this->table(array_keys($counts), [array_values($counts)]);

        $this->newLine();
        $this->line('  Les EXERCICES FISCAUX ne sont pas importés : leurs totaux sont figés et');
        $this->line('  dépendent de la chaîne des reports. Recréez-les depuis l\'application, ils');
        $this->line('  se recalculeront sur les données qui viennent d\'entrer.');

        return self::SUCCESS;
    }

    /** Contrôle sans écriture : version, propriétaire, volumétrie. */
    private function report(array $payload, User $user): int
    {
        $version = $payload['schema_version'] ?? null;
        $owner = $payload['owner']['email'] ?? '(non renseigné)';

        $this->table(['Champ', 'Valeur'], [
            ['Version du format', $version ?? '(absente)'],
            ['Version lisible ici', DossierArchive::SCHEMA_VERSION],
            ['Propriétaire de l\'archive', $owner],
            ['Compte de destination', $user->email],
            ['Exportée le', $payload['exported_at'] ?? '(inconnu)'],
            ['Biens', count($payload['properties'] ?? [])],
        ]);

        if (! is_int($version) || $version > DossierArchive::SCHEMA_VERSION) {
            $this->error('Cette archive ne peut pas être relue par cette version d\'OpenLMNP.');

            return self::FAILURE;
        }

        if ($owner !== $user->email) {
            $this->warn('L\'archive appartient à un autre compte : --force sera exigé.');
        }

        $this->info('Archive lisible. Relancez sans --dry-run pour importer.');

        return self::SUCCESS;
    }
}
