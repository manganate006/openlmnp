<?php

namespace App\Services\Ai\Exceptions;

/**
 * La remise de clé par OAuth n'a pas abouti.
 *
 * Comme toute AiException, le message est un texte français FIXE : il ne
 * contient ni le code d'autorisation, ni la réponse du fournisseur, ni la clé.
 * Chaque cas a sa fabrique pour que l'utilisateur sache quoi faire ensuite —
 * « relancer », « réessayer plus tard » ou « coller sa clé à la main » ne sont
 * pas la même consigne.
 */
final class AiOauthFailed extends AiException
{
    /** L'utilisateur est revenu sans code : il a refusé, ou fermé la fenêtre. */
    public static function cancelled(): self
    {
        return new self("Aucune clé n'a été délivrée : rien n'a changé sur votre compte.");
    }

    /** Aucune demande en cours dans cette session (expirée, ou autre navigateur). */
    public static function noPendingRequest(): self
    {
        return new self(
            "Cette demande de connexion a expiré ou a été ouverte dans un autre navigateur. "
            . "Relancez-la depuis Mon profil."
        );
    }

    /** La demande appartient à un autre compte de cette instance. */
    public static function otherAccount(): self
    {
        return new self("Cette demande de connexion a été ouverte depuis un autre compte. Relancez-la depuis Mon profil.");
    }

    /** Plus de dix minutes se sont écoulées : le code est mort de toute façon. */
    public static function expired(): self
    {
        return new self(
            "Le lien de retour n'est plus valable : il expire au bout de dix minutes. "
            . "Relancez la connexion."
        );
    }

    /** Le fournisseur a refusé le code (déjà consommé, expiré, vérificateur faux). */
    public static function refusedByProvider(): self
    {
        return new self("Le fournisseur a refusé cette demande de connexion. Relancez-la, ou saisissez votre clé à la main.");
    }

    /** Panne réseau ou service indisponible pendant l'échange. */
    public static function unreachable(): self
    {
        return new self(
            "Impossible de contacter le fournisseur pour l'instant. Réessayez dans un instant, "
            . "ou saisissez votre clé à la main."
        );
    }

    /** Réponse inattendue : pas de clé, ou une valeur inexploitable. */
    public static function unusableKey(): self
    {
        return new self("Aucune clé exploitable n'a été reçue. Rien n'a changé sur votre compte.");
    }
}
