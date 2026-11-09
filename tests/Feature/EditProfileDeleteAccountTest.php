<?php

use App\Enums\DossierMemberRole;
use App\Filament\Pages\EditProfile;
use App\Models\LinkedDossier;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;

const MOT_DE_PASSE_PROFIL = 'mot-de-passe-du-compte-a-supprimer';

/**
 * ⚠️ Même piège que `AccountDeletionServiceTest::utilisateurStandard()` : le premier
 * compte créé dans une base vide devient administrateur malgré lui
 * (`AppServiceProvider::boot()`), ce qui déclenche à tort `REFUSAL_LAST_ADMIN` dans des
 * tests qui ne portent pas sur ce refus.
 */
function utilisateurProfil(array $attributs = []): User
{
    $user = User::factory()->create($attributs);

    if (! array_key_exists('is_admin', $attributs) && $user->is_admin) {
        $user->forceFill(['is_admin' => false])->save();
    }

    return $user;
}

function dossierPourProfil(User $owner, string $siren = '100000009'): LinkedDossier
{
    $dossierUser = new User;
    $dossierUser->forceFill([
        'name' => 'Indivision profil',
        'email' => 'dossier-'.Str::lower((string) Str::ulid()).'@dossiers.invalid',
        'password' => Hash::make(Str::random(32)),
        'is_linked_dossier' => true,
        'is_indivision' => true,
        'siren' => $siren,
    ])->save();

    $dossier = LinkedDossier::create([
        'public_id' => (string) Str::ulid(),
        'user_id' => $dossierUser->id,
        'owner_id' => $owner->id,
        'kind' => 'indivision',
        'siren' => $siren,
    ]);

    $dossier->members()->create([
        'user_id' => $owner->id,
        'role' => DossierMemberRole::Owner,
        'accepted_at' => now(),
    ]);

    return $dossier;
}

beforeEach(function () {
    config(['services.provisioning.token' => null]);
});

// ⚠️ `assertSee`/`assertDontSee` sur la page entière sont impropres ici : la fiche
// d'aide contextuelle de « Mon profil » (resources/views/help/assistant.blade.php)
// nomme aussi « Supprimer mon compte » en texte statique, visible de tous — y compris
// un compte démo pour qui le BOUTON est masqué. On vérifie donc la visibilité de
// l'action Filament elle-même, pas une sous-chaîne de la page.

it('affiche l\'action pour un compte standard', function () {
    $user = utilisateurProfil(['password' => Hash::make(MOT_DE_PASSE_PROFIL)]);
    $this->actingAs($user);

    Livewire::test(EditProfile::class)->assertActionVisible('deleteAccount');
});

it('masque l\'action pour un compte de démonstration', function () {
    config(['demo.email' => 'demo@openlmnp.fr']);
    $demo = User::factory()->create(['is_demo' => true, 'demo_expires_at' => now()->addHour()]);
    $this->actingAs($demo);

    Livewire::test(EditProfile::class)->assertActionHidden('deleteAccount');
});

it('supprime le compte quand le mot de passe et l\'adresse sont corrects', function () {
    $user = utilisateurProfil(['password' => Hash::make(MOT_DE_PASSE_PROFIL)]);
    $this->actingAs($user);

    Livewire::test(EditProfile::class)
        ->callAction('deleteAccount', [
            'password' => MOT_DE_PASSE_PROFIL,
            'email_confirmation' => $user->email,
        ])
        ->assertHasNoActionErrors()
        ->assertRedirect('/');

    expect(User::find($user->id))->toBeNull();
});

// === Mot de passe faux ===

it('refuse la suppression si le mot de passe est faux', function () {
    $user = utilisateurProfil(['password' => Hash::make(MOT_DE_PASSE_PROFIL)]);
    $this->actingAs($user);

    Livewire::test(EditProfile::class)
        ->callAction('deleteAccount', [
            'password' => 'un-mot-de-passe-faux',
            'email_confirmation' => $user->email,
        ])
        ->assertHasActionErrors(['password']);

    expect(User::find($user->id))->not->toBeNull();
});

it('refuse la suppression si l\'adresse de confirmation ne correspond pas', function () {
    $user = utilisateurProfil(['password' => Hash::make(MOT_DE_PASSE_PROFIL)]);
    $this->actingAs($user);

    Livewire::test(EditProfile::class)
        ->callAction('deleteAccount', [
            'password' => MOT_DE_PASSE_PROFIL,
            'email_confirmation' => 'mauvaise@adresse.fr',
        ])
        ->assertHasActionErrors(['email_confirmation']);

    expect(User::find($user->id))->not->toBeNull();
});

// === Dossiers d'indivision : le sort de chacun, vérifié de bout en bout via l'action ===

it('supprime le dossier dont le compte est l\'unique membre actif, via l\'action du profil', function () {
    $user = utilisateurProfil(['password' => Hash::make(MOT_DE_PASSE_PROFIL)]);
    $dossier = dossierPourProfil($user);
    $this->actingAs($user);

    Livewire::test(EditProfile::class)
        ->callAction('deleteAccount', [
            'password' => MOT_DE_PASSE_PROFIL,
            'email_confirmation' => $user->email,
        ])
        ->assertHasNoActionErrors();

    expect(LinkedDossier::find($dossier->id))->toBeNull();
});

it('conserve un dossier partagé pour l\'autre membre, via l\'action du profil', function () {
    $user = utilisateurProfil(['password' => Hash::make(MOT_DE_PASSE_PROFIL)]);
    $autreMembre = utilisateurProfil();
    $dossier = dossierPourProfil($user);
    $dossier->members()->create([
        'user_id' => $autreMembre->id,
        'role' => DossierMemberRole::Member,
        'accepted_at' => now(),
    ]);
    $this->actingAs($user);

    Livewire::test(EditProfile::class)
        ->callAction('deleteAccount', [
            'password' => MOT_DE_PASSE_PROFIL,
            'email_confirmation' => $user->email,
        ])
        ->assertHasNoActionErrors();

    expect(LinkedDossier::find($dossier->id))->not->toBeNull()
        ->and($dossier->fresh()->members()->where('user_id', $autreMembre->id)->exists())->toBeTrue();
});

// === Dernier administrateur : la modale refuse, purement informative ===

it('n\'efface rien pour le dernier administrateur, même en appelant l\'action', function () {
    $admin = User::factory()->create(['is_admin' => true, 'password' => Hash::make(MOT_DE_PASSE_PROFIL)]);
    $this->actingAs($admin);

    Livewire::test(EditProfile::class)->callAction('deleteAccount');

    expect(User::find($admin->id))->not->toBeNull();
});
