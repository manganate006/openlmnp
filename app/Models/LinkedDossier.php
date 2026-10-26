<?php

namespace App\Models;

use App\Enums\DossierMemberRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Dossier d'indivision offert : un `User` à part entière (voir `user()`), ouvert par
 * bascule d'authentification depuis un compte principal abonné (`App\Support\DossierContext`).
 *
 * ⚠️ Ni `BelongsToUserScope` ni aucun scope d'isolation : cette table n'est jamais
 * consultée « en tant que » son propre dossier, seulement par des comptes PRINCIPAUX
 * qui gèrent les dossiers dont ils sont membres.
 */
class LinkedDossier extends Model
{
    use HasFactory;

    protected $fillable = [
        'public_id', 'user_id', 'owner_id', 'kind', 'siren',
        'sirene_snapshot', 'sirene_checked_at', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'sirene_snapshot' => 'array',
            'sirene_checked_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** Le `User` du dossier lui-même — celui que `DossierContext::switchTo()` authentifie. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(LinkedDossierMember::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(LinkedDossierInvitation::class);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /** Membres encore valides (acceptés, non révoqués) — c'est l'autorisation elle-même. */
    public function scopeWhereMemberIs(Builder $query, int $userId): Builder
    {
        return $query->whereHas('members', fn (Builder $q) => $q->where('user_id', $userId)
            ->whereNotNull('accepted_at')
            ->whereNull('revoked_at'));
    }

    /**
     * Membre qui ADMINISTRE aujourd'hui ce dossier (inviter, révoquer, archiver) :
     * le titulaire du rôle Owner s'il est encore utilisable (compte non suspendu,
     * non démo), sinon le membre valide accepté depuis le plus longtemps parmi les
     * autres. Lot 3, plan § 10 décision n°5 : « si le créateur est suspendu ou
     * part, l'offre passe automatiquement à un membre actif, qui devient owner ».
     *
     * ⚠️ JAMAIS persisté — même principe que `DossierContext::validate()`
     * (« pas de webhook de suspension », plan § 0) : recalculé à CHAQUE
     * consultation, sans aucune ligne à resynchroniser. `owner_id`, lui, n'est
     * JAMAIS réécrit par ce mécanisme : il reste la trace de qui a créé le
     * dossier et donc de quel quota l'a débité à la création (décision n°5 du
     * plan, déjà actée dans le commentaire de la migration
     * `create_linked_dossiers_table`). Voir le rapport de livraison du lot 3
     * pour la décision produit soumise à ce sujet (consommation du quota du
     * nouvel administrateur).
     *
     * Rend `null` si aucun membre valide n'a de compte principal utilisable : le
     * dossier est alors FERMÉ (`isClosed()`) — personne ne peut de toute façon
     * l'ouvrir, `DossierContext::switchTo()` exigeant déjà que l'acteur
     * lui-même soit un membre utilisable.
     *
     * ⚠️ **Un compte « membre » (`AccountKind::Member`) n'est JAMAIS éligible ici**
     * (lot « dossiers membre », décision du 2026-10-15) : la succession d'un dossier
     * ne passe qu'à un compte `Standard` (abonné ou auto-hébergé) actif. Sans ça, un
     * dossier n'ayant plus que des comptes membres actifs resterait « administré »
     * par l'un d'eux alors que personne ne le fait plus vivre — il doit au contraire
     * se FERMER (`isClosed()`), comme le prévoyait déjà la décision n°5 du plan pour
     * un dossier sans AUCUN membre utilisable.
     */
    public function actingOwner(): ?User
    {
        return $this->members()
            ->whereNotNull('accepted_at')
            ->whereNull('revoked_at')
            ->with('user')
            // Le titulaire du rôle Owner d'abord ; entre membres ordinaires, le
            // plus ancien accepté — ordre déterministe, jamais arbitraire.
            ->orderByRaw('role = ? desc', [DossierMemberRole::Owner->value])
            ->orderBy('accepted_at')
            ->get()
            ->first(fn (LinkedDossierMember $member) => $member->user !== null
                && $member->user->suspended_at === null
                && ! $member->user->isDemo()
                && ! $member->user->isMemberAccount())
            ?->user;
    }

    /** Aucun membre utilisable : conservé, jamais supprimé, mais personne ne peut l'ouvrir. */
    public function isClosed(): bool
    {
        return $this->actingOwner() === null;
    }

    /**
     * Garde ANTI-DÉTOURNEMENT du lot « dossiers membre » (décision du 2026-10-15) :
     * ce dossier compte-t-il au moins un membre ACTIF dont le compte n'est PAS
     * lui-même un compte membre (un abonné ou un compte standard actif) ?
     *
     * Sans elle, un compte standard pourrait créer un dossier, y inviter un ami en
     * compte membre, puis résilier — le dossier resterait indéfiniment accessible
     * gratuitement au seul compte membre. Consultée par
     * `DossierContext::switchTo()`/`validate()` : un compte membre ne peut ouvrir,
     * ni rester dans, ce dossier que tant que cette méthode rend `true`.
     *
     * ⚠️ RECALCULÉE à chaque appel, jamais persistée — même principe que
     * `actingOwner()`/`isClosed()` (« pas de webhook de suspension », plan § 0) :
     * aucun état à resynchroniser quand le dernier compte standard se suspend ou se
     * révoque lui-même.
     */
    public function hasActiveNonMemberBacker(): bool
    {
        return $this->members()
            ->whereNotNull('accepted_at')
            ->whereNull('revoked_at')
            ->with('user')
            ->get()
            ->contains(fn (LinkedDossierMember $member) => $member->user !== null
                && ! $member->user->isMemberAccount()
                && $member->user->suspended_at === null
                && ! $member->user->isDemo());
    }
}
