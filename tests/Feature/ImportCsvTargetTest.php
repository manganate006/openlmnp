<?php

use App\Filament\Pages\ImportCsv;
use App\Models\Property;
use App\Models\User;
use App\Services\Csv\CsvProfile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * La « nature des lignes » de l'import CSV suit l'écran d'où l'on vient.
 *
 * ⚠️ Ce défaut est silencieux et coûteux. La valeur était `TARGET_EXPENSE` en dur :
 * arriver depuis *Recettes* proposait « Charges ». Un utilisateur qui ne change pas le
 * champ importe ses loyers en charges, ce qui INVERSE le signe de son résultat fiscal —
 * et aucun écran ne s'en étonne, les montants restant plausibles.
 *
 * Deux filets, dans cet ordre :
 *   1. la **présélection**, qui vient de l'appelant et ne devine rien ;
 *   2. un **contrôle de cohérence** à l'aperçu, qui POSE une question quand les intitulés
 *      du fichier contredisent la nature choisie. Il ne tranche jamais : la liste
 *      d'intitulés qui le déclenche se périmera, et une garde qui impose finit par
 *      imposer la mauvaise valeur.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->property = importCsvProperty($this->user);
});

/**
 * Monte la page telle qu'on y arrive depuis un écran de liste.
 *
 * ⚠️ La présélection se lit dans la REQUÊTE : la jouer sans requête reviendrait à tester
 * le repli, et le test serait vert quoi qu'il arrive.
 */
function importCsvFrom(User $user, ?string $target): \Livewire\Features\SupportTesting\Testable
{
    $url = '/import-csv'.($target === null ? '' : '?target='.$target);

    return Livewire::actingAs($user)->withQueryParams($target === null ? [] : ['target' => $target])
        ->test(ImportCsv::class, [], ['url' => $url]);
}

it('propose la nature de l\'écran appelant', function () {
    foreach (CsvProfile::targetLabels() as $target => $label) {
        expect(importCsvFrom($this->user, $target)->get('data.target'))
            ->toBe($target, "Arrivée avec ?target={$target}");
    }
});

it('retombe sur les charges quand l\'appelant ne dit rien', function () {
    expect(importCsvFrom($this->user, null)->get('data.target'))->toBe(CsvProfile::TARGET_EXPENSE);
});

it('ignore une nature inventée dans l\'URL plutôt que de la retenir', function () {
    // `target` vient du navigateur : il peut valoir n'importe quoi.
    expect(importCsvFrom($this->user, 'patrimoine-occulte')->get('data.target'))
        ->toBe(CsvProfile::TARGET_EXPENSE);
});

it('laisse le champ modifiable après présélection', function () {
    $component = importCsvFrom($this->user, CsvProfile::TARGET_INCOME);

    $component->set('data.target', CsvProfile::TARGET_FURNITURE);

    expect($component->get('data.target'))->toBe(CsvProfile::TARGET_FURNITURE);
});

it('mène à l\'import CSV avec la nature de chaque écran de liste', function () {
    // Le lien de l'écran appelant EST la présélection : sans son paramètre, la page
    // n'a rien pour deviner, et le correctif ne vaut rien.
    $links = [
        '/incomes' => 'target=income',
        '/expenses' => 'target=expense',
        '/furniture' => 'target=furniture',
        '/property-works' => 'target=work',
    ];

    // Deux réglages, sans lesquels le test parlerait d'une page d'erreur ou d'une
    // redirection : Mobilier et Travaux sont masqués en mode Simple, et ces deux écrans
    // renvoient vers leur vue « par bien » quand un seul bien existe.
    $this->user->update(['nav_mode' => \App\Enums\NavMode::Advanced]);

    foreach ($links as $url => $expected) {
        $html = $this->actingAs($this->user)->followingRedirects()->get($url)->assertOk()->getContent();

        expect($html)->toContain('/import-csv?'.$expected);
    }
});

// ---------------------------------------------------------------------------------------
// Contrôle de cohérence à l'aperçu
// ---------------------------------------------------------------------------------------

it('demande confirmation quand le fichier ressemble à une autre nature', function () {
    // Un export de plateforme : deux intitulés qui n'existent que sur des recettes.
    $file = uploadedCsv("Code de confirmation;Nom du voyageur;Date;Montant\nHMABC;Dupont;15/03/2024;450,00\n");

    $component = Livewire::actingAs($this->user)
        ->test(ImportCsv::class)
        ->set('data.property_id', $this->property->id)
        ->set('data.target', CsvProfile::TARGET_EXPENSE)
        ->set('data.csv_file', $file)
        ->call('preview');

    expect($component->get('previewData')['suspected_target'])->toBe(CsvProfile::TARGET_INCOME);

    $component->assertSee('Est-ce bien la bonne nature')
        // Et la raison, celle qui justifie de poser la question.
        ->assertSee('inverse le signe de votre résultat fiscal');
});

it('ne dit rien quand la nature choisie est la bonne', function () {
    $file = uploadedCsv("Code de confirmation;Nom du voyageur;Date;Montant\nHMABC;Dupont;15/03/2024;450,00\n");

    $component = Livewire::actingAs($this->user)
        ->test(ImportCsv::class)
        ->set('data.property_id', $this->property->id)
        ->set('data.target', CsvProfile::TARGET_INCOME)
        ->set('data.csv_file', $file)
        ->call('preview');

    expect($component->get('previewData')['suspected_target'])->toBeNull();

    $component->assertDontSee('Est-ce bien la bonne nature');
});

it('se tait plutôt que de crier sur un seul indice', function () {
    // « Travaux » figure comme CATÉGORIE dans bien des tableaux de charges. Un indice
    // unique ne vaut rien : une alerte à chaque import apprendrait surtout à l'ignorer.
    $file = uploadedCsv("Date;Montant;Libellé;Travaux\n15/03/2024;250,00;Ramonage;non\n");

    $component = Livewire::actingAs($this->user)
        ->test(ImportCsv::class)
        ->set('data.property_id', $this->property->id)
        ->set('data.target', CsvProfile::TARGET_EXPENSE)
        ->set('data.csv_file', $file)
        ->call('preview');

    expect($component->get('previewData')['suspected_target'])->toBeNull();
});

it('n\'empêche jamais l\'import, même quand il doute', function () {
    // ⚠️ Le point le plus important du contrôle : c'est une question, pas un verrou. Un
    // fichier légitime dont les intitulés trompent la liste doit rester importable.
    $file = uploadedCsv("Code de confirmation;Nom du voyageur;Date;Montant\nHMABC;Dupont;15/03/2024;450,00\n");

    Livewire::actingAs($this->user)
        ->test(ImportCsv::class)
        ->set('data.property_id', $this->property->id)
        ->set('data.target', CsvProfile::TARGET_EXPENSE)
        ->set('data.csv_file', $file)
        ->call('preview')
        ->call('confirmImport');

    expect(\App\Models\Expense::withoutGlobalScopes()->count())->toBe(1);
});

it('ne devine la nature d\'aucun fichier sans intitulé reconnaissable', function () {
    // Deviner d'après le contenu est précisément ce que l'import refuse de faire pour le
    // mappage des colonnes. La présélection vient de l'appelant, jamais du fichier.
    expect(CsvProfile::suspectedTarget(CsvProfile::TARGET_EXPENSE, ['A', 'B', 'C']))->toBeNull()
        ->and(CsvProfile::suspectedTarget(CsvProfile::TARGET_EXPENSE, ['Date', 'Montant', 'Libellé']))->toBeNull();
});
