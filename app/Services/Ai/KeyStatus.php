<?php

namespace App\Services\Ai;

/**
 * Retour du bouton « Vérifier la clé » de Mon profil.
 *
 * Ne contient JAMAIS la clé elle-même : seulement ce que le fournisseur
 * accepte de dire d'elle (libellé, crédit restant, limite).
 */
final class KeyStatus
{
    public function __construct(
        public readonly bool $valid,
        public readonly ?string $label = null,
        public readonly ?string $limitRemaining = null,
        public readonly ?string $usage = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $message = null,
    ) {}

    public static function invalid(string $errorCode, string $message): self
    {
        return new self(valid: false, errorCode: $errorCode, message: $message);
    }

    /** Résumé affichable en notification Filament. */
    public function summary(): string
    {
        if (! $this->valid) {
            return $this->message ?? "Clé refusée par le fournisseur.";
        }

        $parts = [];

        if (filled($this->label)) {
            $parts[] = $this->label;
        }

        if (filled($this->usage)) {
            $parts[] = "utilisé : {$this->usage} \$";
        }

        if (filled($this->limitRemaining)) {
            $parts[] = "crédit restant : {$this->limitRemaining} \$";
        }

        return $parts === [] ? "Clé valide." : implode(' · ', $parts);
    }
}
