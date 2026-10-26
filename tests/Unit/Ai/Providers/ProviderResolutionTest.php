<?php

use App\Services\Ai\Exceptions\AiMisconfigured;
use App\Services\Ai\Providers\AiProviders;
use App\Services\Ai\Providers\Capability;
use App\Services\Ai\Providers\OpenAiProvider;
use App\Services\Ai\Providers\OpenRouterProvider;

/**
 * Résolution du fournisseur : la seule règle de détection du projet.
 *
 * Ce qui se joue ici, c'est où partent les justificatifs d'un utilisateur. Une
 * installation existante doit continuer d'aller chez OpenRouter sans que
 * personne n'ait rien à faire, et une configuration douteuse doit échouer
 * bruyamment plutôt que de deviner.
 */
beforeEach(function () {
    // Repart de la configuration livrée : ni fournisseur, ni adresse imposés.
    config(['ai.provider' => 'auto', 'ai.base_url' => '']);
});

// =============================================================================
// Détection
// =============================================================================

it('vise OpenRouter quand rien n\'est configuré', function () {
    $provider = AiProviders::resolve();

    expect($provider)->toBeInstanceOf(OpenRouterProvider::class)
        ->and($provider->key())->toBe('openrouter')
        ->and($provider->baseUrl())->toBe('https://openrouter.ai/api/v1');
});

it('reconnaît OpenRouter à son nom d\'hôte, comme sur une installation existante', function () {
    // Le cas de tous les .env déjà déployés : AI_BASE_URL renseignée, pas d'AI_PROVIDER.
    config(['ai.base_url' => 'https://openrouter.ai/api/v1']);

    expect(AiProviders::resolve())->toBeInstanceOf(OpenRouterProvider::class);
});

it('reconnaît un sous-domaine d\'un hôte connu', function () {
    config(['ai.base_url' => 'https://eu.openrouter.ai/api/v1']);

    expect(AiProviders::resolveKey())->toBe('openrouter');
});

it('classe un hôte inconnu en serveur local, sans toucher à son adresse', function () {
    // Exactement ce que faisait l'ancienne détection codée en dur : un Ollama
    // n'est pas OpenRouter, donc les PDF lui partiront en texte extrait.
    config(['ai.base_url' => 'http://ollama:11434/v1']);

    $provider = AiProviders::resolve();

    expect($provider->key())->toBe('local')
        ->and($provider)->toBeInstanceOf(OpenAiProvider::class)
        ->and($provider)->not->toBeInstanceOf(OpenRouterProvider::class)
        ->and($provider->baseUrl())->toBe('http://ollama:11434/v1');
});

it('laisse une adresse explicite primer sur celle du fournisseur', function () {
    // Passerelle d'entreprise placée devant OpenRouter : le protocole reste
    // celui d'OpenRouter, l'adresse est celle de la passerelle.
    config(['ai.provider' => 'openrouter', 'ai.base_url' => 'https://ia.acme.corp/or/v1']);

    $provider = AiProviders::resolve();

    expect($provider)->toBeInstanceOf(OpenRouterProvider::class)
        ->and($provider->baseUrl())->toBe('https://ia.acme.corp/or/v1');
});

it('retire la barre oblique finale de l\'adresse', function () {
    config(['ai.base_url' => 'https://openrouter.ai/api/v1/']);

    expect(AiProviders::resolve()->baseUrl())->toBe('https://openrouter.ai/api/v1');
});

// =============================================================================
// Configuration inexploitable — échec bruyant
// =============================================================================

it('refuse un fournisseur inconnu au lieu de retomber sur OpenRouter', function () {
    // Une faute de frappe ne doit pas envoyer les justificatifs ailleurs que là
    // où l'administrateur croit les envoyer.
    config(['ai.provider' => 'openrouteur']);

    expect(fn () => AiProviders::resolve())
        ->toThrow(AiMisconfigured::class, 'openrouteur');
});

it('refuse un fournisseur sans adresse', function () {
    // `local` n'a pas d'adresse par défaut : elle dépend de l'installation.
    config(['ai.provider' => 'local', 'ai.base_url' => '']);

    expect(fn () => AiProviders::resolve())
        ->toThrow(AiMisconfigured::class, 'AI_BASE_URL');
});

// =============================================================================
// Capacités
// =============================================================================

it('n\'accorde qu\'à OpenRouter les champs qui lui sont propres', function () {
    $openrouter = AiProviders::resolve();

    config(['ai.base_url' => 'http://ollama:11434/v1']);
    $local = AiProviders::resolve();

    $propres = [
        Capability::PdfUpload,
        Capability::FileParserPlugin,
        Capability::FileAnnotations,
        Capability::DataCollectionPolicy,
        Capability::AttributionHeaders,
        Capability::ReportedCost,
        Capability::KeyIntrospection,
        Capability::OauthKeyHandover,
    ];

    foreach ($propres as $capacite) {
        expect($openrouter->supports($capacite))->toBeTrue("OpenRouter devrait savoir : {$capacite->value}")
            ->and($local->supports($capacite))->toBeFalse("Un serveur local ne sait pas : {$capacite->value}");
    }

    // Le schéma strict, en revanche, se tente partout : c'est son refus qui
    // déclenche le repli en json_object.
    expect($local->supports(Capability::StructuredOutput))->toBeTrue();
});

// =============================================================================
// Modèles et adresses dérivées
// =============================================================================

it('propose les modèles par défaut du fournisseur', function () {
    $provider = AiProviders::resolve();

    expect($provider->defaultModel('documents'))->toBe('google/gemini-3.8-flash')
        ->and($provider->defaultModel('documents_fallback'))->toBe('google/gemini-3.6-flash')
        ->and($provider->defaultModel('chat'))->toBe('google/gemini-3.8-flash');
});

it('ne propose aucun modèle par défaut pour un serveur local', function () {
    // Cela dépend de ce qui y est installé : deviner mènerait à un 404 opaque.
    config(['ai.base_url' => 'http://ollama:11434/v1']);

    expect(AiProviders::resolve()->defaultModel('documents'))->toBe('');
});

it('ne traduit jamais un slug de modèle d\'un éditeur à l\'autre', function () {
    // Retirer un préfixe qui correspond au fournisseur visé, oui ; deviner
    // qu'un slug Google « voulait dire » autre chose, non : ce serait envoyer
    // les documents d'un utilisateur à un modèle qu'il n'a pas choisi.
    expect(AiProviders::resolve()->normalizeModel('google/gemini-3.7-flash'))
        ->toBe('google/gemini-3.7-flash');
});

it('dérive les adresses de compte de la base URL configurée', function () {
    $provider = AiProviders::resolve();

    expect($provider->authorizeUrl())->toBe('https://openrouter.ai/auth')
        ->and($provider->keyExchangeUrl())->toBe('https://openrouter.ai/api/v1/auth/keys')
        ->and($provider->creditsUrl())->toBe('https://openrouter.ai/settings/credits')
        ->and($provider->keysUrl())->toBe('https://openrouter.ai/keys');
});

it('reconnaît Anthropic, Google et Mistral à leur nom d\'hôte', function () {
    config(['ai.base_url' => 'https://api.anthropic.com/v1']);
    expect(AiProviders::resolveKey())->toBe('anthropic');

    config(['ai.base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai']);
    expect(AiProviders::resolveKey())->toBe('gemini');

    config(['ai.base_url' => 'https://api.mistral.ai/v1']);
    expect(AiProviders::resolveKey())->toBe('mistral');
});

it('propose un fournisseur français, avec un modèle qui sait lire une photo', function () {
    // Le seul de la liste dont les documents restent en Europe — un critère à
    // part entière pour une comptabilité. Et un justificatif arrive souvent en
    // photo : un modèle sans vision serait inutilisable ici.
    config(['ai.provider' => 'mistral', 'ai.base_url' => '']);

    $provider = AiProviders::resolve();

    expect($provider->label())->toBe('Mistral AI')
        ->and($provider->baseUrl())->toBe('https://api.mistral.ai/v1')
        ->and($provider->defaultModel('documents'))->not->toBe('')
        // Pas de cascade par défaut : rien n'a été mesuré chez cet éditeur.
        ->and($provider->defaultModel('documents_fallback'))->toBe('');
});

it('sait qui reçoit un PDF et qui doit recevoir du texte', function (string $cle, bool $recoitUnPdf) {
    // C'est cette réponse, et elle seule, qui décide du repli texte : un
    // fournisseur qui sait lire un PDF ne doit pas être dégradé pour rien, et
    // un serveur qui ne sait pas recevrait une partie `file` sans savoir quoi
    // en faire.
    config(['ai.provider' => $cle, 'ai.base_url' => $cle === 'local' ? 'http://ollama:11434/v1' : '']);

    expect(AiProviders::resolve()->supports(Capability::PdfUpload))->toBe($recoitUnPdf);
})->with([
    'OpenRouter' => ['openrouter', true],
    'Anthropic en natif' => ['anthropic', true],
    'Gemini en natif' => ['gemini', true],
    'Mistral' => ['mistral', false],
    'OpenAI' => ['openai', false],
    'serveur local' => ['local', false],
]);

it('n\'offre ni remise de clé ni page de crédits sur un serveur local', function () {
    config(['ai.base_url' => 'http://ollama:11434/v1']);

    $provider = AiProviders::resolve();

    expect($provider->authorizeUrl())->toBeNull()
        ->and($provider->creditsUrl())->toBeNull()
        ->and($provider->keysUrl())->toBeNull();
});
