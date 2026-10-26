<?php

use App\Filament\Pages\RepriseDossier;
use App\Models\Property;
use App\Models\User;
use Livewire\Livewire;

/**
 * Les champs de date de `/reprise` : un seul et même sélecteur, partout.
 *
 * La page mélangeait deux conventions. Les dates de début de composant, dans l'éditeur
 * d'amortissements inclus à l'étape 3, étaient de vrais `type="date"` — calendrier natif,
 * valeur ISO 8601. Les deux dates saisies aux étapes 1 et 2 étaient du `type="text"`
 * déguisé, avec un gabarit « jj/mm/aaaa » pour toute aide : aucun calendrier, et un format
 * à deviner.
 *
 * ⚠️ Le piège n'est pas l'apparence, c'est le FORMAT ÉCRIT. `type="date"` rend et attend
 * de l'ISO 8601 ; une valeur relue depuis la base au format français n'y affiche
 * strictement RIEN — le champ paraît vide alors que la date existe, et repart vide à
 * l'enregistrement. Ce fichier teste donc l'aller-retour, pas seulement le balisage.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
});

/** Type déclaré par la balise `<input>` portant cet identifiant, dans du HTML rendu. */
function inputType(string $html, string $id): ?string
{
    if (! preg_match('/<input\b[^>]*\bid="'.preg_quote($id, '/').'"[^>]*>/i', $html, $tag)) {
        return null;
    }

    return preg_match('/\btype="([^"]+)"/i', $tag[0], $type) ? $type[1] : null;
}

it('offre un calendrier natif sur la date de mise en location', function () {
    $html = Livewire::actingAs($this->user)->test(RepriseDossier::class)->html();

    expect(inputType($html, 'rp-rental-start'))->toBe('date');
});

it('offre un calendrier natif sur la date d\'acquisition', function () {
    // Sans bien enregistré, l'étape 2 affiche les champs d'identité, dont la date
    // d'acquisition — c'est le seul chemin qui la rend.
    $html = Livewire::actingAs($this->user)
        ->test(RepriseDossier::class)
        ->set('rentalStartDate', '2019-06-01')
        ->set('firstYear', 2026)
        ->call('nextStep')
        ->assertSet('step', 2)
        ->html();

    expect(inputType($html, 'rp-acquisition-date'))->toBe('date');
});

it('n\'affiche plus nulle part un gabarit de date à recopier à la main', function () {
    // Le gabarit « jj/mm/aaaa » était l'aveu du champ texte : un vrai sélecteur de date
    // n'en a pas besoin, et le laisser ferait croire à un format imposé qui n'existe plus.
    $view = file_get_contents(resource_path('views/filament/pages/reprise-dossier.blade.php'));

    expect($view)->not->toContain('jj/mm/aaaa')
        ->and(file_get_contents(app_path('Filament/Pages/RepriseDossier.php')))
        ->not->toContain('jj/mm/aaaa');
});

it('relit une date déjà en base dans le format que le sélecteur affiche', function () {
    // ⚠️ LE test du lot : `displayDate()` rendait « 01/06/2019 », qu'un `type="date"`
    // ignore en silence. Le champ s'ouvrait vide sur un bien pourtant renseigné.
    $property = Property::forceCreate([
        'user_id' => $this->user->id,
        'name' => 'Appartement repris',
        'address' => '12 rue de la Liasse',
        'city' => 'Bordeaux',
        'postal_code' => '33000',
        'type' => 'apartment',
        'total_area' => 45,
        'rented_area' => 45,
        'acquisition_date' => '2019-06-01',
        'acquisition_price' => 20_000_000,
        'notary_fees' => 0,
        'agency_fees' => 0,
        'land_percentage' => 15,
        'rental_start_date' => '2019-07-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);

    $component = Livewire::actingAs($this->user)->test(RepriseDossier::class);

    expect($component->get('acquisitionDate'))->toBe('2019-06-01')
        ->and($component->get('rentalStartDate'))->toBe('2019-07-01');

    // Et la valeur relue repart telle quelle : l'aller-retour ne décale pas la date.
    $component->call('nextStep')->call('nextStep');

    $property->refresh();

    expect($property->acquisition_date->format('Y-m-d'))->toBe('2019-06-01')
        ->and($property->rental_start_date->format('Y-m-d'))->toBe('2019-07-01');
});

it('écrit en base l\'ISO 8601 que le sélecteur produit', function () {
    // ⚠️ Le sens de lecture qui manquait : le calendrier natif POSTE « 2019-06-01 ».
    // Vérifier qu'on sait relire l'ISO déjà en base ne dit rien de ce qui s'y écrit
    // depuis le nouveau champ. La colonne doit rester de l'ISO 8601, sans quoi tous les
    // calculs d'exercice qui s'appuient dessus partent en silence.
    Livewire::actingAs($this->user)
        ->test(RepriseDossier::class)
        ->set('rentalStartDate', '2019-06-01')
        ->set('firstYear', 2026)
        ->call('nextStep')
        ->set('propertyName', 'Studio ISO')
        ->set('propertyAddress', '1 rue Test')
        ->set('propertyCity', 'Bordeaux')
        ->set('propertyPostalCode', '33000')
        ->set('propertyArea', '30')
        ->set('acquisitionDate', '2018-04-12')
        ->set('acquisitionPrice', '100000')
        ->call('nextStep');

    $property = Property::withoutGlobalScopes()->where('user_id', $this->user->id)->firstOrFail();

    // La valeur BRUTE de la colonne, pas la lecture d'un cast qui normaliserait après coup.
    $raw = \Illuminate\Support\Facades\DB::table('properties')
        ->where('id', $property->id)
        ->first(['acquisition_date', 'rental_start_date']);

    expect(substr($raw->acquisition_date, 0, 10))->toBe('2018-04-12')
        ->and(substr($raw->rental_start_date, 0, 10))->toBe('2019-06-01');
});

it('accepte encore une date saisie à la française', function () {
    // Un navigateur sans `type="date"` retombe sur un champ texte : la lecture française
    // reste le filet, et « 01/06/2019 » doit rester le 1er juin, jamais le 6 janvier.
    Livewire::actingAs($this->user)
        ->test(RepriseDossier::class)
        ->set('rentalStartDate', '01/06/2019')
        ->set('firstYear', 2026)
        ->call('nextStep')
        ->assertSet('step', 2)
        ->set('propertyName', 'Studio')
        ->set('propertyAddress', '1 rue Test')
        ->set('propertyCity', 'Bordeaux')
        ->set('propertyPostalCode', '33000')
        ->set('propertyArea', '30')
        ->set('acquisitionPrice', '100000')
        ->call('nextStep');

    $property = Property::withoutGlobalScopes()->where('user_id', $this->user->id)->firstOrFail();

    expect($property->rental_start_date->format('Y-m-d'))->toBe('2019-06-01');
});
