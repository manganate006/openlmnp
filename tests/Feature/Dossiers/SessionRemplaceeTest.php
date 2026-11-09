<?php

use App\Enums\DossierMemberRole;
use App\Models\LinkedDossier;
use App\Models\User;
use App\Support\DossierContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Déconnexion intermittente du test bêta du 2026-10-15 : en deux onglets, « Revenir à mon
 * dossier » finissait 3 fois sur 7 sur /login, jamais en un seul onglet.
 *
 * Cause reproduite ici. Une bascule (`Auth::login()`) change l'identifiant de session :
 * l'ancien (S1) est DÉTRUIT, le nouveau (S2) part dans le cookie de la réponse. Mais une
 * requête de l'autre onglet, partie AVANT que le navigateur reçoive S2, porte encore S1.
 * Laravel ne trouve plus S1, ouvre à la place une session VIDE sous ce même identifiant, et
 * renvoie le cookie S1 avec sa réponse. Si cette réponse arrive après celle de la bascule, le
 * navigateur remplace S2 par S1 : la requête suivante n'est plus authentifiée, d'où /login.
 *
 * Le navigateur est simulé à la main, pour que l'ordre des réponses soit celui du défaut : un
 * seul pot de cookies partagé par les deux onglets, et des requêtes rejouées dans l'ordre où
 * le serveur les traite. Le pilote de session est `file`, comme en production, et chaque
 * requête repart d'un garde et d'une session neufs, comme un processus PHP neuf (sans quoi le
 * harnais garderait l'utilisateur en mémoire d'une requête à l'autre et masquerait le défaut).
 */
function creerDossierPourSession(User $owner): LinkedDossier
{
    $dossierUser = new User;
    $dossierUser->forceFill([
        'name' => 'Indivision Session',
        'email' => 'dossier-'.Str::lower((string) Str::ulid()).'@dossiers.invalid',
        'password' => \Illuminate\Support\Facades\Hash::make(Str::random(32)),
        'is_linked_dossier' => true,
        'is_indivision' => true,
        'siren' => '100000009',
    ])->save();

    $dossier = LinkedDossier::create([
        'public_id' => (string) Str::ulid(),
        'user_id' => $dossierUser->id,
        'owner_id' => $owner->id,
        'kind' => 'indivision',
        'siren' => '100000009',
    ]);

    $dossier->members()->create([
        'user_id' => $owner->id,
        'role' => DossierMemberRole::Owner,
        'accepted_at' => now(),
    ]);

    return $dossier;
}

/** Repart d'un garde et d'une session neufs : ce que voit un processus PHP neuf. */
function processusNeuf(): void
{
    Auth::forgetGuards();
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
}

/** Contenu stocké d'une session, relu depuis le disque. */
function sessionStockee(string $id): array
{
    processusNeuf();
    $store = app('session')->driver();
    $store->setId($id);
    $store->start();

    return $store->all();
}

beforeEach(function () {
    config([
        'dossiers.enabled' => true,
        'services.provisioning.token' => null,
        'dossiers.quota' => null,
        'session.driver' => 'file',
        'session.files' => storage_path('framework/sessions-test-'.Str::random(8)),
    ]);
    File::ensureDirectoryExists(config('session.files'));

    $this->withoutDossierContextHeader();
    $this->cookieName = config('session.cookie');

    /**
     * Une requête du navigateur : part avec le cookie de session du pot COMMUN (ou celui
     * qu'on lui impose, pour une requête partie plus tôt), et ne met le pot à jour que si la
     * réponse renvoie un cookie de session — exactement ce que fait le navigateur.
     */
    $this->navigateur = function (string $method, string $uri, array $data = [], ?string $cookie = null, array $headers = []): TestResponse {
        processusNeuf();
        $this->defaultCookies = [];
        $this->withCookie($this->cookieName, $cookie ?? $this->pot);

        $reponse = $this->call($method, $uri, $data, $this->prepareCookiesForRequest(), [], collect($headers)
            ->mapWithKeys(fn ($v, $k) => ['HTTP_'.strtoupper(str_replace('-', '_', $k)) => $v])
            ->all());

        $nouveau = $reponse->getCookie($this->cookieName);
        if ($nouveau !== null) {
            $this->pot = $nouveau->getValue();
        }

        return $reponse;
    };

    // Session de départ : le principal connecté, comme après la page de connexion.
    $this->principal = User::factory()->create();
    $this->dossier = creerDossierPourSession($this->principal);

    processusNeuf();
    $store = app('session')->driver();
    $store->setId(Str::random(40));
    $store->start();
    $store->put(Auth::guard()->getName(), $this->principal->id);
    $store->put('password_hash_web', $this->principal->getAuthPassword());
    $store->save();
    $this->pot = $store->getId();
});

it('reproduit le scénario : une requête partie de l\'autre onglet avec l\'ancien cookie ne déconnecte plus', function (string $pilote) {
    if ($pilote === 'database') {
        // Pilote par défaut de .env.example. Son gestionnaire garde un drapeau « la ligne
        // existe » partagé par tous les identifiants : écrire la marque sans le remettre à
        // zéro ferait perdre la VRAIE session en fin de requête (voir markSessionReplaced()).
        config(['session.driver' => 'database']);
        processusNeuf();
        $store = app('session')->driver();
        $store->setId(Str::random(40));
        $store->start();
        $store->put(Auth::guard()->getName(), $this->principal->id);
        $store->put('password_hash_web', $this->principal->getAuthPassword());
        $store->save();
        $this->pot = $store->getId();
    }

    $navigateur = $this->navigateur;

    // Onglet 2 : page du principal, puis ouverture du dossier.
    $navigateur('GET', '/')->assertOk();
    $contexte = sessionStockee($this->pot)['dossier']['context'];
    $navigateur('POST', route('filament.admin.dossiers.open', $this->dossier->public_id), ['openlmnp_context' => $contexte])
        ->assertRedirect('/');

    // Onglet 1 : profil du dossier. S1 = la session du dossier.
    $navigateur('GET', '/profile')->assertOk();
    $s1 = $this->pot;
    $contexteDossier = sessionStockee($s1)['dossier']['context'];
    expect(sessionStockee($s1)[Auth::guard()->getName()])->toBe($this->dossier->user_id);

    // Onglet 2 : « Revenir à mon dossier ». S2 part dans le cookie.
    $navigateur('POST', route('filament.admin.dossiers.leave'), ['openlmnp_context' => $contexteDossier])
        ->assertRedirect('/');
    $s2 = $this->pot;
    expect($s2)->not->toBe($s1);

    // Onglet 1 : requête partie AVANT que le navigateur ait reçu S2, donc avec S1, et
    // traitée par le serveur APRÈS la bascule. Sa réponse arrive en dernier.
    $navigateur('GET', '/profile', [], $s1, ['X-Livewire' => '1', 'X-OpenLMNP-Context' => $contexteDossier]);

    // Le pot ne doit pas avoir été ramené à S1 : la session du principal est intacte.
    expect($this->pot)->toBe($s2);

    // Onglet 2 suit sa redirection : tableau de bord du principal, pas /login.
    $navigateur('GET', '/')->assertOk();
    expect(sessionStockee($this->pot)[Auth::guard()->getName()])->toBe($this->principal->id);
})->with(['file', 'database']);

it('la requête restée sur l\'ancienne session reçoit un 419 « changé de dossier », sans rien exécuter', function () {
    $navigateur = $this->navigateur;

    $navigateur('GET', '/')->assertOk();
    $contexte = sessionStockee($this->pot)['dossier']['context'];
    $navigateur('POST', route('filament.admin.dossiers.open', $this->dossier->public_id), ['openlmnp_context' => $contexte]);
    $s1 = $this->pot;

    // La bascule vers le dossier change elle aussi l'identifiant : S0 est remplacée.
    $navigateur('GET', '/profile', [], $s1)->assertOk();
    $contexteDossier = sessionStockee($s1)['dossier']['context'];
    $navigateur('POST', route('filament.admin.dossiers.leave'), ['openlmnp_context' => $contexteDossier]);

    $reponse = $navigateur('POST', route('filament.admin.dossiers.leave'), ['openlmnp_context' => $contexteDossier], $s1);

    $reponse->assertStatus(419)
        ->assertHeader(\App\Exceptions\StaleDossierContextException::HEADER, '1');
    expect($reponse->getCookie($this->cookieName))->toBeNull();
});

it('une requête en cours pendant la bascule ne réécrit pas l\'ancienne session ni le cookie', function () {
    // Variante plus étroite : la requête a LU l'ancienne session avant la bascule, et la
    // bascule se termine pendant qu'elle s'exécute. Simulé par une route qui remplace la
    // session courante pendant la requête, comme le ferait la bascule concurrente.
    Route::middleware('web')->get('/__test-bascule-concurrente', function () {
        DossierContext::markSessionReplaced(session()->getId());
        session()->put('donnee_perimee', 'ne doit pas être réécrite');

        return response('ok');
    });

    $navigateur = $this->navigateur;
    $avant = $this->pot;

    $reponse = $navigateur('GET', '/__test-bascule-concurrente');

    expect($reponse->getCookie($this->cookieName))->toBeNull()
        ->and($this->pot)->toBe($avant)
        ->and(sessionStockee($avant))->not->toHaveKey('donnee_perimee')
        ->and(sessionStockee($avant))->not->toHaveKey(Auth::guard()->getName());
});

it('une connexion ordinaire ne laisse aucune marque sur la session', function () {
    $navigateur = $this->navigateur;

    $reponse = $navigateur('GET', '/')->assertOk();

    expect(sessionStockee($this->pot))->not->toHaveKey(DossierContext::SESSION_REPLACED);
    expect($reponse->getCookie($this->cookieName))->not->toBeNull();
});

it('les deux moitiés encadrent StartSession dans la pile RÉELLEMENT exécutée (panel et Livewire)', function () {
    // `route:list -v` affiche l'ordre déclaré ; seul gatherRouteMiddleware() rend l'ordre trié
    // par la liste de priorité, celui qui s'exécute (voir CLAUDE.md, piège du réordonnancement).
    $router = app('router');
    $routes = [
        $router->getRoutes()->getByName('filament.admin.dossiers.leave'),
        collect($router->getRoutes()->getRoutes())->first(fn ($r) => str_ends_with((string) $r->getName(), 'livewire.update')),
    ];

    foreach ($routes as $route) {
        expect($route)->not->toBeNull();

        $pile = array_values(array_filter(
            $router->gatherRouteMiddleware($route),
            'is_string',
        ));
        $position = fn (string $classe) => array_search($classe, $pile, true);

        $start = $position(\Illuminate\Session\Middleware\StartSession::class);

        expect($start)->not->toBeFalse()
            ->and($position(\App\Http\Middleware\StripReplacedSessionCookie::class))->toBeLessThan($start)
            ->and($position(\App\Http\Middleware\GuardReplacedSession::class))->toBe($start + 1);
    }
});
