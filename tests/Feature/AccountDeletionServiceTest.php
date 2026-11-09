<?php

use App\Enums\AccountKind;
use App\Enums\DossierMemberRole;
use App\Models\AiUsageLog;
use App\Models\BadgeDefinition;
use App\Models\BadgeProgress;
use App\Models\DocumentVault;
use App\Models\DossierEvent;
use App\Models\Feedback;
use App\Models\FiscalYear;
use App\Models\Invitation;
use App\Models\LinkedDossier;
use App\Models\McpAuditLog;
use App\Models\Property;
use App\Models\User;
use App\Models\UserBadge;
use App\Services\AccountDeletionRefusedException;
use App\Services\AccountDeletionService;
use App\Support\DocumentStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * `creerBienASupprimer()` et `creerDossierASupprimer()` : fabriques minimales propres à
 * ce fichier (patron des helpers déjà posés dans DemoModeTest/IsolationChildModelsTest).
 */
function creerBienASupprimer(User $user, string $name = 'Bien a supprimer'): Property
{
    return Property::withoutGlobalScopes()->forceCreate([
        'user_id' => $user->id,
        'name' => $name,
        'address' => '1 rue de Test',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        'total_area' => 50,
        'rented_area' => 50,
        'acquisition_date' => '2020-01-01',
        'acquisition_price' => 20000000,
        'notary_fees' => 1600000,
        'land_percentage' => 15,
        'rental_start_date' => '2020-06-01',
        'rental_type' => 'long_term',
        'is_primary_residence' => false,
    ]);
}

function creerDossierASupprimer(User $owner, string $siren = '100000009'): LinkedDossier
{
    $dossierUser = new User;
    $dossierUser->forceFill([
        'name' => 'Indivision suppression',
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

/**
 * ⚠️ `AppServiceProvider::boot()` force `is_admin = true` sur le TOUT PREMIER compte
 * créé dans une base vide (« premier compte = administrateur »). Dans une suite Pest
 * (`RefreshDatabase`, base vide à chaque test), le premier `User::factory()->create()`
 * d'un test devient donc administrateur MALGRÉ LUI — et, seul administrateur de la
 * base, il déclenche `REFUSAL_LAST_ADMIN` dans des tests qui n'ont rien à voir avec ce
 * refus. Neutralisé explicitement pour tout compte qui ne teste pas ce mécanisme.
 */
function utilisateurStandard(array $attributs = []): User
{
    $user = User::factory()->create($attributs);

    if (! array_key_exists('is_admin', $attributs) && $user->is_admin) {
        $user->forceFill(['is_admin' => false])->save();
    }

    return $user;
}

beforeEach(function () {
    config(['services.provisioning.token' => null]);
});

// === Suppression complète, tables et fichiers compris ===

it('supprime le compte et toutes ses données, y compris les fichiers sur disque', function () {
    $user = utilisateurStandard();
    $property = creerBienASupprimer($user);

    FiscalYear::withoutGlobalScopes()->forceCreate([
        'user_id' => $user->id,
        'year' => 2025,
        'status' => 'draft',
    ]);

    $token = $user->createToken('jeton-test');

    // Racine courante ET ancienne racine (Laravel 10) : les deux doivent partir.
    Storage::disk('local')->put('documents/'.$user->id.'/facture.pdf', 'contenu');
    DocumentStorage::legacyDisk()->put('documents/'.$user->id.'/ancien.pdf', 'contenu ancien');

    expect(Storage::disk('local')->exists('documents/'.$user->id.'/facture.pdf'))->toBeTrue()
        ->and(DocumentStorage::legacyDisk()->exists('documents/'.$user->id.'/ancien.pdf'))->toBeTrue();

    app(AccountDeletionService::class)->delete($user);

    expect(User::find($user->id))->toBeNull()
        ->and(Property::withoutGlobalScopes()->find($property->id))->toBeNull()
        ->and(FiscalYear::withoutGlobalScopes()->where('user_id', $user->id)->count())->toBe(0)
        ->and(\Laravel\Sanctum\PersonalAccessToken::find($token->accessToken->id))->toBeNull()
        ->and(Storage::disk('local')->exists('documents/'.$user->id.'/facture.pdf'))->toBeFalse()
        ->and(DocumentStorage::legacyDisk()->exists('documents/'.$user->id.'/ancien.pdf'))->toBeFalse();
});

// === Contre-épreuve : sans la purge de fichiers, ils resteraient ===

it('contre-épreuve : un simple $user->delete() sans le service laisse les fichiers sur le disque', function () {
    $user = utilisateurStandard();
    Storage::disk('local')->put('documents/'.$user->id.'/facture.pdf', 'contenu');

    $user->delete();

    expect(Storage::disk('local')->exists('documents/'.$user->id.'/facture.pdf'))->toBeTrue();

    // Nettoyage pour ne pas polluer les autres tests du disque de test.
    Storage::disk('local')->deleteDirectory('documents/'.$user->id);
});

// === Aucun orphelin dans les tables qui référencent users ===

it('ne laisse aucune ligne orpheline dans les tables qui référencent users', function () {
    $user = utilisateurStandard();
    $property = creerBienASupprimer($user);

    $fiscalYear = FiscalYear::withoutGlobalScopes()->forceCreate([
        'user_id' => $user->id,
        'year' => 2025,
        'status' => 'draft',
    ]);

    $user->createToken('jeton-orphelin');

    $definition = BadgeDefinition::first() ?? BadgeDefinition::forceCreate([
        'code' => 'badge-suppression-test',
        'category' => 'test',
        'name' => 'Badge de test',
        'description' => 'Badge utilisé par les tests',
        'icon' => 'heroicon-o-star',
        'color' => 'emerald',
        'is_yearly' => false,
        'sort_order' => 1,
        'is_active' => true,
        'unlock_conditions' => '[]',
    ]);

    BadgeProgress::forceCreate([
        'user_id' => $user->id,
        'badge_definition_id' => $definition->id,
        'current_value' => 1,
        'target_value' => 10,
    ]);

    UserBadge::forceCreate([
        'user_id' => $user->id,
        'badge_definition_id' => $definition->id,
        'unlocked_at' => now(),
        'is_notified' => false,
    ]);

    AiUsageLog::forceCreate([
        'user_id' => $user->id,
        'feature' => 'documents',
        'status' => 'ok',
        'created_at' => now(),
    ]);

    McpAuditLog::forceCreate([
        'user_id' => $user->id,
        'tool_name' => 'list_properties',
        'result_status' => 'ok',
        'created_at' => now(),
    ]);

    DocumentVault::forceCreate([
        'user_id' => $user->id,
        'kdf_salt' => 'sel',
        'kdf_ops' => 1,
        'kdf_mem' => 8192,
        'kdf_alg' => 2,
        'wrapped_dek' => 'x',
        'wrap_nonce' => 'y',
        'dek_fingerprint' => 'z',
        'status' => DocumentVault::STATUS_ACTIVE,
    ]);

    Feedback::factory()->for($user)->create();

    Invitation::create([
        'email' => 'invite-oublie@exemple.fr',
        'token_hash' => hash('sha256', Str::random(48)),
        'expires_at' => now()->addDays(14),
        'invited_by_user_id' => $user->id,
        'user_id' => $user->id,
    ]);

    // Dossier lié partagé : la ligne de membre de $user doit partir avec lui, le
    // dossier et l'autre membre doivent rester (couvert aussi par le test dédié
    // plus bas, mais ça ne coûte rien de le vérifier ici aussi).
    $autreMembre = utilisateurStandard();
    $dossier = creerDossierASupprimer($user);
    $dossier->members()->create([
        'user_id' => $autreMembre->id,
        'role' => DossierMemberRole::Member,
        'accepted_at' => now(),
    ]);

    $event = DossierEvent::record($dossier->id, DossierEvent::TYPE_CREATED, $user);

    $userId = $user->id;

    app(AccountDeletionService::class)->delete($user->fresh());

    // ⚠️ Politique d'audit, tranchée et testée explicitement (reprise du schéma, pas
    // recodée) : `dossier_events` est ANONYMISÉ (la ligne survit, l'auteur devient
    // anonyme — `user_id` en `nullOnDelete()`), `mcp_audit_logs` est PURGÉ (la ligne
    // part avec le compte — `user_id` en `cascadeOnDelete()`, même choix que ce que
    // fait déjà `openlmnp:demo-cleanup`, qui ne l'a jamais traité à part).
    expect(DossierEvent::find($event->id))->not->toBeNull()
        ->and(DossierEvent::find($event->id)->user_id)->toBeNull()
        ->and(McpAuditLog::where('user_id', $userId)->count())->toBe(0);

    // Boucle générique : toute table qui porte une colonne dont le nom contient
    // « user_id », « owner_id » ou « invited_by » (ce qui couvre aussi bien
    // `user_id`/`owner_id` que `invited_by_user_id`/`dossier_user_id`) ne doit
    // plus comporter aucune ligne pointant vers le compte supprimé.
    $motifs = ['user_id', 'owner_id', 'invited_by'];

    foreach (Schema::getTableListing(schemaQualified: false) as $table) {
        foreach (Schema::getColumnListing($table) as $column) {
            if (! Str::contains($column, $motifs)) {
                continue;
            }

            $count = DB::table($table)->where($column, $userId)->count();

            expect($count)->toBe(
                0,
                "La table {$table}, colonne {$column}, référence encore le compte supprimé.",
            );
        }
    }

    // Le dossier partagé, lui, doit survivre avec son autre membre intact.
    expect(LinkedDossier::find($dossier->id))->not->toBeNull()
        ->and($dossier->fresh()->members()->where('user_id', $autreMembre->id)->exists())->toBeTrue();
});

// === Dossiers d'indivision : règle noir sur blanc ===

it('supprime un dossier d\'indivision dont le compte est l\'unique membre actif', function () {
    $user = utilisateurStandard();
    $dossier = creerDossierASupprimer($user);
    $dossierUserId = $dossier->user_id;
    creerBienASupprimer($dossier->user, 'Bien du dossier');

    $report = app(AccountDeletionService::class)->delete($user);

    expect($report['dossiers_deleted'])->toBe(1)
        ->and($report['dossiers_kept'])->toBe(0)
        ->and(LinkedDossier::find($dossier->id))->toBeNull()
        ->and(User::find($dossierUserId))->toBeNull()
        ->and(Property::withoutGlobalScopes()->where('user_id', $dossierUserId)->count())->toBe(0);
});

it('conserve un dossier d\'indivision partagé pour l\'autre membre, et ne retire que la ligne du compte supprimé', function () {
    $owner = utilisateurStandard();
    $autreMembre = utilisateurStandard();
    $dossier = creerDossierASupprimer($owner);
    $dossier->members()->create([
        'user_id' => $autreMembre->id,
        'role' => DossierMemberRole::Member,
        'accepted_at' => now(),
    ]);

    $report = app(AccountDeletionService::class)->delete($owner);

    expect($report['dossiers_deleted'])->toBe(0)
        ->and($report['dossiers_kept'])->toBe(1)
        ->and(LinkedDossier::find($dossier->id))->not->toBeNull()
        ->and(User::find($dossier->user_id))->not->toBeNull()
        ->and($dossier->fresh()->members()->where('user_id', $owner->id)->exists())->toBeFalse()
        ->and($dossier->fresh()->members()->where('user_id', $autreMembre->id)->where('revoked_at', null)->exists())->toBeTrue();
});

// === Compte membre ===

it('autorise un compte membre à supprimer son propre compte, y compris sur le cloud', function () {
    config(['services.provisioning.token' => 'jeton-provisioning-test']);

    $membre = utilisateurStandard();
    $membre->forceFill(['account_kind' => AccountKind::Member])->save();

    expect(app(AccountDeletionService::class)->refusalReason($membre))->toBeNull()
        ->and(app(AccountDeletionService::class)->selfServiceRefusalReason($membre))->toBeNull();

    app(AccountDeletionService::class)->delete($membre);

    expect(User::find($membre->id))->toBeNull();
});

// === Refus ===

it('refuse la suppression du compte de démonstration public', function () {
    config(['demo.email' => 'demo@openlmnp.fr']);
    $demo = User::factory()->create(['email' => 'demo@openlmnp.fr', 'is_demo' => false]);

    expect(app(AccountDeletionService::class)->refusalReason($demo))
        ->toBe(AccountDeletionService::REFUSAL_DEMO);

    expect(fn () => app(AccountDeletionService::class)->delete($demo))
        ->toThrow(AccountDeletionRefusedException::class);

    expect(User::find($demo->id))->not->toBeNull();
});

it('refuse la suppression d\'un bac à sable de démonstration éphémère', function () {
    $sandbox = User::factory()->create(['is_demo' => true, 'demo_expires_at' => now()->addHour()]);

    expect(app(AccountDeletionService::class)->refusalReason($sandbox))
        ->toBe(AccountDeletionService::REFUSAL_DEMO);
});

it('refuse la suppression du dernier administrateur de l\'instance', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    expect(app(AccountDeletionService::class)->refusalReason($admin))
        ->toBe(AccountDeletionService::REFUSAL_LAST_ADMIN);

    expect(User::find($admin->id))->not->toBeNull();
});

it('autorise la suppression d\'un administrateur si un autre administrateur reste', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    User::factory()->create(['is_admin' => true]);

    expect(app(AccountDeletionService::class)->refusalReason($admin))->toBeNull();
});

it('refuse la suppression d\'un dossier lié : ce n\'est pas un compte personnel', function () {
    $owner = utilisateurStandard();
    $dossier = creerDossierASupprimer($owner);

    expect(app(AccountDeletionService::class)->refusalReason($dossier->user))
        ->toBe(AccountDeletionService::REFUSAL_LINKED_DOSSIER);
});

// === Refus propre à l'auto-suppression, sur le cloud ===

it('refuse l\'auto-suppression d\'un compte standard sur le cloud (abonnement potentiellement actif)', function () {
    config(['services.provisioning.token' => 'jeton-provisioning-test']);
    $user = utilisateurStandard();

    expect(app(AccountDeletionService::class)->refusalReason($user))->toBeNull()
        ->and(app(AccountDeletionService::class)->selfServiceRefusalReason($user))
        ->toBe(AccountDeletionService::REFUSAL_CLOUD_SUBSCRIPTION);
});

it('n\'oppose pas le refus cloud en auto-hébergement', function () {
    config(['services.provisioning.token' => null]);
    $user = utilisateurStandard();

    expect(app(AccountDeletionService::class)->selfServiceRefusalReason($user))->toBeNull();
});
