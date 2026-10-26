<?php

use App\Models\Property;
use App\Models\User;
use App\Services\Ai\Assistant\ToolBridge;

/**
 * Pont vers les outils MCP : allowlist verrouillée, définitions exploitables par le
 * modèle, exécution interne sous l'identité de l'utilisateur connecté.
 */

/** Un bien minimal, avec les colonnes réellement présentes en base. */
function bienDeTest(User $user, string $name): Property
{
    return Property::forceCreate([
        'user_id' => $user->id,
        'name' => $name,
        'address' => '1 rue Test',
        'city' => 'Lyon',
        'postal_code' => '69000',
        'type' => 'apartment',
        'total_area' => 50,
        'rented_area' => 50,
        'acquisition_date' => '2022-01-01',
        'acquisition_price' => 30000000,
        'notary_fees' => 1500000,
        'land_percentage' => 15,
        'rental_start_date' => '2022-01-01',
        'rental_type' => 'seasonal',
        'tva_regime' => 'exempt',
    ]);
}

beforeEach(function () {
    $this->bridge = new ToolBridge;
    $this->user = User::factory()->create();
});

it('n\'expose que les 16 outils de lecture de l\'allowlist', function () {
    expect($this->bridge->allowed())->toBe([
        'list_properties', 'get_property',
        'list_fiscal_years', 'get_fiscal_year',
        'get_onboarding_status', 'get_dashboard_summary',
        'list_expenses', 'list_incomes',
        'list_loans', 'get_loan',
        'list_furniture', 'list_property_works', 'list_property_components',
        'list_categories',
        'compare_micro_bic', 'get_simulation',
    ]);
});

it('ne laisse passer aucun outil d\'écriture', function () {
    foreach (['create_expense', 'update_property', 'delete_income', 'generate_fec', 'export_documents'] as $tool) {
        expect($this->bridge->isAllowed($tool))->toBeFalse();
    }
});

it('génère une définition OpenAI par outil autorisé', function () {
    $definitions = $this->bridge->definitions();

    expect($definitions)->toHaveCount(count($this->bridge->allowed()));

    foreach ($definitions as $definition) {
        expect($definition['type'])->toBe('function')
            ->and($definition['function']['name'])->toBeIn($this->bridge->allowed())
            ->and($definition['function']['description'])->not->toBeEmpty()
            ->and($definition['function']['parameters'])->toHaveKey('type');
    }
});

it('décrit les paramètres attendus d\'un outil', function () {
    $definition = collect($this->bridge->definitions())
        ->firstWhere('function.name', 'get_property');

    expect($definition['function']['parameters']['properties'])->toHaveKey('property_id')
        ->and($definition['function']['parameters']['required'])->toContain('property_id');
});

it('exécute un outil de lecture et renvoie du JSON', function () {
    bienDeTest($this->user, 'Studio Lyon');

    $this->actingAs($this->user);

    $json = json_decode($this->bridge->call($this->user, 'list_properties', []), true);

    expect($json['count'])->toBe(1)
        ->and($json['properties'][0]['name'])->toBe('Studio Lyon');
});

it('refuse un outil hors allowlist', function () {
    $this->actingAs($this->user);

    $this->bridge->call($this->user, 'create_expense', []);
})->throws(InvalidArgumentException::class);

it('refuse d\'exécuter un outil pour un autre compte que celui connecté', function () {
    $autre = User::factory()->create();

    $this->actingAs($this->user);

    $this->bridge->call($autre, 'list_properties', []);
})->throws(InvalidArgumentException::class);

it('transforme une erreur d\'outil en message neutre', function () {
    $this->actingAs($this->user);

    // property_id manquant : l'outil valide et échoue, le modèle ne doit voir qu'un
    // message neutre (jamais la trace d'exception).
    $reponse = $this->bridge->call($this->user, 'get_property', []);

    expect($reponse)->toContain('error')
        ->and($reponse)->not->toContain('Exception')
        ->and($reponse)->not->toContain('vendor/');
});

it('signale un élément introuvable sans divulguer son existence', function () {
    $this->actingAs($this->user);

    $reponse = $this->bridge->call($this->user, 'get_property', ['property_id' => 99999]);

    expect(json_decode($reponse, true)['error'])->toBe('élément introuvable');
});

it('tronque un résultat trop long', function () {
    $this->actingAs($this->user);

    for ($i = 0; $i < 60; $i++) {
        bienDeTest($this->user, str_repeat("Bien numéro {$i} avec un nom volontairement très long ", 12));
    }

    $reponse = $this->bridge->call($this->user, 'list_properties', []);

    expect(mb_strlen($reponse))->toBeLessThanOrEqual(12100)
        ->and($reponse)->toContain('résultat tronqué');
});

it('donne un libellé lisible pour la ligne « Consulté »', function () {
    expect($this->bridge->labels(['list_properties', 'list_fiscal_years', 'list_properties']))
        ->toBe('vos biens, vos exercices');
});
