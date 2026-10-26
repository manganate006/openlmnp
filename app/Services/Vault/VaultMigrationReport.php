<?php

namespace App\Services\Vault;

/**
 * Compte rendu d'un lot de migration. Volontairement chiffré (au sens
 * comptable) : la page de réglages comme la commande artisan affichent les
 * mêmes nombres, y compris les anomalies qu'on ne répare pas ici.
 */
class VaultMigrationReport
{
    /** Fichiers effectivement transformés pendant ce lot. */
    public int $processed = 0;

    /** Fichiers déjà dans l'état voulu — la migration est idempotente. */
    public int $skipped = 0;

    /** Fichiers restant à traiter après ce lot. */
    public int $remaining = 0;

    /** Chemins référencés en base mais absents du disque. */
    public int $missing = 0;

    /** Fichiers dont la transformation a échoué (laissés intacts). */
    public int $failed = 0;

    /** @var list<string> */
    public array $missingPaths = [];

    /** @var list<string> */
    public array $failedPaths = [];

    public function isComplete(): bool
    {
        return $this->remaining === 0;
    }

    public function total(): int
    {
        return $this->processed + $this->skipped + $this->remaining;
    }

    public function summary(): string
    {
        $parts = ["traités : {$this->processed}", "déjà à jour : {$this->skipped}", "restants : {$this->remaining}"];

        if ($this->missing > 0) {
            $parts[] = "introuvables : {$this->missing}";
        }

        if ($this->failed > 0) {
            $parts[] = "en échec : {$this->failed}";
        }

        return implode(' · ', $parts);
    }
}
