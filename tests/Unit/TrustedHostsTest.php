<?php

use App\Support\TrustedHosts;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Le middleware TrustHosts se retire pendant les tests (runningUnitTests) : on vérifie donc
 * les motifs eux-mêmes, puis leur effet sur une requête Symfony, comme le ferait le middleware.
 */
function hoteAccepte(array $motifs, string $hote): bool
{
    // Ce que TrustHosts ajoute de lui-même : APP_URL et ses sous-domaines.
    Request::setTrustedHosts([...$motifs, '^(.+\.)?app\.openlmnp\.fr$']);

    try {
        Request::create("http://{$hote}/up")->getHost();

        return true;
    } catch (SuspiciousOperationException) {
        return false;
    } finally {
        Request::setTrustedHosts([]);
    }
}

it('filtre les hôtes quand APP_URL porte un nom de domaine', function () {
    $motifs = TrustedHosts::patterns('https://app.openlmnp.fr', null);

    expect(hoteAccepte($motifs, 'app.openlmnp.fr'))->toBeTrue()
        ->and(hoteAccepte($motifs, 'pirate.example'))->toBeFalse()
        ->and(hoteAccepte($motifs, 'app.openlmnp.fr.pirate.example'))->toBeFalse()
        ->and(hoteAccepte($motifs, '192.168.0.147:8090'))->toBeFalse();
});

it('accepte toujours localhost, que sonde le déploiement', function () {
    $motifs = TrustedHosts::patterns('https://app.openlmnp.fr', null);

    expect(hoteAccepte($motifs, 'localhost:8090'))->toBeTrue()
        ->and(hoteAccepte($motifs, '127.0.0.1:8090'))->toBeTrue();
});

it('ajoute les noms de TRUSTED_HOSTS', function () {
    $motifs = TrustedHosts::patterns('https://app.openlmnp.fr', ' 192.168.0.147 , autre.example ');

    expect(hoteAccepte($motifs, '192.168.0.147:8090'))->toBeTrue()
        ->and(hoteAccepte($motifs, 'autre.example'))->toBeTrue()
        ->and(hoteAccepte($motifs, 'xautre.example'))->toBeFalse();
});

it('ne filtre pas une instance dont APP_URL est localhost ou une IP', function (string $appUrl) {
    expect(hoteAccepte(TrustedHosts::patterns($appUrl, ''), '192.168.1.20'))->toBeTrue();
})->with(['http://localhost:8090', 'http://localhost', 'http://192.168.1.20', 'http://[::1]:8000', '']);

it('coupe le filtre avec TRUSTED_HOSTS=*', function () {
    expect(hoteAccepte(TrustedHosts::patterns('https://app.openlmnp.fr', '*'), 'nimporte.example'))->toBeTrue();
});
