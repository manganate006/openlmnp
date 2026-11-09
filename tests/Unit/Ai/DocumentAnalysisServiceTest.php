<?php

use App\Models\AiUsageLog;
use App\Models\Document;
use App\Models\Expense;
use App\Models\Furniture;
use App\Models\Loan;
use App\Models\Property;
use App\Models\User;
use App\Services\Ai\Analysis\DocumentFile;
use App\Services\Ai\Analysis\PdfTextFallback;
use App\Services\Ai\DocumentAnalysisService;
use App\Services\Ai\Exceptions\AiBadResponse;
use App\Services\Ai\Exceptions\AiTimeout;
use App\Services\Ai\Exceptions\AiUnavailable;
use App\Services\Ai\Providers\AiProviders;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

use function Pest\Laravel\travelTo;

const ENDPOINT_IA = 'https://openrouter.test/api/v1/chat/completions';

beforeEach(function () {
    config([
        'ai.enabled' => true,
        'ai.api_key' => 'sk-or-v1-factice-pour-les-tests',
        // Épinglé : l'hôte de test n'est pas openrouter.ai, la détection
        // automatique le classerait donc en serveur local.
        'ai.provider' => 'openrouter',
        'ai.base_url' => 'https://openrouter.test/api/v1',
        'ai.models.documents' => 'modele/rapide',
        'ai.models.documents_fallback' => 'modele/renforce',
        'ai.fallback_confidence' => 0.6,
        'ai.pdf_engine' => 'native',
        // Le service de test n'est pas openrouter.ai : sans ce réglage explicite,
        // la détection automatique replierait tous les PDF sur le mode texte.
        'ai.pdf_text_fallback' => 'never',
        'demo.email' => 'demo@openlmnp.fr',
    ]);

    // Sans cela, un test qui provoque une nouvelle tentative dort réellement.
    Sleep::fake();
    Storage::fake('local');

    $this->user = User::factory()->create();
    Auth::setUser($this->user);

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

    $this->service = new DocumentAnalysisService;
});

// -----------------------------------------------------------------------------
// Utilitaires
// -----------------------------------------------------------------------------

/** Contenu JSON d'une fixture de réponse de modèle. */
function fixtureIa(string $nom): string
{
    return (string) file_get_contents(base_path("tests/Fixtures/ai/{$nom}.json"));
}

/** Réponse OpenRouter complète autour d'un contenu JSON. */
function reponseIa(string $contenu, string $modele = 'modele/rapide', array $annotations = []): array
{
    $message = ['role' => 'assistant', 'content' => $contenu];

    if ($annotations !== []) {
        $message['annotations'] = $annotations;
    }

    return [
        'id' => 'gen-test',
        'model' => $modele,
        'choices' => [['message' => $message, 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 4000, 'completion_tokens' => 500, 'cost' => 0.0024],
    ];
}

/**
 * PDF minimal PORTEUR D'UNE COUCHE TEXTE, non compressé.
 *
 * Sert à vérifier le repli texte : un PDF scanné (celui de deposerPdf()) n'en a
 * pas, et c'est précisément la différence que le repli doit savoir faire.
 */
function pdfAvecTexte(string $texte): string
{
    $echappe = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $texte);
    $flux = "BT /F1 12 Tf 72 720 Td ({$echappe}) Tj ET";

    $objets = [
        "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
        "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
        "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R "
            . "/Resources << /Font << /F1 5 0 R >> >> >>\nendobj\n",
        "4 0 obj\n<< /Length " . strlen($flux) . " >>\nstream\n{$flux}\nendstream\nendobj\n",
        "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n",
    ];

    $pdf = "%PDF-1.4\n";
    $offsets = [];

    foreach ($objets as $objet) {
        $offsets[] = strlen($pdf);
        $pdf .= $objet;
    }

    $debutXref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objets) + 1) . "\n0000000000 65535 f \n";

    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }

    return $pdf . "trailer\n<< /Size " . (count($objets) + 1) . " /Root 1 0 R >>\n"
        . "startxref\n{$debutXref}\n%%EOF";
}

/** Dépose un faux PDF sur le disque et renvoie son chemin. */
function deposerPdf(User $user, string $nom = 'facture.pdf'): string
{
    $chemin = "documents/{$user->id}/ai-staging/{$nom}";
    Storage::disk()->put($chemin, "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF");

    return $chemin;
}

// =============================================================================
// Extraction par type de document
// =============================================================================

it('extrait une facture de charge et la propose sans rien créer', function () {
    Http::fake([ENDPOINT_IA => Http::response(reponseIa(fixtureIa('facture-energie')))]);

    $result = $this->service->analyze(deposerPdf($this->user), $this->property);

    expect($result->documentType)->toBe('expense_invoice')
        ->and($result->entity())->toBe('expense')
        ->and($result->values['amount'])->toBe(14320)
        ->and($result->values['expense_date'])->toBe('2026-02-04')
        ->and($result->values['tva_rate'])->toBe(2000)
        ->and($result->values['category'])->toBe('energy')
        ->and($result->values['description'])->toBe('EDF — Électricité janvier 2026')
        ->and($result->values['recurring_type'])->toBe('monthly')
        // analyze() ne crée jamais rien : c'est apply() qui enregistre.
        ->and(Expense::count())->toBe(0);
});

it('extrait un avis de taxe foncière', function () {
    Http::fake([ENDPOINT_IA => Http::response(reponseIa(fixtureIa('avis-taxe-fonciere')))]);

    $result = $this->service->analyze(deposerPdf($this->user), $this->property);

    expect($result->values['category'])->toBe(Expense::CATEGORY_PROPERTY_TAX)
        ->and($result->values['amount'])->toBe(118700)
        ->and($result->values['tva_rate'])->toBe(0)
        ->and($result->values['recurring_type'])->toBe('yearly');
});

it('regroupe les petits articles d\'une facture de mobilier en une ligne', function () {
    Http::fake([ENDPOINT_IA => Http::response(reponseIa(fixtureIa('facture-mobilier')))]);

    $result = $this->service->analyze(deposerPdf($this->user), $this->property);

    $rows = collect($result->rows);

    expect($result->entity())->toBe('furniture')
        // 3 articles ≥ 100 € + 1 ligne « Petit équipement » (3 articles < 100 €).
        ->and($rows)->toHaveCount(4)
        ->and($rows->last()['description'])->toContain('Petit équipement')
        ->and($rows->last()['amount'])->toBe(17790)
        // Le lave-vaisselle dépasse 1 000 € ? Non : durée standard.
        ->and($rows->firstWhere('description', 'Canapé convertible 3 places')['duration_years'])->toBe(5);
});

// =============================================================================
// Requête émise
// =============================================================================

it('joint toujours un moteur PDF explicite, faute de quoi OpenRouter facture l\'OCR', function () {
    Http::fake([ENDPOINT_IA => Http::response(reponseIa(fixtureIa('facture-energie')))]);

    $this->service->analyze(deposerPdf($this->user), $this->property);

    Http::assertSent(function ($request) {
        $corps = $request->data();

        return ($corps['plugins'][0]['id'] ?? null) === 'file-parser'
            && ($corps['plugins'][0]['pdf']['engine'] ?? null) === 'native';
    });
});

it('n\'envoie que le bien concerné, jamais l\'identité de l\'utilisateur', function () {
    Http::fake([ENDPOINT_IA => Http::response(reponseIa(fixtureIa('facture-energie')))]);

    $this->user->update(['name' => 'Jean Dupont', 'siren' => '123456789']);

    $this->service->analyze(deposerPdf($this->user), $this->property);

    Http::assertSent(function ($request) {
        $corps = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

        return str_contains($corps, 'Studio Lilas')
            && ! str_contains($corps, 'Jean Dupont')
            && ! str_contains($corps, '123456789')
            && ! str_contains($corps, $this->user->email);
    });
});

it('ne laisse jamais fuir la clé dans un message d\'erreur', function () {
    Http::fake([ENDPOINT_IA => Http::response([
        'error' => ['message' => 'Invalid key sk-or-v1-factice-pour-les-tests'],
    ], 401)]);

    try {
        $this->service->analyze(deposerPdf($this->user), $this->property);
        $this->fail('Une exception était attendue.');
    } catch (\App\Services\Ai\Exceptions\AiException $e) {
        expect($e->getMessage())->not->toContain('sk-or-v1')
            ->and($e->code())->toBe('AiKeyInvalid');
    }

    // Ni dans le journal.
    expect(json_encode(AiUsageLog::withoutGlobalScopes()->get()->toArray()))->not->toContain('sk-or-v1');
});

// =============================================================================
// Réparation et cascade
// =============================================================================

it('répare une réponse mal formée en un seul appel supplémentaire, hors quota', function () {
    Http::fake([ENDPOINT_IA => Http::sequence()
        ->push(reponseIa('ceci n\'est pas du JSON', annotations: [['type' => 'file', 'ref' => 'abc']]))
        ->push(reponseIa(fixtureIa('facture-energie'))),
    ]);

    $result = $this->service->analyze(deposerPdf($this->user), $this->property);

    expect($result->values['amount'])->toBe(14320);

    $journal = AiUsageLog::withoutGlobalScopes()->orderBy('id')->get();

    expect($journal)->toHaveCount(2)
        // Le premier appel échoue, le second répare — un seul compte au quota.
        ->and($journal[0]->feature)->toBe('documents')
        ->and($journal[0]->status)->toBe('error')
        ->and($journal[1]->feature)->toBe('documents_auto')
        ->and($journal[1]->meta['repair'])->toBeTrue();
});

it('rejoue les annotations du parsing lors de la réparation, pour ne pas repayer le PDF', function () {
    Http::fake([ENDPOINT_IA => Http::sequence()
        ->push(reponseIa('pas du JSON', annotations: [['type' => 'file', 'ref' => 'parsing-deja-facture']]))
        ->push(reponseIa(fixtureIa('facture-energie'))),
    ]);

    $this->service->analyze(deposerPdf($this->user), $this->property);

    $requetes = Http::recorded();
    $seconde = $requetes[1][0]->data();

    // OpenRouter attend les annotations sur un message ASSISTANT de suivi.
    $assistant = collect($seconde['messages'])->firstWhere('role', 'assistant');

    expect($assistant)->not->toBeNull()
        ->and($assistant['annotations'][0]['ref'])->toBe('parsing-deja-facture');
});

it('bascule sur le modèle renforcé quand la confiance est sous le seuil', function () {
    $peuSur = json_encode(array_merge(
        json_decode(fixtureIa('facture-energie'), true),
        ['confidence' => 0.35],
    ));

    Http::fake([ENDPOINT_IA => Http::sequence()
        ->push(reponseIa($peuSur))
        ->push(reponseIa(fixtureIa('facture-energie'), modele: 'modele/renforce')),
    ]);

    $result = $this->service->analyze(deposerPdf($this->user), $this->property);

    expect($result->fallbackUsed)->toBeTrue()
        ->and($result->model)->toBe('modele/renforce')
        ->and($result->confidence)->toBe(0.93);

    $journal = AiUsageLog::withoutGlobalScopes()->orderBy('id')->get();

    expect($journal)->toHaveCount(2)
        ->and($journal[1]->feature)->toBe('documents_auto')
        ->and($journal[1]->meta['fallback'])->toBeTrue()
        ->and($journal[1]->model)->toBe('modele/renforce');
});

it('garde la proposition la mieux notée après cascade', function () {
    $moinsBon = json_encode(array_merge(
        json_decode(fixtureIa('facture-energie'), true),
        ['confidence' => 0.4],
    ));

    // Le repli fait pire que le premier : on garde le premier.
    $encoreMoinsBon = json_encode(array_merge(
        json_decode(fixtureIa('avis-taxe-fonciere'), true),
        ['confidence' => 0.2],
    ));

    Http::fake([ENDPOINT_IA => Http::sequence()
        ->push(reponseIa($moinsBon))
        ->push(reponseIa($encoreMoinsBon, modele: 'modele/renforce')),
    ]);

    $result = $this->service->analyze(deposerPdf($this->user), $this->property);

    expect($result->documentType)->toBe('expense_invoice')
        ->and($result->confidence)->toBe(0.4);
});

it('ne cascade pas quand aucun modèle de repli n\'est configuré', function () {
    // « none » = refus explicite : une chaîne vide retomberait désormais sur
    // le modèle de repli par défaut du fournisseur.
    config(['ai.models.documents_fallback' => 'none']);

    $peuSur = json_encode(array_merge(
        json_decode(fixtureIa('facture-energie'), true),
        ['confidence' => 0.2],
    ));

    Http::fake([ENDPOINT_IA => Http::response(reponseIa($peuSur))]);

    $result = $this->service->analyze(deposerPdf($this->user), $this->property);

    expect($result->fallbackUsed)->toBeFalse()
        ->and(AiUsageLog::withoutGlobalScopes()->count())->toBe(1);
});

it('remonte une erreur quand la réparation et la cascade échouent toutes deux', function () {
    Http::fake([ENDPOINT_IA => Http::sequence()
        ->push(reponseIa('pas du JSON'))
        ->push(reponseIa('toujours pas'))
        ->push(reponseIa('décidément pas', modele: 'modele/renforce')),
    ]);

    expect(fn () => $this->service->analyze(deposerPdf($this->user), $this->property))
        ->toThrow(AiBadResponse::class);
});

it('traduit une coupure réseau en délai dépassé', function () {
    Http::fake(fn () => throw new ConnectionException('timeout'));

    expect(fn () => $this->service->analyze(deposerPdf($this->user), $this->property))
        ->toThrow(AiTimeout::class);
});

// =============================================================================
// Fichier
// =============================================================================

it('refuse un fichier dont le type réel n\'est pas analysable', function () {
    $chemin = "documents/{$this->user->id}/ai-staging/piege.pdf";
    Storage::disk()->put($chemin, 'PK' . "\x03\x04" . str_repeat('x', 200));

    expect(fn () => $this->service->analyze($chemin, $this->property))
        ->toThrow(RuntimeException::class);
});

it('oriente vers le JPEG quand une photo HEIC est déposée', function () {
    // Le type est déterminé par les octets, pas par l'extension.
    expect(fn () => DocumentFile::fromContents(
        "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00" . str_repeat("\x00", 64),
        'photo.heic',
    ))->toThrow(RuntimeException::class, 'HEIC');
});

it('refuse un fichier au-delà de 10 Mo', function () {
    expect(fn () => DocumentFile::fromContents(str_repeat('a', 11 * 1024 * 1024), 'gros.pdf'))
        ->toThrow(RuntimeException::class, '10 Mo');
});

// =============================================================================
// Repli texte pour les serveurs sans prise en charge des fichiers
// =============================================================================

it('envoie la couche texte du PDF quand le service ne sait pas recevoir de fichiers', function () {
    config(['ai.pdf_text_fallback' => 'always']);

    Http::fake([ENDPOINT_IA => Http::response(reponseIa(fixtureIa('facture-energie')))]);

    // PDF minimal porteur d'une vraie couche texte.
    $chemin = "documents/{$this->user->id}/ai-staging/texte.pdf";
    Storage::disk()->put($chemin, pdfAvecTexte('Facture EDF Total TTC 143,20 EUR du 04/02/2026'));

    $this->service->analyze($chemin, $this->property);

    Http::assertSent(function ($request) {
        $corps = $request->data();
        $parties = collect($corps['messages'])->pluck('content')->flatten(1);

        return $parties->contains(fn ($p) => is_array($p) && ($p['type'] ?? null) === 'text'
                && str_contains($p['text'] ?? '', 'Total TTC 143,20'))
            // Plus de partie `file` : le service ne saurait pas la lire.
            && ! $parties->contains(fn ($p) => is_array($p) && ($p['type'] ?? null) === 'file')
            // Ni de plugin de lecture de fichiers, qui n'aurait plus d'objet.
            && ! isset($corps['plugins']);
    });
});

it('oriente vers une image quand le PDF est un scan sans couche texte', function () {
    config(['ai.pdf_text_fallback' => 'always']);

    Http::fake();

    // PDF valide, mais sans un caractère de texte exploitable : c'est ce que
    // produit un scan. Rien ne doit partir chez le fournisseur.
    $chemin = "documents/{$this->user->id}/ai-staging/scan.pdf";
    Storage::disk()->put($chemin, pdfAvecTexte(''));

    expect(fn () => $this->service->analyze($chemin, $this->property))
        ->toThrow(RuntimeException::class, 'image (JPEG ou PNG)');

    Http::assertNothingSent();
});

it('détecte automatiquement qu\'un serveur local ne lit pas les PDF', function () {
    config(['ai.pdf_text_fallback' => 'auto']);

    // La question n'est plus « quel hôte » mais « ce fournisseur sait-il
    // recevoir un PDF » : le sens d'`auto` est identique pour les deux cas
    // historiques, et se transpose aux fournisseurs à venir.
    config(['ai.provider' => 'auto', 'ai.base_url' => 'http://ollama:11434/v1']);
    expect(PdfTextFallback::isRequiredFor(AiProviders::resolve()))->toBeTrue();

    config(['ai.provider' => 'auto', 'ai.base_url' => 'https://openrouter.ai/api/v1']);
    expect(PdfTextFallback::isRequiredFor(AiProviders::resolve()))->toBeFalse();
});

it('laisse l\'administrateur forcer le repli texte dans les deux sens', function () {
    // Une passerelle d'entreprise devant OpenRouter, ou à l'inverse un service
    // tiers qui gère les PDF, tromperait la détection automatique.
    config(['ai.provider' => 'openrouter', 'ai.base_url' => 'https://openrouter.ai/api/v1']);

    config(['ai.pdf_text_fallback' => 'always']);
    expect(PdfTextFallback::isRequiredFor(AiProviders::resolve()))->toBeTrue();

    config(['ai.provider' => 'auto', 'ai.base_url' => 'http://ollama:11434/v1', 'ai.pdf_text_fallback' => 'never']);
    expect(PdfTextFallback::isRequiredFor(AiProviders::resolve()))->toBeFalse();
});

// =============================================================================
// Accès et quotas
// =============================================================================

it('refuse d\'analyser pour un compte de démonstration', function () {
    $demo = User::factory()->create(['email' => 'demo@openlmnp.fr']);
    Auth::setUser($demo);

    Http::fake();

    expect(fn () => $this->service->analyze(deposerPdf($this->user), $this->property))
        ->toThrow(AiUnavailable::class);

    Http::assertNothingSent();
});

it('refuse d\'analyser au-delà du quota mensuel et journalise le refus', function () {
    config(['ai.docs_monthly_quota' => 2]);

    foreach (range(1, 2) as $ignored) {
        AiUsageLog::create([
            'user_id' => $this->user->id,
            'feature' => 'documents',
            'model' => 'modele/rapide',
            'key_source' => 'instance',
            'status' => 'ok',
            'prompt_tokens' => 10,
            'completion_tokens' => 10,
            'duration_ms' => 100,
            'created_at' => now(),
        ]);
    }

    Http::fake();

    expect(fn () => $this->service->analyze(deposerPdf($this->user), $this->property))
        ->toThrow(AiUnavailable::class, 'Quota mensuel atteint');

    Http::assertNothingSent();

    expect(AiUsageLog::withoutGlobalScopes()->where('status', 'quota')->count())->toBe(1);
});

it('ne compte pas les appels automatiques de suivi dans le quota', function () {
    config(['ai.docs_monthly_quota' => 1]);

    // Une cascade produit deux appels : le second ne doit pas consommer le quota
    // de l'utilisateur, qui n'a demandé qu'une seule analyse.
    $peuSur = json_encode(array_merge(json_decode(fixtureIa('facture-energie'), true), ['confidence' => 0.3]));

    Http::fake([ENDPOINT_IA => Http::sequence()
        ->push(reponseIa($peuSur))
        ->push(reponseIa(fixtureIa('facture-energie'), modele: 'modele/renforce')),
    ]);

    $this->service->analyze(deposerPdf($this->user), $this->property);

    expect(\App\Support\AiQuota::for($this->user->refresh(), 'documents')->used())->toBe(1);
});

// =============================================================================
// Création (apply)
// =============================================================================

it('crée la charge validée par l\'utilisateur et y rattache le justificatif', function () {
    Http::fake([ENDPOINT_IA => Http::response(reponseIa(fixtureIa('facture-energie')))]);

    $chemin = deposerPdf($this->user);
    $result = $this->service->analyze($chemin, $this->property)->withFile($chemin, 'facture.pdf');

    // L'utilisateur corrige le montant avant de valider : c'est SA valeur qui compte.
    $valides = array_merge($result->values, ['amount' => 15000]);

    $expense = $this->service->apply($result, $valides, $this->user);

    expect($expense)->toBeInstanceOf(Expense::class)
        ->and($expense->amount)->toBe(15000)
        ->and($expense->category)->toBe('energy')
        // Le saving() du modèle recalcule la ventilation de TVA.
        ->and($expense->amount_ht)->toBe(12500);

    $document = Document::first();

    expect($document)->not->toBeNull()
        ->and($document->documentable_id)->toBe($expense->id)
        ->and($document->file_path)->toStartWith("documents/{$this->user->id}/")
        // Le fichier a quitté la zone de dépôt temporaire.
        ->and(Storage::disk()->exists($chemin))->toBeFalse()
        ->and(Storage::disk()->exists($document->file_path))->toBeTrue();
});

it('crée une ligne de mobilier par article coché', function () {
    Http::fake([ENDPOINT_IA => Http::response(reponseIa(fixtureIa('facture-mobilier')))]);

    $chemin = deposerPdf($this->user);
    $result = $this->service->analyze($chemin, $this->property)->withFile($chemin, 'facture.pdf');

    $rows = $result->rows;
    $rows[0]['selected'] = false; // l'utilisateur écarte le canapé

    $created = $this->service->apply($result, array_merge($result->values, ['rows' => $rows]), $this->user);

    expect($created)->toHaveCount(3)
        ->and(Furniture::count())->toBe(3)
        ->and(Furniture::where('description', 'Canapé convertible 3 places')->exists())->toBeFalse()
        // Un justificatif par ligne créée, tous sur le même fichier.
        ->and(Document::count())->toBe(3)
        ->and(Document::distinct('file_path')->count('file_path'))->toBe(1);
});

it('importe un tableau d\'amortissement et le protège du recalcul', function () {
    Http::fake([ENDPOINT_IA => Http::response(reponseIa(fixtureIa('tableau-amortissement')))]);

    $chemin = deposerPdf($this->user, 'pret.pdf');
    $result = $this->service->analyze($chemin, $this->property)->withFile($chemin, 'pret.pdf');

    expect($result->warnings)->toBe([]);

    $loan = $this->service->apply($result, $result->values, $this->user);

    expect($loan)->toBeInstanceOf(Loan::class)
        ->and($loan->schedule_source)->toBe(Loan::SCHEDULE_IMPORTED)
        ->and($loan->hasImportedSchedule())->toBeTrue()
        ->and($loan->payments()->count())->toBe(12)
        // Les intérêts réels alimentent directement le résultat fiscal.
        ->and($loan->getInterestsForYear(2026))->toBeGreaterThan(0);

    // La garde empêche l'écrasement silencieux du tableau de la banque.
    expect(app(\App\Services\LoanService::class)->generateSchedule($loan))->toBeFalse()
        ->and($loan->payments()->count())->toBe(12);
});

it('attribue le badge de première analyse', function () {
    $this->seed(\Database\Seeders\BadgeSeeder::class);

    Http::fake([ENDPOINT_IA => Http::response(reponseIa(fixtureIa('facture-energie')))]);

    $chemin = deposerPdf($this->user);
    $result = $this->service->analyze($chemin, $this->property)->withFile($chemin, 'facture.pdf');

    $this->service->apply($result, $result->values, $this->user);

    expect($this->user->hasBadge('lecteur_automatique'))->toBeTrue();
});

// =============================================================================
// Relevé bancaire
// =============================================================================

it('ne coche pas les opérations déjà saisies d\'un relevé', function () {
    // Une charge identique existe déjà : la réimporter la compterait deux fois.
    Expense::create([
        'property_id' => $this->property->id,
        'expense_date' => '2026-03-05',
        'amount' => 14320,
        'tva_rate' => 0,
        'category' => 'energy',
        'description' => 'EDF février',
        'is_dedicated' => true,
        'recurring_type' => 'once',
    ]);

    Http::fake([ENDPOINT_IA => Http::response(reponseIa(fixtureIa('releve-bancaire')))]);

    $result = $this->service->analyze(deposerPdf($this->user, 'releve.pdf'), $this->property);

    $edf = collect($result->rows)->firstWhere('label', 'PRLV EDF FACTURE FEVRIER');

    expect($edf['duplicate'])->toBeTrue()
        ->and($edf['selected'])->toBeFalse();
});

it('crée en lot les seules opérations cochées d\'un relevé', function () {
    Http::fake([ENDPOINT_IA => Http::response(reponseIa(fixtureIa('releve-bancaire')))]);

    $chemin = deposerPdf($this->user, 'releve.pdf');
    $result = $this->service->analyze($chemin, $this->property)->withFile($chemin, 'releve.pdf');

    $created = $this->service->apply($result, array_merge($result->values, ['rows' => $result->rows]), $this->user);

    expect(Expense::count())->toBe(3)
        ->and(\App\Models\Income::count())->toBe(1)
        // L'échéance de prêt et les lignes personnelles sont écartées.
        ->and(Expense::where('description', 'ECHEANCE PRET IMMOBILIER 4471')->exists())->toBeFalse()
        ->and(Expense::where('description', 'CB RESTAURANT LE COMPTOIR')->exists())->toBeFalse()
        // Un justificatif par écriture, un seul fichier sur le disque.
        ->and(Document::count())->toBe($created->count())
        ->and(Document::distinct('file_path')->count('file_path'))->toBe(1);
});

it('ne stocke jamais l\'IBAN complet d\'un relevé', function () {
    Http::fake([ENDPOINT_IA => Http::response(reponseIa(fixtureIa('releve-bancaire')))]);

    $result = $this->service->analyze(deposerPdf($this->user, 'releve.pdf'), $this->property);

    expect($result->values['iban_last4'])->toBe('0189')
        ->and(strlen((string) $result->values['iban_last4']))->toBe(4);
});

// =============================================================================
// Journal
// =============================================================================

it('ne journalise aucune valeur extraite du document', function () {
    Http::fake([ENDPOINT_IA => Http::response(reponseIa(fixtureIa('facture-energie')))]);

    $this->service->analyze(deposerPdf($this->user), $this->property);

    $meta = json_encode(AiUsageLog::withoutGlobalScopes()->first()->meta);

    expect($meta)->not->toContain('143.20')
        ->and($meta)->not->toContain('EDF')
        ->and($meta)->not->toContain('rue des Lilas')
        ->and($meta)->not->toContain('%PDF');
});

it('journalise le coût réel renvoyé par le fournisseur', function () {
    Http::fake([ENDPOINT_IA => Http::response(reponseIa(fixtureIa('facture-energie')))]);

    travelTo(now());

    $this->service->analyze(deposerPdf($this->user), $this->property);

    $ligne = AiUsageLog::withoutGlobalScopes()->first();

    expect($ligne->status)->toBe('ok')
        ->and($ligne->prompt_tokens)->toBe(4000)
        // decimal:6 : une CHAÎNE, jamais un float (les coûts se cumulent en bcmath).
        ->and($ligne->cost_usd)->toBeString()
        ->and(bccomp($ligne->cost_usd, '0.002400', 6))->toBe(0);
});

// =============================================================================
// Réponses encadrées par un bloc de code Markdown
// =============================================================================

it('décode un JSON encadré par un bloc de code Markdown', function (string $enveloppe) {
    // Mesuré en conditions réelles le 2026-08-21 : `anthropic/claude-sonnet-5`
    // servi par Amazon Bedrock IGNORE response_format: json_schema et encadre
    // son JSON de ```json … ```. Sans ce nettoyage, le modèle de repli — celui
    // qu'on appelle justement quand le premier a douté — est inutilisable.
    $contenu = str_replace('{JSON}', fixtureIa('facture-energie'), $enveloppe);

    Http::fake([ENDPOINT_IA => Http::response(reponseIa($contenu))]);

    $result = $this->service->analyze(deposerPdf($this->user), $this->property);

    expect($result->values['amount'])->toBe(14320)
        ->and($result->documentType)->toBe('expense_invoice');
})->with([
    'bloc json' => ["```json\n{JSON}\n```"],
    'bloc sans langue' => ["```\n{JSON}\n```"],
    'bloc avec espaces autour' => ["  ```json\n{JSON}\n```  "],
    'sans bloc' => ['{JSON}'],
]);

it('échoue toujours sur un contenu qui n\'est pas du JSON', function () {
    // Le nettoyage du bloc Markdown ne doit pas rendre tolérant à n'importe quoi.
    Http::fake([ENDPOINT_IA => Http::sequence()
        ->push(reponseIa("```json\nceci n'est pas du JSON\n```"))
        ->push(reponseIa("```\ntoujours pas\n```"))
        ->push(reponseIa('ni comme ça', modele: 'modele/renforce')),
    ]);

    expect(fn () => $this->service->analyze(deposerPdf($this->user), $this->property))
        ->toThrow(AiBadResponse::class);
});

// =============================================================================
// Liasse 2033 (lot 6 — assistant de reprise)
// =============================================================================

it('extrait une liasse 2033 : cases en centimes, lignes du 2033-C et déficits', function () {
    Http::fake([ENDPOINT_IA => Http::response(reponseIa(fixtureIa('liasse-2033')))]);

    $result = $this->service->analyze(deposerPdf($this->user), $this->property, forcedType: 'liasse_2033');

    expect($result->documentType)->toBe('liasse_2033')
        ->and($result->entity())->toBe('reprise')
        ->and($result->values['fiscal_year'])->toBe(2025)
        ->and($result->values['gross_assets'])->toBe(20000000)
        ->and($result->values['accumulated_depreciation'])->toBe(4739400)
        ->and($result->values['deferred_depreciation'])->toBe(1248000)
        ->and($result->values['declared_total_depreciation'])->toBe(719900)
        ->and($result->values['fiscal_result_deficit'])->toBe(85000)
        ->and($result->values['deficits'])->toBe([['origin_year' => 2023, 'amount' => 125000, 'confidence' => 0.93]])
        ->and($result->rows)->toHaveCount(8)
        // Somme des dotations lues : 7 200,00 € pour une ligne 572 à 7 199,00 € —
        // l'écart d'1 € tient dans la tolérance, aucun signalement.
        ->and($result->warnings)->toBe([]);

    // La durée absente du 2033-C est reconstituée par base ÷ dotation, et la
    // ligne Cerfa est suggérée d'après l'intitulé.
    $menuiseries = collect($result->rows)->firstWhere('label', 'Menuiseries extérieures');
    $grosOeuvre = collect($result->rows)->firstWhere('label', 'Gros œuvre');

    expect($menuiseries['base'])->toBe(400000)
        ->and($menuiseries['duration_years'])->toBe(20)
        ->and($grosOeuvre['duration_years'])->toBe(50)
        ->and($grosOeuvre['cumul'])->toBe(1040000)
        ->and($grosOeuvre['cerfa_category'])->toBe(\App\Models\PropertyComponent::CERFA_CATEGORY_CONSTRUCTIONS);

    // Les frais d'acquisition sont marqués « incorporelles » : l'assistant de
    // reprise les montre mais ne les recopie pas, l'application les amortit déjà.
    $frais = collect($result->rows)->firstWhere('label', 'Frais d\'acquisition');

    expect($frais['cerfa_category'])->toBe(\App\Models\PropertyComponent::CERFA_CATEGORY_INTANGIBLE);
});

it('range chaque ligne du 2033-C dans sa rubrique d\'après l\'intitulé du formulaire', function () {
    // D9 : « Constructions » tombait en « Autres immobilisations (470 / 560) », le
    // rattachement ne connaissant que les noms du catalogue de l'application.
    $payload = json_decode(fixtureIa('liasse-2033'), true);
    $payload['depreciation'] = [
        ['label' => 'Constructions', 'base' => '150000.00', 'duration_years' => 40, 'annual' => '3750.00', 'cumul' => '3750.00', 'confidence' => 0.9],
        ['label' => 'Installations techniques, matériel et outillage', 'base' => '9000.00', 'duration_years' => 20, 'annual' => '450.00', 'cumul' => '450.00', 'confidence' => 0.9],
        ['label' => 'Installations générales, agencements, aménagements divers', 'base' => '6000.00', 'duration_years' => 10, 'annual' => '600.00', 'cumul' => '600.00', 'confidence' => 0.9],
        ['label' => 'Autres immobilisations corporelles', 'base' => '2000.00', 'duration_years' => 5, 'annual' => '400.00', 'cumul' => '400.00', 'confidence' => 0.9],
    ];

    Http::fake([ENDPOINT_IA => Http::response(reponseIa((string) json_encode($payload)))]);

    $result = $this->service->analyze(deposerPdf($this->user), $this->property, forcedType: 'liasse_2033');

    expect(array_column($result->rows, 'cerfa_category'))->toBe([
        \App\Models\PropertyComponent::CERFA_CATEGORY_CONSTRUCTIONS,
        \App\Models\PropertyComponent::CERFA_CATEGORY_INSTALLATIONS,
        \App\Models\PropertyComponent::CERFA_CATEGORY_FITTINGS,
        \App\Models\PropertyComponent::CERFA_CATEGORY_OTHER,
    ]);
});

it('lit la case 044, total des immobilisations brutes, à part de la case 028', function () {
    $payload = json_decode(fixtureIa('liasse-2033'), true);
    $payload['fields'][] = ['name' => 'case_014', 'value' => '16000.00', 'confidence' => 0.9, 'evidence' => '2033-A case 014'];
    $payload['fields'][] = ['name' => 'case_044', 'value' => '216000.00', 'confidence' => 0.9, 'evidence' => '2033-A case 044'];

    Http::fake([ENDPOINT_IA => Http::response(reponseIa((string) json_encode($payload)))]);

    $result = $this->service->analyze(deposerPdf($this->user), $this->property, forcedType: 'liasse_2033');

    expect($result->values['gross_assets'])->toBe(20000000)
        ->and($result->values['intangible_assets'])->toBe(1600000)
        ->and($result->values['total_gross_assets'])->toBe(21600000);
});

it('ne transforme jamais une case absente de la liasse en zéro', function () {
    $payload = json_decode(fixtureIa('liasse-2033'), true);
    // Les cases 030 lue retirée, 014 jamais présente : toutes deux doivent rester null.
    $payload['fields'] = array_values(array_filter(
        $payload['fields'],
        fn (array $field) => $field['name'] !== 'case_030',
    ));

    Http::fake([ENDPOINT_IA => Http::response(reponseIa((string) json_encode($payload)))]);

    $result = $this->service->analyze(deposerPdf($this->user), $this->property, forcedType: 'liasse_2033');

    expect($result->values['accumulated_depreciation'])->toBeNull()
        ->and($result->values['intangible_assets'])->toBeNull()
        ->and($result->values['fiscal_result_profit'])->toBeNull();
});

it('replie les amortissements différés sur la case 318 quand la 870 est vide, et le signale', function () {
    $payload = json_decode(fixtureIa('liasse-2033'), true);
    $payload['fields'] = array_values(array_filter(
        $payload['fields'],
        fn (array $field) => $field['name'] !== 'case_870',
    ));
    $payload['fields'][] = ['name' => 'case_318', 'value' => '3120.00', 'confidence' => 0.9, 'evidence' => '2033-B case 318'];

    Http::fake([ENDPOINT_IA => Http::response(reponseIa((string) json_encode($payload)))]);

    $result = $this->service->analyze(deposerPdf($this->user), $this->property, forcedType: 'liasse_2033');

    expect($result->values['deferred_depreciation'])->toBe(312000)
        ->and(implode(' ', $result->warnings))->toContain('case 318');
});

it('signale quand la somme des dotations lues ne retombe pas sur la ligne 572', function () {
    $payload = json_decode(fixtureIa('liasse-2033'), true);
    // Le modèle n'a lu que la moitié du tableau : le contrôle de complétude doit le dire.
    $payload['depreciation'] = array_slice($payload['depreciation'], 0, 4);

    Http::fake([ENDPOINT_IA => Http::response(reponseIa((string) json_encode($payload)))]);

    $result = $this->service->analyze(deposerPdf($this->user), $this->property, forcedType: 'liasse_2033');

    expect($result->rows)->toHaveCount(4)
        ->and(implode(' ', $result->warnings))->toContain('572');
});

it('accorde à la liasse la fenêtre de sortie longue des documents à lignes multiples', function () {
    Http::fake([ENDPOINT_IA => Http::response(reponseIa(fixtureIa('liasse-2033')))]);

    $this->service->analyze(deposerPdf($this->user), $this->property, forcedType: 'liasse_2033');

    $requete = Http::recorded()[0][0]->data();

    expect($requete['max_tokens'])->toBe(16_000);
});

it('refuse d\'enregistrer une liasse : elle se reprend depuis l\'assistant dédié', function () {
    Http::fake([ENDPOINT_IA => Http::response(reponseIa(fixtureIa('liasse-2033')))]);

    $result = $this->service->analyze(deposerPdf($this->user), $this->property, forcedType: 'liasse_2033');

    expect(fn () => $this->service->apply($result, [], $this->user))
        ->toThrow(RuntimeException::class, 'Reprendre un dossier existant');
});

// =============================================================================
// `is_dedicated` — le repli des lignes détaillées
// =============================================================================

it('crée du mobilier DÉDIÉ quand la clé est absente, comme la branche sans lignes', function () {
    // ⚠️ Les deux branches de createFurniture() divergeaient. Celle sans lignes passe
    // par only(), qui omet la clé absente et laisse le DÉFAUT DE LA COLONNE (`true`)
    // s'appliquer ; celle avec lignes forçait `false`, donc la quote-part de surface.
    // Conséquence : une dotation rabotée et un bien qui ne s'amortit jamais
    // entièrement — sans message, sans test, sans rien d'observable.
    Http::fake([ENDPOINT_IA => Http::response(reponseIa(fixtureIa('facture-mobilier')))]);

    $chemin = deposerPdf($this->user);
    $result = $this->service->analyze($chemin, $this->property)->withFile($chemin, 'facture.pdf');

    $valeurs = $result->values;
    unset($valeurs['is_dedicated']);          // l'écran n'a rien transmis
    $valeurs['rows'] = $result->rows;

    $created = $this->service->apply($result, $valeurs, $this->user);

    expect($created)->not->toBeEmpty();

    foreach ($created as $meuble) {
        expect($meuble->is_dedicated)->toBeTrue();
    }
});

it('respecte un « partagé » choisi à la main sur une facture multi-articles', function () {
    // L'inverse doit tenir aussi : un `false` EXPLICITE ne doit pas être écrasé par
    // le nouveau repli.
    Http::fake([ENDPOINT_IA => Http::response(reponseIa(fixtureIa('facture-mobilier')))]);

    $chemin = deposerPdf($this->user);
    $result = $this->service->analyze($chemin, $this->property)->withFile($chemin, 'facture.pdf');

    $created = $this->service->apply(
        $result,
        array_merge($result->values, ['is_dedicated' => false, 'rows' => $result->rows]),
        $this->user,
    );

    foreach ($created as $meuble) {
        expect($meuble->is_dedicated)->toBeFalse();
    }
});

// =============================================================================
// Relevé annuel de plateforme
// =============================================================================

it('crée une recette par mois loué, au brut, avec la commission à part', function () {
    Http::fake([ENDPOINT_IA => Http::response(reponseIa(fixtureIa('releve-annuel-airbnb')))]);

    $chemin = deposerPdf($this->user, 'releve.pdf');
    $result = $this->service->analyze($chemin, $this->property)->withFile($chemin, 'releve.pdf');
    $created = $this->service->apply($result, array_merge($result->values, ['rows' => $result->rows]), $this->user);

    expect($created)->toHaveCount(9)
        ->and(\App\Models\Income::sum('amount'))->toBe(1358050)
        ->and(\App\Models\Income::sum('platform_fee'))->toBe(53890)
        // Le net versé du relevé se reconstitue au centime.
        ->and(\App\Models\Income::sum('amount') - \App\Models\Income::sum('platform_fee'))->toBe(1304160);
});

it('n\'invente ni taxe de séjour ni code de réservation', function () {
    Http::fake([ENDPOINT_IA => Http::response(reponseIa(fixtureIa('releve-annuel-airbnb')))]);

    $chemin = deposerPdf($this->user, 'releve.pdf');
    $result = $this->service->analyze($chemin, $this->property)->withFile($chemin, 'releve.pdf');
    $this->service->apply($result, array_merge($result->values, ['rows' => $result->rows]), $this->user);

    foreach (\App\Models\Income::all() as $recette) {
        expect($recette->tourist_tax)->toBe(0)
            ->and($recette->reservation_ref)->toBeNull()
            ->and($recette->guest_name)->toBeNull()
            ->and($recette->source)->toBe('airbnb');
    }
});

it('ne crée que les mois cochés', function () {
    Http::fake([ENDPOINT_IA => Http::response(reponseIa(fixtureIa('releve-annuel-airbnb')))]);

    $chemin = deposerPdf($this->user, 'releve.pdf');
    $result = $this->service->analyze($chemin, $this->property)->withFile($chemin, 'releve.pdf');

    $rows = $result->rows;
    $rows[0]['selected'] = false;

    $this->service->apply($result, array_merge($result->values, ['rows' => $rows]), $this->user);

    expect(\App\Models\Income::count())->toBe(8);
});

it('l\'année corrigée à l\'écran fait foi pour toutes les dates', function () {
    // ⚠️ Sans ça, corriger l'année dans le formulaire ne changerait rien aux recettes
    // écrites — le champ serait décoratif.
    Http::fake([ENDPOINT_IA => Http::response(reponseIa(fixtureIa('releve-annuel-airbnb')))]);

    $chemin = deposerPdf($this->user, 'releve.pdf');
    $result = $this->service->analyze($chemin, $this->property)->withFile($chemin, 'releve.pdf');

    $this->service->apply(
        $result,
        array_merge($result->values, ['fiscal_year' => 2024, 'rows' => $result->rows]),
        $this->user,
    );

    expect(\App\Models\Income::pluck('income_date')->map(fn ($d) => $d->year)->unique()->all())->toBe([2024])
        // Février 2024 est bissextile : la recomposition doit le savoir.
        ->and(\App\Models\Income::orderBy('income_date')->first()->income_date->toDateString())->toBe('2024-02-29');
});

it('marque en doublon un relevé déjà importé', function () {
    Http::fake([ENDPOINT_IA => Http::response(reponseIa(fixtureIa('releve-annuel-airbnb')))]);

    $chemin = deposerPdf($this->user, 'releve.pdf');
    $result = $this->service->analyze($chemin, $this->property)->withFile($chemin, 'releve.pdf');
    $this->service->apply($result, array_merge($result->values, ['rows' => $result->rows]), $this->user);

    // Second dépôt du MÊME relevé : dates déterministes, montants identiques.
    $chemin2 = deposerPdf($this->user, 'releve-bis.pdf');
    $second = $this->service->analyze($chemin2, $this->property);

    expect(collect($second->rows)->where('duplicate', true))->toHaveCount(9)
        ->and(collect($second->rows)->where('selected', true))->toBeEmpty();
});

it('décoche tout et prévient quand l\'exercice porte déjà des recettes', function () {
    // ⚠️ LE risque réel : les recettes viennent déjà d'un import CSV, sous une autre
    // maille. Les montants ne coïncident jamais, DuplicateFinder est aveugle, et
    // l'année serait comptée deux fois.
    \App\Models\Income::create([
        'property_id' => $this->property->id,
        'income_date' => '2025-07-14',
        'amount' => 42300,          // un montant qui ne correspond à AUCUNE ligne mensuelle
        'tva_rate' => 0,
        'source' => 'airbnb',
    ]);

    Http::fake([ENDPOINT_IA => Http::response(reponseIa(fixtureIa('releve-annuel-airbnb')))]);

    $chemin = deposerPdf($this->user, 'releve.pdf');
    $result = $this->service->analyze($chemin, $this->property);

    expect(collect($result->rows)->where('selected', true))->toBeEmpty()
        ->and(collect($result->warnings)->filter(fn ($w) => str_contains($w, 'déjà')))->not->toBeEmpty();
});

it('attache le relevé aux recettes, avec une date de document exploitable', function () {
    // ⚠️ Sans `period_end` dans la chaîne de document_date, la pièce serait classée
    // « sans-annee » par DocumentExportService.
    Http::fake([ENDPOINT_IA => Http::response(reponseIa(fixtureIa('releve-annuel-airbnb')))]);

    $chemin = deposerPdf($this->user, 'releve.pdf');
    $result = $this->service->analyze($chemin, $this->property)->withFile($chemin, 'releve.pdf');
    $this->service->apply($result, array_merge($result->values, ['rows' => $result->rows]), $this->user);

    expect(Document::count())->toBe(9)
        ->and(Document::distinct('file_path')->count('file_path'))->toBe(1)
        ->and(Document::first()->document_date)->not->toBeNull()
        ->and(Document::first()->file_path)->toContain('/recettes/');
});

it('suit le régime de TVA du bien', function () {
    $this->property->forceFill(['tva_regime' => 'liable'])->save();

    Http::fake([ENDPOINT_IA => Http::response(reponseIa(fixtureIa('releve-annuel-airbnb')))]);

    $chemin = deposerPdf($this->user, 'releve.pdf');
    $result = $this->service->analyze($chemin, $this->property->refresh())->withFile($chemin, 'releve.pdf');
    $this->service->apply($result, array_merge($result->values, ['rows' => $result->rows]), $this->user);

    // Le hook `saving` d'Income dérive tva_collected du taux : un 0 en dur aurait
    // sous-déclaré la TVA collectée, en silence.
    expect(\App\Models\Income::first()->tva_rate)->toBe(1000)
        ->and(\App\Models\Income::sum('tva_collected'))->toBeGreaterThan(0);
});
