<?php

use App\Models\User;
use App\Services\OpenData\DvfClient;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Relevé automatique des millésimes DVF.
 *
 * DVF est republié deux fois par an. Le risque n'est pas une panne mais un silence : une liste
 * figée en configuration continue de répondre, simplement avec des données périmées.
 *
 * Le test central est « ne joint jamais le réseau » : c'est la propriété qui s'oublierait à la
 * première « optimisation ». Tout le dispositif repose sur le fait que l'appel sortant part
 * d'un clic explicite — le rendre implicite ici viderait la garantie de sa substance.
 */
beforeEach(function () {
    File::delete(storage_path('app/private/dvf/years.json'));
});

function dvfIndexHtml(array $years): string
{
    $rows = '';
    foreach ($years as $year) {
        $rows .= '<tr><td><a href="/geo-dvf/latest/csv/'.$year.'/">'.$year.'/</a></td><td></td></tr>';
    }

    return '<html><body><h1>Index of /latest/csv/</h1><table><tbody>'
        .'<tr><td><a href="../">..</a></td></tr>'.$rows.'</tbody></table></body></html>';
}

it('reads the published vintages from the directory index', function () {
    Http::fake(['files.data.gouv.fr/*' => Http::response(dvfIndexHtml([2021, 2022, 2023, 2024, 2025, 2026]))]);

    expect(DvfClient::discoverYears())->toBe([2026, 2025, 2024, 2023, 2022, 2021]);
});

it('never reaches the network when listing the vintages', function () {
    Http::fake();

    DvfClient::years();
    $this->actingAs(User::factory()->create());
    $this->get('/properties/create')->assertOk();

    Http::assertNothingSent();
});

it('prefers a stored reading over the configured fallback', function () {
    config(['opendata.dvf.years' => [2021, 2022, 2023]]);
    expect(DvfClient::years())->toBe([2023, 2022, 2021]);

    DvfClient::storeYears([2024, 2025, 2026]);

    expect(DvfClient::years())->toBe([2026, 2025, 2024]);
});

it('keeps the previous reading when the index is unreadable', function () {
    DvfClient::storeYears([2022, 2023, 2024]);

    // Réseau en panne, page de maintenance, et un index à un seul millésime — plus
    // vraisemblablement un format qui a changé qu'un retrait massif de données.
    foreach ([Http::response('', 500), Http::response('<html>Maintenance</html>'), Http::response(dvfIndexHtml([2025]))] as $response) {
        Http::fake(['files.data.gouv.fr/*' => $response]);

        expect(DvfClient::discoverYears())->toBe([]);
        $this->artisan('dvf:refresh-years')->assertFailed();
        expect(DvfClient::years())->toBe([2024, 2023, 2022]);
    }
});

it('records a new vintage and drops one withdrawn upstream', function () {
    DvfClient::storeYears([2021, 2022, 2023, 2024, 2025]);
    Http::fake(['files.data.gouv.fr/*' => Http::response(dvfIndexHtml([2022, 2023, 2024, 2025, 2026]))]);

    $this->artisan('dvf:refresh-years')->assertSuccessful();

    // 2021 disparaît : le proposer enverrait l'utilisateur sur un 404.
    expect(DvfClient::years())->toBe([2026, 2025, 2024, 2023, 2022]);
});

it('stays silent and offline when DVF is disabled', function () {
    // Couper la fonctionnalité doit tout couper — y compris une tâche planifiée qui, elle,
    // sortirait sans que personne ne l'ait demandé.
    config(['opendata.dvf.enabled' => false]);
    Http::fake();

    $this->artisan('dvf:refresh-years')->assertSuccessful();

    Http::assertNothingSent();
});
