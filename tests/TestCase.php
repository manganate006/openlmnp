<?php

namespace Tests;

use App\Support\DossierContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Vieil onglet (voir App\Http\Middleware\VerifyDossierContext) : ce garde-fou est
     * un vrai middleware de requête, pas court-circuité en test comme l'est
     * `PreventRequestForgery` (`runningUnitTests()`) — faire autrement reviendrait à
     * ne RIEN prouver sur ce correctif précis. Mais des dizaines de tests existants
     * (`$this->post(route('filament.admin.dossiers.open', ...))`, etc.) ignorent tout
     * de cet en-tête : plutôt que de les retoucher un par un, CHAQUE requête de test
     * porte automatiquement l'identifiant de contexte COURANT de la session — exactement
     * ce qu'une page fraîchement rendue aurait porté. Un test qui veut au contraire
     * PROUVER le rejet (vieil onglet) passe un en-tête explicite (il prime, voir
     * `call()` ci-dessous) ou appelle `withoutDossierContextHeader()`.
     */
    private bool $withoutDossierContextHeader = false;

    /** Supprime l'injection automatique pour les requêtes suivantes — scénario « sans en-tête ». */
    public function withoutDossierContextHeader(): static
    {
        $this->withoutDossierContextHeader = true;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $cookies
     * @param  array<string, mixed>  $files
     * @param  array<string, mixed>  $server
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        if (
            ! $this->withoutDossierContextHeader
            && ! array_key_exists('HTTP_X_OPENLMNP_CONTEXT', $server)
        ) {
            $server['HTTP_X_OPENLMNP_CONTEXT'] = DossierContext::contextId();
        }

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}
