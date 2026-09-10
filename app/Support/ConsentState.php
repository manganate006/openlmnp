<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Choix de l'utilisateur en matière de traceurs, lu depuis son cookie.
 *
 * Deux catégories seulement, parce que c'est ce qu'un bandeau peut faire comprendre :
 *  - `analytics` — mesure d'audience (GA4) ;
 *  - `ads` — publicité (identifiants de clic, conversions transmises aux régies).
 *
 * Elles se projettent sur les quatre signaux du Consent Mode v2 : `analytics_storage` d'un
 * côté, `ad_storage` + `ad_user_data` + `ad_personalization` de l'autre.
 *
 * ⚠️ LE DÉFAUT EST LE REFUS. Sans cookie, tout est refusé et {@see self::decided()} rend
 * `false` — ce qui distingue « n'a pas encore répondu » (il faut lui montrer le bandeau) de
 * « a refusé » (il ne faut plus l'importuner). Confondre les deux, c'est soit réafficher le
 * bandeau à quelqu'un qui a déjà tranché, soit ne jamais le montrer.
 *
 * ⚠️ CE COOKIE EST POSÉ PAR LE NAVIGATEUR, en clair, parce que le Consent Mode doit être
 * initialisé AVANT le conteneur GTM — donc avant tout aller-retour serveur. Il figure à ce
 * titre dans les exceptions de chiffrement
 * ({@see \App\Providers\AppServiceProvider::exemptThirdPartyCookiesFromEncryption()}).
 * Sans cette exception, Laravel le mettrait à `null` sans rien signaler.
 *
 * ⚠️ CLASSE VOLONTAIREMENT DUPLIQUÉE depuis la vitrine, au même titre que le moteur DVF :
 * ce dépôt est public et doit rester autonome. Y introduire une dépendance vers un dépôt
 * privé rendrait le logiciel ininstallable pour qui le récupère.
 *
 * ⚠️ Sans conteneur GTM configuré, rien de tout ceci n'existe : une instance auto-hébergée
 * ne charge aucun traceur, donc ne pose aucune question. C'est la même porte
 * (`config('services.gtm.id')`) qui commande le conteneur et le bandeau.
 */
final class ConsentState
{
    public const COOKIE = 'olmnp_consent';

    /**
     * Durée de conservation du choix, en jours.
     *
     * Six mois : la CNIL recommande de ne pas conserver le refus au-delà, afin que la
     * question puisse être reposée, sans pour autant la reposer à chaque visite.
     */
    public const LIFETIME_DAYS = 182;

    /** Préfixe de version. Un format futur pourra cohabiter sans qu'un ancien cookie soit mal lu. */
    private const PREFIX = 'v1.';

    private function __construct(
        public readonly bool $analytics,
        public readonly bool $ads,
        public readonly bool $decided,
    ) {}

    /** Aucun choix exprimé : tout est refusé, et le bandeau doit être montré. */
    public static function undecided(): self
    {
        return new self(analytics: false, ads: false, decided: false);
    }

    public static function fromRequest(Request $request): self
    {
        return self::parse((string) $request->cookie(self::COOKIE));
    }

    /**
     * Lit la valeur « v1.a<0|1>p<0|1> ».
     *
     * Format volontairement scalaire plutôt que JSON : un cookie posé en JavaScript devrait
     * sinon être encodé puis décodé, et une erreur d'encodage se lirait comme un refus.
     * Toute valeur non reconnue retombe sur {@see self::undecided()} — une valeur corrompue
     * ne doit jamais accorder un consentement.
     */
    public static function parse(string $raw): self
    {
        if (preg_match('/^'.preg_quote(self::PREFIX, '/').'a([01])p([01])$/', $raw, $m) !== 1) {
            return self::undecided();
        }

        return new self(analytics: $m[1] === '1', ads: $m[2] === '1', decided: true);
    }

    /** Sérialise pour le cookie. Le JavaScript du bandeau écrit exactement la même chose. */
    public function toCookieValue(): string
    {
        return self::PREFIX.'a'.($this->analytics ? '1' : '0').'p'.($this->ads ? '1' : '0');
    }
}
