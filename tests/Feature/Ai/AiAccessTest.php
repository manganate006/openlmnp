<?php

use App\Models\User;
use App\Support\AiAccess;

const FAKE_INSTANCE_KEY = 'sk-or-v1-instance-0000';
const FAKE_USER_KEY = 'sk-or-v1-utilisateur-9999';

beforeEach(function () {
    config([
        'ai.enabled' => true,
        'ai.api_key' => null,
        'ai.allow_user_keys' => true,
        'demo.email' => 'demo@openlmnp.fr',
    ]);

    $this->user = User::factory()->create();
});

// === 1. Interrupteur général ===

it('est indisponible quand AI_ENABLED est faux', function () {
    config(['ai.enabled' => false, 'ai.api_key' => FAKE_INSTANCE_KEY]);

    $access = AiAccess::for($this->user);

    expect($access->available())->toBeFalse()
        ->and($access->key())->toBeNull()
        ->and($access->source())->toBeNull()
        ->and($access->unavailableReason())->toBe('disabled');
});

// === 1 bis. Compte suspendu ===

it('est indisponible pour un compte suspendu, même avec une clé', function () {
    config(['ai.api_key' => FAKE_INSTANCE_KEY]);
    // suspended_at n'est pas assignable en masse (posé par le provisioning).
    $this->user->forceFill(['suspended_at' => now(), 'ai_api_key' => FAKE_USER_KEY])->save();

    $access = AiAccess::for($this->user->fresh());

    expect($access->available())->toBeFalse()
        ->and($access->key())->toBeNull()
        ->and($access->unavailableReason())->toBe('suspended')
        ->and($access->unavailableMessage())->toContain('suspendu');
});

// === 2. Comptes de démonstration ===

it('est indisponible pour un compte de démonstration éphémère', function () {
    $this->user->update(['is_demo' => true]);
    config(['ai.api_key' => FAKE_INSTANCE_KEY]);

    $access = AiAccess::for($this->user->fresh());

    expect($access->available())->toBeFalse()
        ->and($access->unavailableReason())->toBe('demo');
});

it('est indisponible pour le compte de démonstration FIXE, dont is_demo vaut false', function () {
    // Ce compte a des identifiants publiés : c'est celui où un coût non
    // maîtrisé serait le plus probable, et il n'est PAS marqué is_demo.
    $demo = User::factory()->create(['email' => 'demo@openlmnp.fr', 'is_demo' => false]);
    config(['ai.api_key' => FAKE_INSTANCE_KEY]);

    $access = AiAccess::for($demo);

    expect($demo->is_demo)->toBeFalse()
        ->and($access->available())->toBeFalse()
        ->and($access->unavailableReason())->toBe('demo');
});

// === 3. Clé d'instance ===

it('utilise la clé d\'instance quand elle est renseignée', function () {
    config(['ai.api_key' => FAKE_INSTANCE_KEY]);
    $this->user->update(['ai_api_key' => FAKE_USER_KEY]);

    $access = AiAccess::for($this->user->fresh());

    expect($access->available())->toBeTrue()
        ->and($access->source())->toBe('instance')
        ->and($access->key())->toBe(FAKE_INSTANCE_KEY)
        ->and($access->unavailableReason())->toBeNull();
});

// === 4. Clé utilisateur ===

it('utilise la clé de l\'utilisateur à défaut de clé d\'instance', function () {
    $this->user->update(['ai_api_key' => FAKE_USER_KEY]);

    $access = AiAccess::for($this->user->fresh());

    expect($access->available())->toBeTrue()
        ->and($access->source())->toBe('user')
        ->and($access->key())->toBe(FAKE_USER_KEY);
});

it('ignore la clé de l\'utilisateur quand AI_ALLOW_USER_KEYS est faux', function () {
    config(['ai.allow_user_keys' => false]);
    $this->user->update(['ai_api_key' => FAKE_USER_KEY]);

    $access = AiAccess::for($this->user->fresh());

    expect($access->available())->toBeFalse()
        ->and($access->unavailableReason())->toBe('no_key');
});

// === 5. Aucune clé ===

it('est indisponible sans aucune clé', function () {
    $access = AiAccess::for($this->user);

    expect($access->available())->toBeFalse()
        ->and($access->source())->toBeNull()
        ->and($access->unavailableReason())->toBe('no_key')
        ->and($access->unavailableMessage())->toContain('Mon profil');
});

// === 6. Stockage de la clé ===

it('chiffre la clé de l\'utilisateur en base et ne la sérialise jamais', function () {
    $this->user->update(['ai_api_key' => FAKE_USER_KEY]);

    $stored = DB::table('users')->where('id', $this->user->id)->value('ai_api_key');

    expect($stored)->not->toBeNull()
        ->and($stored)->not->toBe(FAKE_USER_KEY)      // chiffrée au repos
        ->and($this->user->fresh()->ai_api_key)->toBe(FAKE_USER_KEY)   // déchiffrée à la lecture
        ->and($this->user->fresh()->toArray())->not->toHaveKey('ai_api_key')
        ->and(json_encode($this->user->fresh()))->not->toContain(FAKE_USER_KEY);
});

// === 7. Choix du modèle ===

it('résout les modèles depuis la configuration', function () {
    config([
        'ai.models.documents' => 'google/gemini-3.7-flash',
        'ai.models.documents_fallback' => 'anthropic/claude-sonnet-5',
        'ai.models.chat' => 'google/gemini-3.7-flash',
    ]);

    $access = AiAccess::for($this->user);

    expect($access->model('documents'))->toBe('google/gemini-3.7-flash')
        ->and($access->model('documents_fallback'))->toBe('anthropic/claude-sonnet-5')
        ->and($access->model('chat'))->toBe('google/gemini-3.7-flash');
});

it('laisse l\'utilisateur surcharger ses modèles', function () {
    config(['ai.models.documents' => 'google/gemini-3.7-flash', 'ai.models.chat' => 'google/gemini-3.7-flash']);
    $this->user->update([
        'ai_model_documents' => 'anthropic/claude-sonnet-5',
        'ai_model_chat' => 'google/gemini-3.5-flash-lite',
    ]);

    $access = AiAccess::for($this->user->fresh());

    expect($access->model('documents'))->toBe('anthropic/claude-sonnet-5')
        ->and($access->model('chat'))->toBe('google/gemini-3.5-flash-lite')
        // La surcharge utilisateur ne s'applique pas au modèle de repli, qui
        // reste celui de l'instance — ici le défaut du fournisseur, faute
        // d'AI_MODEL_DOCUMENTS_FALLBACK.
        ->and($access->model('documents_fallback'))->toBe('google/gemini-3.6-flash');
});

it('retombe sur le modèle par défaut du fournisseur quand rien n\'est imposé', function () {
    // Une installation neuve n'a aucun AI_MODEL_* : elle doit malgré tout
    // appeler quelque chose, sinon l'IA « ne marche pas » sans rien dire.
    config(['ai.models.documents' => null, 'ai.models.chat' => null]);

    $access = AiAccess::for($this->user);

    expect($access->model('documents'))->toBe('google/gemini-3.8-flash')
        ->and($access->model('chat'))->toBe('google/gemini-3.8-flash');
});

it('laisse l\'instance imposer un modèle contre le défaut du fournisseur', function () {
    config(['ai.models.documents' => 'google/gemini-3.5-flash-lite']);

    expect(AiAccess::for($this->user)->model('documents'))->toBe('google/gemini-3.5-flash-lite');
});

it('traite « none » comme un refus explicite de modèle', function () {
    // Seul moyen de couper la cascade depuis que le défaut du fournisseur
    // comble une valeur vide : c'est un choix, pas une absence de choix.
    config(['ai.models.documents_fallback' => 'none']);

    expect(AiAccess::for($this->user)->model('documents_fallback'))->toBe('');
});

it('ne propose aucun modèle sur un serveur local qui n\'en déclare pas', function () {
    config([
        'ai.provider' => 'auto',
        'ai.base_url' => 'http://ollama:11434/v1',
        'ai.models.documents' => null,
    ]);

    // Ce qui est installé sur un Ollama nous est inconnu : deviner produirait
    // un 404 opaque plutôt qu'un message clair sur la configuration.
    expect(AiAccess::for($this->user)->model('documents'))->toBe('');
});

it('reste indisponible, sans tomber, quand le fournisseur configuré est inconnu', function () {
    config(['ai.provider' => 'nimportequoi', 'ai.api_key' => FAKE_INSTANCE_KEY]);

    $access = AiAccess::for($this->user);

    expect($access->available())->toBeFalse()
        ->and($access->unavailableReason())->toBe('misconfigured')
        // Le message nomme la variable à corriger : c'est un écran
        // d'administration qui l'affichera, pas un journal.
        ->and($access->unavailableMessage())->toContain('AI_PROVIDER');
});

it('normalise la base URL sans barre oblique finale', function () {
    config(['ai.base_url' => 'https://openrouter.ai/api/v1/']);

    expect(AiAccess::for($this->user)->baseUrl())->toBe('https://openrouter.ai/api/v1');
});
