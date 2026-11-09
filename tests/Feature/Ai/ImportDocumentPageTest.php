<?php

use App\Filament\Pages\ImportDocument;
use App\Models\AiUsageLog;
use App\Models\Document;
use App\Models\Expense;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    config([
        'ai.enabled' => true,
        'ai.api_key' => 'sk-or-v1-factice-pour-les-tests',
        // Épinglé : l'hôte de test n'est pas openrouter.ai, la détection
        // automatique le classerait donc en serveur local.
        'ai.provider' => 'openrouter',
        'ai.base_url' => 'https://openrouter.test/api/v1',
        'ai.models.documents' => 'modele/test',
        'ai.models.documents_fallback' => '',
        'ai.pdf_text_fallback' => 'never',
        'ai.docs_monthly_quota' => 0,
        'demo.email' => 'demo@openlmnp.fr',
    ]);

    Sleep::fake();
    Storage::fake('local');

    $this->user = User::factory()->create(['ai_consented_at' => now()]);

    $this->property = Property::forceCreate([
        'user_id' => $this->user->id,
        'name' => 'Studio Lilas',
        'address' => '12 rue des Lilas',
        'city' => 'Lyon',
        'postal_code' => '69003',
        'type' => 'apartment',
        'total_area' => 40,
        'rented_area' => 40,
        'acquisition_date' => '2021-01-01',
        'acquisition_price' => 15000000,
        'notary_fees' => 1000000,
        'land_percentage' => 15,
        'rental_start_date' => '2021-06-01',
        'rental_type' => 'seasonal',
        'tva_regime' => 'exempt',
        'is_primary_residence' => false,
    ]);

    actingAs($this->user);
});

/** Réponse du fournisseur adossée à une fixture. */
function faireRepondreLIa(string $fixture = 'facture-energie'): void
{
    Http::fake(['https://openrouter.test/api/v1/chat/completions' => Http::response([
        'id' => 'gen-1',
        'model' => 'modele/test',
        'choices' => [[
            'message' => [
                'role' => 'assistant',
                'content' => file_get_contents(base_path("tests/Fixtures/ai/{$fixture}.json")),
            ],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'cost' => 0.0012],
    ])]);
}

/**
 * Dépose un PDF dans la zone de transit et renvoie l'état attendu par Filament.
 *
 * ⚠️ L'état d'un FileUpload est un TABLEAU indexé par identifiant, jamais une
 * chaîne : le lui passer en chaîne fait échouer sa validation interne.
 *
 * @return array<string, string>
 */
function deposerDansLaPage(User $user): array
{
    $chemin = "documents/{$user->id}/ai-staging/facture.pdf";
    Storage::disk()->put($chemin, "%PDF-1.4\n%\xE2\xE3\xCF\xD3\ntrailer\n<<>>\n%%EOF");

    return [(string) \Illuminate\Support\Str::uuid() => $chemin];
}

// =============================================================================
// Accès à la page
// =============================================================================

it('rend la page quand l\'IA est activée', function () {
    Livewire::test(ImportDocument::class)->assertOk();
});

it('rend la page inaccessible et invisible quand AI_ENABLED est faux', function () {
    config(['ai.enabled' => false]);

    // Filament refuse l'accès (403) et retire l'entrée de navigation dès que
    // canAccess() est faux — même convention que la page d'administration MCP.
    expect(ImportDocument::canAccess())->toBeFalse()
        ->and(ImportDocument::shouldRegisterNavigation())->toBeTrue();

    get(ImportDocument::getUrl())->assertForbidden();
});

it('affiche un encart d\'indisponibilité pour un compte de démonstration', function () {
    $demo = User::factory()->create(['email' => 'demo@openlmnp.fr', 'ai_consented_at' => now()]);
    actingAs($demo);

    Livewire::test(ImportDocument::class)
        ->assertOk()
        ->assertSee('Fonction indisponible dans la démonstration');
});

it('affiche un encart quand aucune clé n\'est configurée', function () {
    config(['ai.api_key' => null, 'ai.allow_user_keys' => true]);

    Livewire::test(ImportDocument::class)
        ->assertSee('Renseignez votre clé OpenRouter dans Mon profil');
});

it('affiche le quota atteint sans écrire dans le journal au simple affichage', function () {
    config(['ai.docs_monthly_quota' => 1]);

    AiUsageLog::create([
        'user_id' => $this->user->id,
        'feature' => 'documents',
        'model' => 'modele/test',
        'key_source' => 'instance',
        'status' => 'ok',
        'prompt_tokens' => 10,
        'completion_tokens' => 10,
        'duration_ms' => 50,
        'created_at' => now(),
    ]);

    Livewire::test(ImportDocument::class)->assertSee('Quota mensuel atteint');

    // Afficher un encart n'est pas un refus : rien de neuf dans le journal.
    expect(AiUsageLog::withoutGlobalScopes()->where('status', 'quota')->count())->toBe(0);
});

// =============================================================================
// Consentement
// =============================================================================

it('demande le consentement avant la première analyse', function () {
    $this->user->forceFill(['ai_consented_at' => null])->save();

    Http::fake();

    $page = Livewire::test(ImportDocument::class)
        ->assertSee('Avant la première analyse')
        ->assertSee('Ce qui est transmis');

    // Tant qu'il n'est pas donné, aucune analyse ne part.
    $page->call('analyze');
    Http::assertNothingSent();

    $page->call('acceptConsent');

    expect($this->user->refresh()->ai_consented_at)->not->toBeNull();
});

it('n\'écrase pas un consentement déjà horodaté', function () {
    // Le consentement est PARTAGÉ avec l'assistant : le réécrire ferait perdre
    // la date d'origine, seule preuve utile.
    $origine = now()->subMonth()->startOfSecond();
    $this->user->forceFill(['ai_consented_at' => $origine])->save();

    Livewire::test(ImportDocument::class)->call('acceptConsent');

    expect($this->user->refresh()->ai_consented_at->timestamp)->toBe($origine->timestamp);
});

// =============================================================================
// Analyse et confirmation
// =============================================================================

it('analyse un justificatif et propose ses valeurs sans rien créer', function () {
    faireRepondreLIa();
    $chemin = deposerDansLaPage($this->user);

    Livewire::test(ImportDocument::class)
        ->set('data.property_id', $this->property->id)
        ->set('data.file', $chemin)
        ->call('analyze')
        ->assertSet('analysis.document_type', 'expense_invoice')
        ->assertSet('analysis.values.amount', 14320)
        ->assertDispatched('analytics')
        // Une proposition n'est pas une écriture.
        ->assertSee('Créer');

    expect(Expense::count())->toBe(0);
});

it('affiche réellement les champs de relecture, pas seulement l\'état', function () {
    // Sans cette vérification, un écran de relecture VIDE passe inaperçu : l'état
    // Livewire peut être correct alors que le schéma Filament, mis en cache avant
    // l'analyse, ne rend aucun champ.
    faireRepondreLIa();
    $chemin = deposerDansLaPage($this->user);

    Livewire::test(ImportDocument::class)
        ->set('data.property_id', $this->property->id)
        ->set('data.file', $chemin)
        ->call('analyze')
        ->assertSee('Catégorie')
        ->assertSee('Description')
        ->assertSee('Récurrence')
        // Indicateur de confiance et extrait du document.
        ->assertSee('Lu avec certitude')
        ->assertSee('Total TTC 143,20')
        // Le bien n'est pas assujetti à la TVA : le bloc TVA du formulaire de
        // saisie reste masqué ici aussi. C'est la preuve que la réutilisation
        // fonctionne — la visibilité dépend du `property_id` caché.
        ->assertDontSee('Décomposition TVA');
});

it('affiche le bloc TVA quand le bien y est assujetti', function () {
    $this->property->forceFill(['tva_regime' => Property::TVA_LIABLE])->save();

    faireRepondreLIa();
    $chemin = deposerDansLaPage($this->user);

    Livewire::test(ImportDocument::class)
        ->set('data.property_id', $this->property->id)
        ->set('data.file', $chemin)
        ->call('analyze')
        ->assertSee('Taux de TVA')
        ->assertSee('Montant TTC');
});

it('affiche les champs du bon formulaire après changement de type', function () {
    faireRepondreLIa();
    $chemin = deposerDansLaPage($this->user);

    Livewire::test(ImportDocument::class)
        ->set('data.property_id', $this->property->id)
        ->set('data.file', $chemin)
        ->call('analyze')
        ->call('changeType', 'loan_schedule')
        // Champs propres à l'emprunt, absents du formulaire de charge.
        ->assertSee('Banque')
        ->assertSee('Capital emprunté')
        ->assertSee('Taux annuel')
        ->assertDontSee('Récurrence');
});

it('crée la charge avec les valeurs corrigées par l\'utilisateur', function () {
    faireRepondreLIa();
    $chemin = deposerDansLaPage($this->user);

    Livewire::test(ImportDocument::class)
        ->set('data.property_id', $this->property->id)
        ->set('data.file', $chemin)
        ->call('analyze')
        // L'utilisateur relit et corrige : 143,20 € lus, 150,00 € réels.
        ->set('values.amount', '150.00')
        ->call('confirm')
        ->assertHasNoErrors();

    $expense = Expense::first();

    expect($expense)->not->toBeNull()
        ->and($expense->amount)->toBe(15000)
        ->and($expense->category)->toBe('energy')
        ->and($expense->property_id)->toBe($this->property->id);

    $document = Document::first();

    expect($document)->not->toBeNull()
        ->and($document->file_path)->toStartWith("documents/{$this->user->id}/")
        ->and(Storage::disk()->exists($document->file_path))->toBeTrue();
});

it('signale un doublon sans empêcher l\'enregistrement', function () {
    Expense::create([
        'property_id' => $this->property->id,
        'expense_date' => '2026-02-05',
        'amount' => 14320,
        'tva_rate' => 2000,
        'category' => 'energy',
        'description' => 'EDF — déjà saisie',
        'is_dedicated' => true,
        'recurring_type' => 'monthly',
    ]);

    faireRepondreLIa();
    $chemin = deposerDansLaPage($this->user);

    Livewire::test(ImportDocument::class)
        ->set('data.property_id', $this->property->id)
        ->set('data.file', $chemin)
        ->call('analyze')
        ->assertSee('Doublon possible')
        ->assertSee('déjà saisie');
});

it('abandonne l\'analyse et supprime le fichier en transit', function () {
    faireRepondreLIa();
    $chemin = deposerDansLaPage($this->user);

    Livewire::test(ImportDocument::class)
        ->set('data.property_id', $this->property->id)
        ->set('data.file', $chemin)
        ->call('analyze')
        ->call('cancel')
        ->assertSet('analysis', null);

    expect(Storage::disk()->exists(reset($chemin)))->toBeFalse()
        ->and(Expense::count())->toBe(0);
});

it('permet de changer le type de document proposé', function () {
    faireRepondreLIa();
    $chemin = deposerDansLaPage($this->user);

    Livewire::test(ImportDocument::class)
        ->set('data.property_id', $this->property->id)
        ->set('data.file', $chemin)
        ->call('analyze')
        ->call('changeType', 'works_invoice')
        ->assertSet('analysis.document_type', 'works_invoice');
});

it('signale une erreur du fournisseur sans exposer sa réponse', function () {
    Http::fake(['https://openrouter.test/api/v1/chat/completions' => Http::response([
        'error' => ['message' => 'quota exceeded for key sk-or-v1-factice-pour-les-tests'],
    ], 401)]);

    $chemin = deposerDansLaPage($this->user);

    Livewire::test(ImportDocument::class)
        ->set('data.property_id', $this->property->id)
        ->set('data.file', $chemin)
        ->call('analyze')
        ->assertSet('analysis', null)
        ->assertDispatched('analytics')
        ->assertDontSee('sk-or-v1');
});

it('attribue le badge de première analyse à la confirmation', function () {
    $this->seed(\Database\Seeders\BadgeSeeder::class);

    faireRepondreLIa();
    $chemin = deposerDansLaPage($this->user);

    Livewire::test(ImportDocument::class)
        ->set('data.property_id', $this->property->id)
        ->set('data.file', $chemin)
        ->call('analyze')
        ->call('confirm');

    expect($this->user->refresh()->hasBadge('lecteur_automatique'))->toBeTrue();
});

// =============================================================================
// Isolation
// =============================================================================

it('ne propose que les biens de l\'utilisateur connecté', function () {
    $autre = User::factory()->create();

    Property::forceCreate([
        'user_id' => $autre->id,
        'name' => 'Villa de quelqu\'un d\'autre',
        'address' => '1 rue B',
        'city' => 'Nice',
        'postal_code' => '06000',
        'type' => 'house',
        'total_area' => 100,
        'rented_area' => 100,
        'acquisition_date' => '2020-01-01',
        'acquisition_price' => 30000000,
        'notary_fees' => 0,
        'land_percentage' => 20,
        'rental_start_date' => '2021-01-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);

    Livewire::test(ImportDocument::class)
        ->assertSee('Studio Lilas')
        ->assertDontSee('Villa de quelqu\'un d\'autre');
});

it('refuse d\'analyser pour le bien d\'un autre utilisateur', function () {
    $autre = User::factory()->create();

    $bienDeLAutre = Property::forceCreate([
        'user_id' => $autre->id,
        'name' => 'Villa B',
        'address' => '1 rue B',
        'city' => 'Nice',
        'postal_code' => '06000',
        'type' => 'house',
        'total_area' => 100,
        'rented_area' => 100,
        'acquisition_date' => '2020-01-01',
        'acquisition_price' => 30000000,
        'notary_fees' => 0,
        'land_percentage' => 20,
        'rental_start_date' => '2021-01-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);

    Http::fake();
    $chemin = deposerDansLaPage($this->user);

    Livewire::test(ImportDocument::class)
        ->set('data.property_id', $bienDeLAutre->id)
        ->set('data.file', $chemin)
        ->call('analyze')
        ->assertSet('analysis', null);

    // Aucun document ne part chez le fournisseur pour un bien qui n'est pas à soi.
    Http::assertNothingSent();
});

it('ne laisse pas un utilisateur lire le journal d\'IA d\'un autre', function () {
    $autre = User::factory()->create();

    AiUsageLog::create([
        'user_id' => $autre->id,
        'feature' => 'documents',
        'model' => 'modele/test',
        'key_source' => 'instance',
        'status' => 'ok',
        'prompt_tokens' => 10,
        'completion_tokens' => 10,
        'duration_ms' => 50,
        'created_at' => now(),
    ]);

    // Scope global : l'utilisateur connecté ne voit que ses propres lignes.
    expect(AiUsageLog::count())->toBe(0)
        ->and(AiUsageLog::withoutGlobalScopes()->count())->toBe(1);
});

// =============================================================================
// Aperçu du document
// =============================================================================

it('propose un aperçu du document sur une URL signée', function () {
    faireRepondreLIa();
    $chemin = deposerDansLaPage($this->user);

    $page = Livewire::test(ImportDocument::class)
        ->set('data.property_id', $this->property->id)
        ->set('data.file', $chemin)
        ->call('analyze');

    $url = $page->instance()->previewUrl();

    expect($url)->toContain('signature=')
        ->and($url)->toContain('/d/documents/' . $this->user->id . '/');

    // ⚠️ `sandbox=""` (sans allow-same-origin) donne au cadre une origine opaque :
    // le cookie de session n'est plus envoyé et DocumentController répond 403.
    $page->assertSee('sandbox="allow-same-origin"', escape: false)
        ->assertDontSee('sandbox=""', escape: false);
});

// === Aide contextuelle ===

it('dispose d\'une aide contextuelle dédiée', function () {
    $aide = App\Support\HelpContentRegistry::resolve('filament.admin.pages.import-document');

    expect($aide['view'])->toBe('help.import-document')
        ->and($aide['title'])->toBe('Importer un justificatif');

    $html = view('help.import-document')->render();

    expect($html)->toContain('Rien n\'est jamais enregistré sans votre relecture')
        ->and($html)->toContain('10 Mo')
        ->and($html)->toContain('À vérifier')
        ->and($html)->toContain('compte pour une unité');

    expect(App\Support\HelpContentRegistry::suggestions('filament.admin.pages.import-document'))->toHaveCount(3);
});

it('accepte les photos HEIC à la sélection (converties en JPEG par le navigateur)', function () {
    actingAs($this->user);

    // Le sélecteur de fichiers doit proposer les HEIC ; le script heic-upload.js
    // les remplace par un JPEG avant téléversement.
    $page = Livewire::test(App\Filament\Pages\ImportDocument::class)->instance();
    $types = $page->getForm('form')->getFlatFields()['file']->getAcceptedFileTypes();

    expect($types)->toContain('image/heic')
        ->and($types)->toContain('image/heif')
        ->and($types)->toContain('image/jpeg');
});

it('affiche une image sans cadre ni visionneuse', function () {
    // Un JPEG n'a aucune raison de passer par une iframe : moins de surface,
    // et insensible aux restrictions d'affichage en cadre.
    faireRepondreLIa();

    $chemin = "documents/{$this->user->id}/ai-staging/photo.jpg";
    Storage::disk()->put($chemin, base64_decode(
        '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0a'
        . 'HBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAA'
        . 'AAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q=='
    ));

    $page = Livewire::test(ImportDocument::class)
        ->set('data.property_id', $this->property->id)
        ->set('data.file', [(string) \Illuminate\Support\Str::uuid() => $chemin])
        ->call('analyze');

    expect($page->instance()->previewIsImage())->toBeTrue();

    $page->assertSee('<img class="ai-viewer"', escape: false)
        ->assertDontSee('<iframe class="ai-viewer"', escape: false);
});

// =============================================================================
// Bouton « Réanalyser avec le modèle renforcé »
// =============================================================================

it('ne propose pas la réanalyse quand la confiance est déjà haute', function () {
    // La fixture porte 0,93 : proposer un appel supplémentaire, facturé, pour
    // une extraction sûre n'a aucun sens.
    config(['ai.models.documents_fallback' => 'modele/renforce']);

    faireRepondreLIa();
    $chemin = deposerDansLaPage($this->user);

    $page = Livewire::test(ImportDocument::class)
        ->set('data.property_id', $this->property->id)
        ->set('data.file', $chemin)
        ->call('analyze');

    expect($page->instance()->canReanalyze())->toBeFalse();

    $page->assertDontSee('Réanalyser avec le modèle renforcé');
});

it('propose la réanalyse sous le seuil de confiance', function () {
    config([
        'ai.models.documents_fallback' => 'modele/renforce',
        'ai.reanalysis_threshold' => 0.85,
        // Sans cela, une confiance de 0,70 déclencherait la cascade automatique.
        'ai.fallback_confidence' => 0.5,
    ]);

    $peuSur = json_encode(array_merge(
        json_decode(file_get_contents(base_path('tests/Fixtures/ai/facture-energie.json')), true),
        ['confidence' => 0.70],
    ));

    Http::fake(['https://openrouter.test/api/v1/chat/completions' => Http::response([
        'id' => 'gen-1',
        'model' => 'modele/test',
        'choices' => [['message' => ['role' => 'assistant', 'content' => $peuSur], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'cost' => 0.0012],
    ])]);

    $chemin = deposerDansLaPage($this->user);

    $page = Livewire::test(ImportDocument::class)
        ->set('data.property_id', $this->property->id)
        ->set('data.file', $chemin)
        ->call('analyze');

    expect($page->instance()->canReanalyze())->toBeTrue();

    $page->assertSee('Réanalyser avec le modèle renforcé');
});

it('ne propose plus la réanalyse une fois la cascade passée', function () {
    // Relancer le même modèle de repli redonnerait le même résultat, facturé.
    config([
        'ai.models.documents_fallback' => 'modele/renforce',
        'ai.fallback_confidence' => 0.9,
    ]);

    $peuSur = json_encode(array_merge(
        json_decode(file_get_contents(base_path('tests/Fixtures/ai/facture-energie.json')), true),
        ['confidence' => 0.40],
    ));

    Http::fake(['https://openrouter.test/api/v1/chat/completions' => Http::sequence()
        ->push([
            'id' => 'gen-1', 'model' => 'modele/test',
            'choices' => [['message' => ['role' => 'assistant', 'content' => $peuSur], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'cost' => 0.0012],
        ])
        ->push([
            'id' => 'gen-2', 'model' => 'modele/renforce',
            'choices' => [['message' => ['role' => 'assistant', 'content' => $peuSur], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'cost' => 0.0090],
        ]),
    ]);

    $chemin = deposerDansLaPage($this->user);

    $page = Livewire::test(ImportDocument::class)
        ->set('data.property_id', $this->property->id)
        ->set('data.file', $chemin)
        ->call('analyze')
        ->assertSet('analysis.fallback_used', true);

    expect($page->instance()->canReanalyze())->toBeFalse();
});

it('ne propose pas la réanalyse quand aucun modèle de repli n\'est configuré', function () {
    // « none » = refus explicite de la cascade. Depuis que le défaut du
    // fournisseur comble une valeur vide, une chaîne vide ne suffit plus.
    config(['ai.models.documents_fallback' => 'none', 'ai.reanalysis_threshold' => 1.0]);

    faireRepondreLIa();
    $chemin = deposerDansLaPage($this->user);

    $page = Livewire::test(ImportDocument::class)
        ->set('data.property_id', $this->property->id)
        ->set('data.file', $chemin)
        ->call('analyze');

    expect($page->instance()->canReanalyze())->toBeFalse();
});

// =============================================================================
// Place dans la navigation
// =============================================================================

it('se range après Recettes et Charges dans le groupe Comptabilité', function () {
    // Une ÉGALITÉ de navigationSort laisse Filament trancher seul : l'entrée
    // s'était retrouvée entre Recettes et Charges. Ce test fixe l'ordre voulu —
    // la saisie courante d'abord, l'import ensuite.
    $rang = fn (string $classe) => (new ReflectionClass($classe))
        ->getStaticPropertyValue('navigationSort');

    expect($rang(ImportDocument::class))
        ->toBeGreaterThan($rang(\App\Filament\Resources\Incomes\IncomeResource::class))
        ->toBeGreaterThan($rang(\App\Filament\Resources\Expenses\ExpenseResource::class));
});

it('refuse de basculer la relecture vers le type liasse, réservé à l\'assistant de reprise', function () {
    faireRepondreLIa();
    $chemin = deposerDansLaPage($this->user);

    // La liste des types de la page n'offre jamais la liasse…
    expect(ImportDocument::typeOptions())->not->toHaveKey('liasse_2033');

    // …et un navigateur qui forcerait la valeur est ignoré : le formulaire
    // de relecture reste celui de la charge.
    Livewire::test(ImportDocument::class)
        ->set('data.property_id', $this->property->id)
        ->set('data.file', $chemin)
        ->call('analyze')
        ->call('changeType', 'liasse_2033')
        ->assertSee('Récurrence')
        ->assertDontSee('Capital emprunté');
});

// =============================================================================
// Changer de type ne doit pas effacer ce qu'on vient de corriger
// =============================================================================

it('conserve les corrections manuelles quand on change le type de document', function () {
    // ⚠️ `changeType()` appelle `present()`, qui refait `previewForm->fill()`. Sans
    // précaution, un montant rectifié, une date corrigée ou l'interrupteur
    // « 100 % dédié » basculé étaient ÉCRASÉS en silence par la proposition du
    // modèle — précisément les gestes qu'on demande à l'utilisateur de faire.
    faireRepondreLIa();
    $chemin = deposerDansLaPage($this->user);

    Livewire::test(ImportDocument::class)
        ->set('data.property_id', $this->property->id)
        ->set('data.file', $chemin)
        ->call('analyze')
        // L'utilisateur corrige, PUIS se ravise sur le type.
        ->set('values.amount', 15000)
        ->set('values.is_dedicated', false)
        ->call('changeType', 'works_invoice')
        ->assertSet('values.amount', 15000)
        ->assertSet('values.is_dedicated', false);
});

it('ne réimpose pas l\'ancienne proposition sur les champs laissés tels quels', function () {
    // L'autre moitié de la règle : on ne conserve que les ÉCARTS. Un champ non
    // touché doit recevoir la proposition du nouveau type, qui peut légitimement
    // différer — sinon changer de type ne servirait plus à rien.
    faireRepondreLIa();
    $chemin = deposerDansLaPage($this->user);

    $page = Livewire::test(ImportDocument::class)
        ->set('data.property_id', $this->property->id)
        ->set('data.file', $chemin)
        ->call('analyze');

    $avant = $page->get('values')['amount'] ?? null;

    $page->call('changeType', 'works_invoice');

    // Le montant n'a pas été touché à la main : il suit la nouvelle analyse.
    expect($page->get('values')['amount'] ?? null)->toBe($avant);
});

// =============================================================================
// Relevé annuel de plateforme — et le relevé bancaire, qui n'était pas couvert
// =============================================================================

it('propose le relevé annuel dans le sélecteur, toujours pas la liasse', function () {
    expect(ImportDocument::typeOptions())->toHaveKey('income_statement')
        ->and(ImportDocument::typeOptions())->not->toHaveKey('liasse_2033');
});

it('affiche les revenus mensuels avec leur commission', function () {
    faireRepondreLIa('releve-annuel-airbnb');
    $chemin = deposerDansLaPage($this->user);

    Livewire::test(ImportDocument::class)
        ->set('data.property_id', $this->property->id)
        ->set('data.file', $chemin)
        ->call('analyze')
        ->assertSee('Revenus mensuels')
        ->assertSee('Commission')
        ->assertSee('Total coché')
        ->assertSee('février');
});

it('montre l\'en-tête du relevé annuel, et aucun champ de charge', function () {
    faireRepondreLIa('releve-annuel-airbnb');
    $chemin = deposerDansLaPage($this->user);

    Livewire::test(ImportDocument::class)
        ->set('data.property_id', $this->property->id)
        ->set('data.file', $chemin)
        ->call('analyze')
        ->assertSee('Plateforme')
        ->assertSee('Année du relevé')
        // Les champs d'une charge n'ont rien à faire là : ils sont requis, et rien
        // ne les remplirait.
        ->assertDontSee('Catégorie');
});

it('confirme un relevé annuel et crée les recettes', function () {
    // ⚠️ C'est le test qui prouve que la validation de previewForm->getState() passe.
    faireRepondreLIa('releve-annuel-airbnb');
    $chemin = deposerDansLaPage($this->user);

    Livewire::test(ImportDocument::class)
        ->set('data.property_id', $this->property->id)
        ->set('data.file', $chemin)
        ->call('analyze')
        ->call('confirm')
        ->assertHasNoErrors();

    expect(\App\Models\Income::count())->toBe(9);
});

it('confirme un relevé bancaire — le chemin que rien ne couvrait', function () {
    // ⚠️ Ce test manquait, et le chemin était cassé : l'entité `statement` tombait
    // dans `default => ExpenseForm::fields()`, dont sept champs sont `required()` et
    // qu'aucune valeur de relevé ne remplit, alors que `confirm()` valide. Personne ne
    // pouvait confirmer un relevé bancaire depuis l'écran.
    faireRepondreLIa('releve-bancaire');
    $chemin = deposerDansLaPage($this->user);

    Livewire::test(ImportDocument::class)
        ->set('data.property_id', $this->property->id)
        ->set('data.file', $chemin)
        ->call('analyze')
        ->call('confirm')
        ->assertHasNoErrors();

    expect(\App\Models\Expense::count() + \App\Models\Income::count())->toBeGreaterThan(0);
});
