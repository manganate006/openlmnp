<?php

namespace App\Support;

/**
 * Noms d'hôte acceptés par l'application (en-tête Host, ou X-Forwarded-Host d'un proxy
 * de confiance). Sans ce filtre, un Host forgé se retrouve dans les liens absolus, dont
 * celui du mail de réinitialisation du mot de passe (GHSA-j4gm-g8m8-93x2, point 4).
 *
 * Le filtre ne s'applique que si APP_URL porte un vrai nom de domaine. Une instance dont
 * APP_URL vaut `localhost` ou une IP (défaut Docker, script LXC) est presque toujours
 * ouverte par l'IP du réseau local : la filtrer lui renverrait une erreur 400 après la
 * mise à jour. Elle garde donc l'ancien comportement, sauf si TRUSTED_HOSTS est réglé.
 *
 * `localhost` et la boucle locale restent toujours acceptés : les sondes de santé
 * (`curl localhost:8090/up` au déploiement) passent par là.
 */
class TrustedHosts
{
    /** Motifs passés à `trustHosts()` — les sous-domaines d'APP_URL sont ajoutés par Laravel. */
    public static function patterns(?string $appUrl, ?string $extra): array
    {
        $extraHosts = array_values(array_filter(array_map('trim', explode(',', (string) $extra))));

        if (in_array('*', $extraHosts, true)) {
            return ['.*'];
        }

        $appHost = (string) parse_url((string) $appUrl, PHP_URL_HOST);

        if ($extraHosts === [] && ! self::isDomainName($appHost)) {
            return ['.*'];
        }

        $hosts = array_merge(['localhost', '127.0.0.1', '[::1]'], $extraHosts);

        return array_values(array_unique(array_map(
            fn (string $host) => '^' . preg_quote(strtolower($host), '{') . '$',
            $hosts,
        )));
    }

    private static function isDomainName(string $host): bool
    {
        $host = trim($host, '[]');

        return $host !== ''
            && strtolower($host) !== 'localhost'
            && filter_var($host, FILTER_VALIDATE_IP) === false;
    }
}
