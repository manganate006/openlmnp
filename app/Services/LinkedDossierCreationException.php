<?php

namespace App\Services;

use RuntimeException;

/**
 * Échec de création d'un dossier lié, avec un message déjà destiné à l'utilisateur.
 *
 * ⚠️ `$kind` est un code MACHINE (voir constantes `KIND_*`), ajouté pour l'assistant
 * en étapes (écran 5) : il doit savoir, SANS reparser le message, s'il faut proposer
 * un lien « Contacter le support » (`isSupportable()`) ou un bouton « Réessayer »
 * (`isRetryable()`) — repérer ça par une sous-chaîne du message aurait été fragile,
 * et aurait divergé du jour où un libellé change.
 */
final class LinkedDossierCreationException extends RuntimeException
{
    public const KIND_DEMO = 'demo';

    public const KIND_QUOTA = 'quota';

    public const KIND_INVALID_SIREN = 'invalid_siren';

    public const KIND_SIREN_TAKEN = 'siren_taken';

    public const KIND_SIRENE_UNAVAILABLE = 'sirene_unavailable';

    public const KIND_NOT_FOUND = 'not_found';

    public const KIND_WRONG_LEGAL_FORM = 'wrong_legal_form';

    public const KIND_FOREIGN_LEGAL_FORM = 'foreign_legal_form';

    public const KIND_CLOSED = 'closed';

    public function __construct(string $message, public readonly string $kind)
    {
        parent::__construct($message);
    }

    public static function demo(): self
    {
        return new self('Un compte de démonstration ne peut pas créer de dossier lié.', self::KIND_DEMO);
    }

    public static function quotaExceeded(): self
    {
        return new self('Vous avez atteint le nombre de dossiers liés offerts par votre abonnement.', self::KIND_QUOTA);
    }

    public static function invalidSiren(): self
    {
        return new self('Le SIREN doit compter 9 chiffres et passer le contrôle de validité (clé de Luhn).', self::KIND_INVALID_SIREN);
    }

    public static function sirenTaken(): self
    {
        // Message volontairement neutre (décision n°4 du plan) : ne pas révéler à qui
        // appartient déjà ce SIREN.
        return new self('Ce SIREN est déjà suivi sur cette instance. Contactez le support pour un transfert.', self::KIND_SIREN_TAKEN);
    }

    public static function sireneUnavailable(string $reason): self
    {
        return new self($reason, self::KIND_SIRENE_UNAVAILABLE);
    }

    public static function sirenNotFound(): self
    {
        return new self('Aucune entreprise ne porte ce SIREN dans la base Sirene.', self::KIND_NOT_FOUND);
    }

    public static function wrongLegalForm(string $legalForm): self
    {
        if ($legalForm === '2120') {
            return new self(
                'Cette indivision comprend une personne morale (nature juridique 2120) : '
                .'contactez le support pour l\'ouvrir manuellement.',
                self::KIND_FOREIGN_LEGAL_FORM,
            );
        }

        return new self(
            'Ce SIREN n\'est pas celui d\'une indivision entre personnes physiques (nature juridique 2110).',
            self::KIND_WRONG_LEGAL_FORM,
        );
    }

    public static function closed(): self
    {
        return new self('Cette entreprise est déclarée cessée dans la base Sirene.', self::KIND_CLOSED);
    }

    /** Un lien « Contacter le support » aide-t-il pour ce refus ? (écran 5, étape « Sirene»). */
    public function isSupportable(): bool
    {
        return in_array($this->kind, [self::KIND_SIREN_TAKEN, self::KIND_FOREIGN_LEGAL_FORM], true);
    }

    /** Un bouton « Réessayer » (relancer SEULEMENT l'appel réseau) a-t-il un sens ? */
    public function isRetryable(): bool
    {
        return $this->kind === self::KIND_SIRENE_UNAVAILABLE;
    }
}
