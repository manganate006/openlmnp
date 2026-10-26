<?php

namespace App\Console\Commands;

use App\Models\DocumentVault;
use App\Models\User;
use App\Services\Vault\VaultException;
use App\Services\Vault\VaultKeyManager;
use App\Services\Vault\VaultMigrator;
use Illuminate\Console\Command;

/**
 * Chiffre ou déchiffre en masse les fichiers d'un compte, hors interface web.
 *
 * Utile quand le volume dépasse ce qu'une requête HTTP peut absorber (il n'y a
 * aucune file d'attente dans ce projet : tout traitement long tourne dans la
 * requête), et comme unique porte de sortie quand une opération est restée
 * bloquée — `php artisan tinker` est absent des images Docker.
 *
 * L'opération est idempotente : l'état de chaque fichier est lu dans ses
 * premiers octets, jamais en base. La relancer après une interruption reprend
 * exactement où elle s'était arrêtée.
 */
class VaultMigrateCommand extends Command
{
    protected $signature = 'openlmnp:vault-migrate
        {email : Adresse email du compte}
        {--decrypt : Déchiffre les fichiers au lieu de les chiffrer}
        {--dry-run : N\'écrit rien, se contente d\'annoncer ce qui serait fait}
        {--batch=0 : Nombre maximum de fichiers traités en une fois (0 = tous)}
        {--abandon : Débloque un coffre resté en cours d\'opération, sans rien transformer}';

    protected $description = 'Chiffre ou déchiffre les justificatifs d\'un compte avec sa phrase de passe de coffre-fort';

    public function handle(VaultKeyManager $keys, VaultMigrator $migrator): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error("Aucun compte avec l'adresse {$this->argument('email')}.");

            return self::FAILURE;
        }

        $vault = $keys->vaultFor($user);

        if ($vault === null) {
            $this->error("Le coffre-fort n'est pas activé sur ce compte.");

            return self::FAILURE;
        }

        if ($this->option('abandon')) {
            $vault->update(['status' => DocumentVault::STATUS_ACTIVE]);
            $this->warn('Opération abandonnée : les fichiers restent dans leur état actuel.');
            $this->line('L\'arborescence peut être partiellement chiffrée — relancez la commande pour la remettre d\'aplomb.');

            return self::SUCCESS;
        }

        $direction = $this->option('decrypt')
            ? VaultMigrator::DIRECTION_DECRYPT
            : VaultMigrator::DIRECTION_ENCRYPT;

        // Un examen à blanc n'a besoin d'aucune clé : il ne fait que lire les
        // premiers octets de chaque fichier.
        if ($this->option('dry-run')) {
            $report = $migrator->process($user, '', $direction, dryRun: true);

            $this->info('Examen à blanc — ' . $report->summary());
            $this->reportAnomalies($report);

            return self::SUCCESS;
        }

        $passphrase = (string) $this->secret('Phrase de passe du coffre-fort');

        // En déchiffrement, la paire en plus de la DEK : sans elle, tout dépôt postérieur
        // à l'activation (format 2, scellé) resterait chiffré et le coffre impossible à
        // désactiver. Reconstituée pour cette exécution seulement.
        $pair = null;

        try {
            if ($direction === VaultMigrator::DIRECTION_DECRYPT) {
                [$key, $pair] = $keys->unlockForDecryption($user, $passphrase);
            } else {
                $key = $keys->unlock($user, $passphrase);
            }
        } catch (VaultException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $vault->update([
            'status' => $direction === VaultMigrator::DIRECTION_ENCRYPT
                ? DocumentVault::STATUS_ENCRYPTING
                : DocumentVault::STATUS_DECRYPTING,
        ]);

        $report = $migrator->process(
            $user,
            $key,
            $direction,
            (int) $this->option('batch'),
            keyVersion: $vault->key_version,
            sealedPair: $pair,
        );

        $this->info($report->summary());
        $this->reportAnomalies($report);

        if (! $report->isComplete()) {
            $this->warn("Lot terminé : {$report->remaining} fichier(s) restant(s). Relancez la commande pour poursuivre.");

            return self::SUCCESS;
        }

        if ($direction === VaultMigrator::DIRECTION_DECRYPT && $report->failed === 0) {
            $keys->destroy($user);
            $this->info('Coffre-fort désactivé : les fichiers sont restitués à l\'identique.');

            return self::SUCCESS;
        }

        $vault->update(['status' => DocumentVault::STATUS_ACTIVE]);

        return self::SUCCESS;
    }

    private function reportAnomalies(\App\Services\Vault\VaultMigrationReport $report): void
    {
        if ($report->missing > 0) {
            // Une instance montée depuis Laravel 11 en a : la racine du disque
            // `local` a changé et des lignes pointent dans le vide. On les signale
            // sans planter — les réparer est un autre chantier.
            $this->warn("{$report->missing} chemin(s) référencé(s) en base sont introuvables sur le disque :");

            foreach (array_slice($report->missingPaths, 0, 20) as $path) {
                $this->line("  - {$path}");
            }
        }

        foreach ($report->failedPaths as $path) {
            $this->error("Non traité (laissé intact) : {$path}");
        }
    }
}
