<?php

namespace App\Services\Ai\Exceptions;

/**
 * La configuration d'IA de l'instance est inexploitable : fournisseur inconnu,
 * ou fournisseur sans adresse.
 *
 * Cette erreur est VOLONTAIREMENT bruyante. Retomber en silence sur OpenRouter
 * parce qu'AI_PROVIDER contient une faute de frappe enverrait les justificatifs
 * d'un utilisateur ailleurs que là où l'administrateur croit les envoyer.
 */
final class AiMisconfigured extends AiException
{
    public static function unknownProvider(string $key): self
    {
        return new self(
            "Le fournisseur d'intelligence artificielle « {$key} » est inconnu : "
            . "vérifiez la variable AI_PROVIDER de cette instance."
        );
    }

    public static function missingBaseUrl(string $key): self
    {
        return new self(
            "Le fournisseur d'intelligence artificielle « {$key} » n'a pas d'adresse : "
            . "renseignez la variable AI_BASE_URL de cette instance."
        );
    }
}
