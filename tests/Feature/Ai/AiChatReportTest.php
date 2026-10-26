<?php

use App\Filament\Pages\AdminAi;
use App\Livewire\AiAssistant;
use App\Models\AiChatReport;
use App\Models\AiUsageLog;
use App\Models\Expense;
use App\Models\Income;
use App\Models\Loan;
use App\Models\Property;
use App\Models\User;
use App\Services\Ai\Assistant\ConversationRedactor;
use Livewire\Livewire;

/**
 * Pouce vers le bas + « joindre la conversation » : rien sans la case, et ce qui est
 * conservé ne contient aucune des valeurs propres au compte, ni aucun montant.
 */
beforeEach(function () {
    config(['ai.enabled' => true, 'ai.api_key' => 'sk-or-v1-factice', 'ai.provider' => 'openrouter',
        'ai.base_url' => 'https://openrouter.test/api/v1', 'demo.email' => 'demo@openlmnp.fr']);

    // Le premier compte d'une instance en devient l'administrateur : celui-ci ne doit pas l'être.
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->user = User::factory()->create(['name' => 'Camille Repère', 'email' => 'camille.repere@exemple.test', 'is_admin' => false]);
    $this->actingAs($this->user);

    $bien = Property::forceCreate([
        'user_id' => $this->user->id, 'name' => 'Studio Bellecour', 'address' => '12 rue des Lilas',
        'city' => 'Villeurbanne', 'postal_code' => '69100', 'type' => 'apartment',
        'total_area' => 40, 'rented_area' => 40, 'acquisition_date' => '2022-01-01',
        'acquisition_price' => 20000000, 'notary_fees' => 0, 'land_percentage' => 15,
        'rental_start_date' => '2022-03-01', 'rental_type' => 'seasonal', 'is_primary_residence' => false,
    ]);
    Income::forceCreate(['property_id' => $bien->id, 'income_date' => '2026-03-01', 'amount' => 80000,
        'platform_fee' => 0, 'tourist_tax' => 0, 'source' => 'airbnb', 'guest_name' => 'Ingrid Voyageuse']);
    Expense::create(['property_id' => $bien->id, 'category' => 'maintenance', 'description' => 'Réparation chaudière Atlantic',
        'amount' => 5000, 'tva_rate' => 0, 'expense_date' => '2026-02-10', 'is_dedicated' => true]);
    Loan::forceCreate(['property_id' => $bien->id, 'bank_name' => 'Banque Repère', 'amount' => 10000000,
        'annual_rate' => 2.0, 'duration_months' => 120, 'start_date' => '2024-01-01',
        'monthly_payment' => 0, 'insurance_monthly' => 0]);

    AiUsageLog::create(['user_id' => $this->user->id, 'feature' => 'chat', 'model' => 'm', 'key_source' => 'instance', 'status' => 'ok']);

    $this->messages = [
        ['role' => 'user', 'text' => 'Mon Studio Bellecour au 12 rue des Lilas, 69100 Villeurbanne : Ingrid Voyageuse a payé 1 234,56 € ?', 'html' => '', 'tools' => [], 'feedback' => null],
        ['role' => 'assistant', 'text' => "Oui : 800,00 € le 01/03/2026, et la charge « Réparation chaudière Atlantic » de 50 €. Prêt Banque Repère. Écrivez à camille.repere@exemple.test ou au 06 12 34 56 78, IBAN FR76 3000 6000 0112 3456 7890 189.", 'html' => '', 'tools' => [], 'feedback' => null],
    ];
});

const VALEURS_PRIVEES = ['Bellecour', 'Lilas', 'Villeurbanne', '69100', 'Ingrid', 'Atlantic', 'Banque Repère',
    'camille.repere', 'Camille Repère', '1 234,56', '800,00', '50 €', '06 12 34 56 78', 'FR76'];

it('ne conserve rien sans la case cochée', function () {
    Livewire::test(AiAssistant::class)->set('messages', $this->messages)
        ->call('feedback', 1, false, 'inexact');

    expect(AiChatReport::count())->toBe(0)
        ->and(AiUsageLog::first()->meta)->toMatchArray(['feedback' => 'down', 'reason' => 'inexact']);
});

it('conserve la conversation masquée quand la case est cochée', function () {
    Livewire::test(AiAssistant::class)->set('messages', $this->messages)
        ->call('feedback', 1, false, 'inexact', true)
        ->assertSee('la conversation masquée nous a été transmise');

    $report = AiChatReport::sole();
    $texte = json_encode($report->transcript, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    foreach (VALEURS_PRIVEES as $valeur) {
        expect($texte)->not->toContain($valeur);
    }

    expect($report->reason)->toBe('inexact')
        ->and($report->user_id)->toBe($this->user->id)
        ->and($report->transcript)->toHaveCount(2)
        ->and($texte)->toContain('[bien]')->toContain('[voyageur]')->toContain('[montant]')
        // La date reste lisible : elle sert au diagnostic et ne désigne personne.
        ->and($texte)->toContain('01/03/2026');
});

it('ne signale jamais sur un pouce levé', function () {
    Livewire::test(AiAssistant::class)->set('messages', $this->messages)
        ->call('feedback', 1, true, null, true);

    expect(AiChatReport::count())->toBe(0);
});

it('affiche avant l\'envoi exactement le texte masqué', function () {
    $apercu = Livewire::test(AiAssistant::class)->set('messages', $this->messages)
        ->instance()->sharePreview(1);

    expect($apercu)->toContain('[bien]')->not->toContain('Bellecour')->not->toContain('800,00');
});

it('ne masque pas les valeurs d\'un autre compte, faute de les connaître', function () {
    $autre = User::factory()->create();
    Property::forceCreate([
        'user_id' => $autre->id, 'name' => 'Loft Secret', 'address' => '1 rue X', 'city' => 'Nice',
        'postal_code' => '06000', 'type' => 'apartment', 'total_area' => 40, 'rented_area' => 40,
        'acquisition_date' => '2022-01-01', 'acquisition_price' => 1, 'notary_fees' => 0, 'land_percentage' => 15,
        'rental_start_date' => '2022-03-01', 'rental_type' => 'seasonal', 'is_primary_residence' => false,
    ]);

    // Les valeurs connues sont celles que les outils de l'assistant voient : jamais celles d'un tiers.
    expect(app(ConversationRedactor::class)->knownValues($this->user))->not->toHaveKey('Loft Secret');
});

it('purge les signalements au-delà de 90 jours', function () {
    AiChatReport::create(['user_id' => $this->user->id, 'transcript' => [], 'created_at' => now()->subDays(91)]);
    AiChatReport::create(['user_id' => $this->user->id, 'transcript' => [], 'created_at' => now()->subDays(10)]);

    $this->artisan('model:prune', ['--model' => [AiChatReport::class]]);

    expect(AiChatReport::count())->toBe(1);
});

it('survit à la suppression du compte, sans lien vers lui', function () {
    $report = AiChatReport::create(['user_id' => $this->user->id, 'transcript' => [['role' => 'user', 'text' => 'x']]]);

    app(App\Services\AccountDeletionService::class)->delete($this->user);

    expect($report->fresh()->user_id)->toBeNull();
});

it('montre les signalements aux administrateurs seulement', function () {
    AiChatReport::create(['user_id' => $this->user->id, 'reason' => 'hors-sujet', 'transcript' => [['role' => 'user', 'text' => 'Question [bien] témoin']]]);

    $this->get(AdminAi::getUrl())->assertForbidden();

    $this->actingAs($this->admin)->get(AdminAi::getUrl())
        ->assertOk()
        ->assertSee('Conversations signalées')
        ->assertSee('Question [bien] témoin');
});
