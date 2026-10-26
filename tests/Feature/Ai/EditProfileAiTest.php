<?php

use App\Filament\Pages\EditProfile;
use App\Models\User;
use App\Services\Ai\Providers\AiProviders;
use App\Services\Ai\Providers\ModelCatalog;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

const CLE_PROFIL = 'sk-or-v1-cle-du-profil-secrete-1234';
const BASE_PROFIL = 'https://openrouter.test/api/v1';

beforeEach(function () {
    config([
        'ai.enabled' => true,
        'ai.api_key' => null,
        'ai.allow_user_keys' => true,
        // Épinglé : l'hôte de test n'est pas openrouter.ai, la détection
        // automatique le classerait donc en serveur local.
        'ai.provider' => 'openrouter',
        'ai.base_url' => BASE_PROFIL,
        'demo.email' => 'demo@openlmnp.fr',
    ]);

    $this->user = User::factory()->create(['timezone' => 'Europe/Paris']);
    $this->actingAs($this->user);
});

// === Visibilité de la section ===

it('masque toute la section quand l\'IA est désactivée', function () {
    config(['ai.enabled' => false]);

    $this->get('/profile')
        ->assertOk()
        ->assertDontSee('Intelligence artificielle');
});

it('affiche la section quand l\'IA est activée', function () {
    $this->get('/profile')
        ->assertOk()
        ->assertSee('Intelligence artificielle')
        ->assertSee('Aucune clé enregistrée.');
});

it('masque le champ de clé quand l\'instance fournit la sienne', function () {
    // Cas du cloud : l'utilisateur ne doit jamais voir de champ clé.
    config(['ai.api_key' => 'sk-or-v1-instance', 'ai.allow_user_keys' => false]);

    $this->get('/profile')
        ->assertOk()
        ->assertSee('Intelligence artificielle')
        ->assertDontSee('Clé OpenRouter')
        ->assertDontSee('sk-or-v1-instance');
});

it('masque le champ de clé quand les clés utilisateur sont interdites', function () {
    config(['ai.allow_user_keys' => false]);

    $this->get('/profile')
        ->assertOk()
        ->assertDontSee('Clé OpenRouter');
});

// === Bouton « Se connecter avec OpenRouter » ===

it('propose la connexion en un clic chez un fournisseur qui remet une clé', function () {
    $this->get('/profile')
        ->assertOk()
        ->assertSee('connectOpenRouter')
        ->assertSee('Se connecter avec OpenRouter')
        // La saisie manuelle reste disponible : elle est le seul recours pour
        // un serveur local, une passerelle d'entreprise ou une panne.
        ->assertSee('Clé OpenRouter')
        ->assertSee('saisir une clé existante ci-dessous', escape: false);
});

it('masque le bouton là où la remise de clé n\'a pas lieu d\'être', function (array $config) {
    config($config);

    // On vise le nom de l'action, et non son libellé : la fiche d'aide
    // contextuelle, rendue sur toutes les pages, décrit elle aussi le bouton.
    // Chercher « Se connecter avec OpenRouter » dans la page entière le
    // trouverait donc même en l'absence de bouton.
    $this->get('/profile')
        ->assertOk()
        ->assertDontSee('connectOpenRouter');
})->with([
    'remise de clé désactivée' => [['ai.oauth_enabled' => false]],
    'clé fournie par l\'instance' => [['ai.api_key' => 'sk-or-v1-instance']],
    'serveur local' => [['ai.provider' => 'auto', 'ai.base_url' => 'http://ollama:11434/v1']],
]);

it('annonce le remplacement quand une clé est déjà enregistrée', function () {
    // L'ancienne clé n'est pas révoquée chez le fournisseur : le modal le dit,
    // et le libellé du bouton ne laisse pas croire à un simple ajout.
    $this->user->update(['ai_api_key' => CLE_PROFIL]);

    $this->get('/profile')
        ->assertOk()
        ->assertSee('Remplacer par une nouvelle clé OpenRouter');
});

it('renvoie vers la route de départ au clic', function () {
    // Le bouton ne part pas chez le fournisseur lui-même : il redirige vers
    // notre route, qui pose le vérificateur en session AVANT de sortir. Un
    // lien direct sauterait cette étape, donc toute la sécurité du procédé.
    Livewire::test(EditProfile::class)
        ->callAction(TestAction::make('connectOpenRouter')->schemaComponent())
        ->assertRedirect(route('filament.admin.ai.openrouter.start'));
});

it('nomme le fournisseur configuré, pas OpenRouter en dur', function () {
    // Sur un serveur local, « clé d'API » : lui parler d'OpenRouter n'aurait
    // aucun sens, et c'est précisément ce que faisait l'ancien libellé.
    config(['ai.provider' => 'auto', 'ai.base_url' => 'http://ollama:11434/v1']);

    $this->get('/profile')
        ->assertOk()
        ->assertSee('Clé d\'API', escape: false)
        ->assertDontSee('Clé OpenRouter');
});

// === ⚠️ La clé n'est jamais renvoyée ===

it('ne renvoie jamais la clé enregistrée dans la page', function () {
    $this->user->update(['ai_api_key' => CLE_PROFIL]);

    $response = $this->get('/profile')->assertOk();

    $response->assertDontSee(CLE_PROFIL, false);
    // Seuls les 4 derniers caractères sont montrés.
    $response->assertSee('se termine par …1234');
});

it('ne charge pas la clé dans l\'état du composant', function () {
    $this->user->update(['ai_api_key' => CLE_PROFIL]);

    $component = Livewire::test(EditProfile::class);

    expect($component->get('data')['ai_api_key'] ?? null)->toBeNull()
        ->and(json_encode($component->get('data')))->not->toContain(CLE_PROFIL);
});

// === Enregistrement ===

it('enregistre une nouvelle clé', function () {
    Livewire::test(EditProfile::class)
        ->fillForm(['ai_api_key' => CLE_PROFIL])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->user->fresh()->ai_api_key)->toBe(CLE_PROFIL);
});

it('retire la clé de l\'état du composant une fois enregistrée', function () {
    $component = Livewire::test(EditProfile::class)
        ->fillForm(['ai_api_key' => CLE_PROFIL])
        ->call('save')
        ->assertHasNoFormErrors();

    // Sinon la clé en clair resterait dans le snapshot Livewire du DOM et
    // repartirait avec chaque requête suivante de la page.
    expect($component->get('data')['ai_api_key'] ?? null)->toBeNull()
        ->and(json_encode($component->get('data')))->not->toContain(CLE_PROFIL)
        ->and($this->user->fresh()->ai_api_key)->toBe(CLE_PROFIL);
});

it('refuse une clé anormalement longue', function () {
    Livewire::test(EditProfile::class)
        ->fillForm(['ai_api_key' => str_repeat('k', 256)])
        ->call('save')
        ->assertHasFormErrors(['ai_api_key']);
});

it('n\'écrase pas la clé quand le champ est laissé vide', function () {
    $this->user->update(['ai_api_key' => CLE_PROFIL]);

    Livewire::test(EditProfile::class)
        ->fillForm(['name' => 'Nouveau nom', 'ai_api_key' => ''])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->user->fresh()->name)->toBe('Nouveau nom')
        ->and($this->user->fresh()->ai_api_key)->toBe(CLE_PROFIL);
});

it('supprime la clé à la demande', function () {
    $this->user->update(['ai_api_key' => CLE_PROFIL]);

    Livewire::test(EditProfile::class)->callAction(TestAction::make('deleteAiKey')->schemaComponent());

    expect($this->user->fresh()->ai_api_key)->toBeNull();
});

// === Vérification de la clé ===

it('vérifie la clé enregistrée sans jamais l\'afficher', function () {
    $this->user->update(['ai_api_key' => CLE_PROFIL]);

    Http::fake([BASE_PROFIL . '/auth/key' => Http::response([
        'data' => ['label' => 'clé de Jérémie', 'usage' => 0.5, 'limit_remaining' => 9.5],
    ])]);

    Livewire::test(EditProfile::class)
        ->callAction(TestAction::make('checkAiKey')->schemaComponent())
        ->assertNotified();

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer ' . CLE_PROFIL));
});

it('vérifie la clé fraîchement saisie plutôt que celle enregistrée', function () {
    $this->user->update(['ai_api_key' => 'sk-or-v1-ancienne-cle']);

    Http::fake([BASE_PROFIL . '/auth/key' => Http::response(['data' => ['label' => 'nouvelle']])]);

    Livewire::test(EditProfile::class)
        ->fillForm(['ai_api_key' => CLE_PROFIL])
        ->callAction(TestAction::make('checkAiKey')->schemaComponent());

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer ' . CLE_PROFIL));
    // La clé saisie n'est pas enregistrée pour autant.
    expect($this->user->fresh()->ai_api_key)->toBe('sk-or-v1-ancienne-cle');
});

// === Accès aux données et consentement ===

it('masque l\'option d\'accès aux données tant qu\'aucune clé n\'est résolue', function () {
    $this->get('/profile')
        ->assertOk()
        ->assertDontSee('Autoriser l\'assistant à consulter mes données');
});

it('affiche l\'option d\'accès aux données dès qu\'une clé est résolue', function () {
    $this->user->update(['ai_api_key' => CLE_PROFIL]);

    $this->get('/profile')
        ->assertOk()
        ->assertSee('Autoriser l\'assistant à consulter mes données');
});

it('horodate le consentement à la première activation de l\'accès aux données', function () {
    $this->user->update(['ai_api_key' => CLE_PROFIL]);

    expect($this->user->fresh()->ai_consented_at)->toBeNull();

    Livewire::test(EditProfile::class)
        ->fillForm(['ai_data_access' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->user->fresh()->ai_data_access)->toBeTrue()
        ->and($this->user->fresh()->ai_consented_at)->not->toBeNull();
});

it('ne réhorodate pas un consentement déjà donné', function () {
    $premier = now()->subMonth()->startOfSecond();
    $this->user->update(['ai_api_key' => CLE_PROFIL, 'ai_consented_at' => $premier]);

    Livewire::test(EditProfile::class)
        ->fillForm(['ai_data_access' => true])
        ->call('save');

    expect($this->user->fresh()->ai_consented_at->timestamp)->toBe($premier->timestamp);
});

// === Modèles par utilisateur ===

it('masque les champs de modèle tant qu\'aucune clé n\'est résolue', function () {
    $this->get('/profile')
        ->assertOk()
        ->assertDontSee('Modèle pour l\'assistant');
});

it('enregistre les modèles choisis par l\'utilisateur et les applique', function () {
    config(['ai.models.documents' => 'google/gemini-3.7-flash', 'ai.models.chat' => 'google/gemini-3.7-flash']);
    $this->user->update(['ai_api_key' => CLE_PROFIL]);

    $this->get('/profile')->assertOk()->assertSee('Modèle pour l\'assistant');

    Livewire::test(EditProfile::class)
        ->fillForm([
            'ai_model_documents' => 'google/gemini-3.6-flash',
            'ai_model_chat' => 'google/gemini-3.5-flash-lite',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $user = $this->user->fresh();

    expect($user->ai_model_documents)->toBe('google/gemini-3.6-flash')
        ->and($user->ai_model_chat)->toBe('google/gemini-3.5-flash-lite')
        ->and(App\Support\AiAccess::for($user)->model('documents'))->toBe('google/gemini-3.6-flash')
        ->and(App\Support\AiAccess::for($user)->model('chat'))->toBe('google/gemini-3.5-flash-lite');
});

it('ne propose pas, pour l\'extraction, un modèle que nos mesures ont écarté', function () {
    // `claude-sonnet-5` n'honore pas `response_format: json_schema` quand
    // Bedrock sert la requête — mesuré le 2026-08-21, c'est ce qui a fait
    // changer le modèle de repli. Le proposer pour l'extraction reviendrait à
    // offrir un choix dont on sait qu'il ne marche pas.
    $this->user->update(['ai_api_key' => CLE_PROFIL]);

    $documents = array_keys(ModelCatalog::options(AiProviders::resolve(), 'documents'));

    expect($documents)->not->toContain('anthropic/claude-sonnet-5')
        ->and($documents)->toContain('google/gemini-3.8-flash');
});

it('conserve un modèle choisi hors catalogue plutôt que de l\'effacer', function () {
    // Une valeur posée avant l'arrivée du catalogue, ou par un administrateur :
    // elle doit rester sélectionnée, et rester enregistrable.
    $this->user->update(['ai_api_key' => CLE_PROFIL, 'ai_model_documents' => 'google/gemini-3.5-flash']);

    Livewire::test(EditProfile::class)
        ->assertFormSet(['ai_model_documents' => 'google/gemini-3.5-flash'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->user->fresh()->ai_model_documents)->toBe('google/gemini-3.5-flash');
});

it('montre le pays de l\'éditeur du modèle ET celui du service qui route', function () {
    // Les deux, jamais l'un pour l'autre : un modèle français appelé via
    // OpenRouter transite par un intermédiaire américain, et n'afficher que le
    // drapeau du modèle laisserait croire à une souveraineté qui n'existe pas.
    $this->user->update(['ai_api_key' => CLE_PROFIL]);

    $options = ModelCatalog::options(AiProviders::resolve(), 'documents');

    expect($options['mistralai/mistral-medium-3-5'])->toStartWith('🇫🇷')
        ->and($options['google/gemini-3.8-flash'])->toStartWith('🇺🇸');

    $this->get('/profile')
        ->assertOk()
        // La ligne « Fournisseur » porte le drapeau du routage.
        ->assertSee('OpenRouter')
        ->assertSee('transite par', escape: false);
});

it('revient au modèle de l\'instance quand le champ est vidé', function () {
    config(['ai.models.chat' => 'google/gemini-3.7-flash']);
    $this->user->update(['ai_api_key' => CLE_PROFIL, 'ai_model_chat' => 'google/gemini-3.5-flash-lite']);

    Livewire::test(EditProfile::class)
        ->assertFormSet(['ai_model_chat' => 'google/gemini-3.5-flash-lite'])
        ->fillForm(['ai_model_chat' => ''])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->user->fresh()->ai_model_chat)->toBeNull()
        ->and(App\Support\AiAccess::for($this->user->fresh())->model('chat'))->toBe('google/gemini-3.7-flash');
});

// === Non-régression ===

it('laisse la page de profil intacte quand l\'IA est désactivée', function () {
    config(['ai.enabled' => false]);

    Livewire::test(EditProfile::class)
        ->fillForm(['name' => 'Sans IA'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->user->fresh()->name)->toBe('Sans IA');
});
