<?php

namespace App\Models;

use App\Enums\AccountKind;
use App\Enums\NavMode;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

// ⚠️ UNION des deux côtés à chaque fusion : une colonne oubliée ici est ignorée
// EN SILENCE par update(), sans erreur ni test rouge. Treize champs communs,
// neuf du cycle de vie démo, cinq de l'IA — vingt-sept en tout.
// ⚠️ `is_linked_dossier` est VOLONTAIREMENT ABSENT de cette liste : il ne se pose
// QUE par `forceFill()` dans App\Services\LinkedDossierService::create() (patron de
// `is_admin` évité à dessein ici — un dossier lié ne doit jamais pouvoir se créer par
// un `update()` de formulaire). `linked_dossier_quota`, lui, est fillable : posé par
// la commande `openlmnp:dossier-quota` via un `update()` ordinaire.
// ⚠️ `account_kind` (App\Enums\AccountKind) suit le MÊME principe que
// `is_linked_dossier` : VOLONTAIREMENT ABSENT d'ici, posé uniquement par
// `forceFill()` dans App\Services\LinkedDossierInvitationService::acceptAsNewMember()
// (création) et App\Http\Controllers\Api\ProvisioningController::promoteMemberAccount()
// (promotion au paiement) — jamais par un `update()` de formulaire, sans quoi
// n'importe qui pourrait s'auto-déclarer « membre » (quota 0 mais aussi hors
// statistiques d'abonnés) ou, pire, un compte membre s'auto-promouvoir `standard`.
#[Fillable(['name', 'email', 'password', 'siren', 'is_indivision', 'co_owners', 'is_admin', 'linked_dossier_quota', 'nav_mode', 'timezone', 'onboarding_dismissed_at', 'whats_new_dismissed_version', 'mcp_enabled', 'is_demo', 'demo_expires_at', 'feedback_prompted_at', 'feedback_answered_at', 'demo_reminders_seen', 'demo_seed', 'demo_email', 'demo_email_consent_at', 'demo_newsletter_at', 'demo_extended_at', 'demo_claim_token', 'demo_promoted_at', 'demo_seed_choice', 'ai_api_key', 'ai_consented_at', 'ai_data_access', 'ai_model_documents', 'ai_model_chat'])]
#[Hidden(['password', 'remember_token', 'ai_api_key'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public function canAccessPanel(Panel $panel): bool
    {
        if ($this->suspended_at !== null) {
            return false;
        }

        // Un dossier lié n'entre QUE par un contexte de bascule validé (voir
        // App\Support\DossierContext::validate()) : ni un mot de passe forcé, ni
        // une réinitialisation ne doivent permettre de s'y connecter directement
        // (son adresse est une boîte `@dossiers.invalid` que personne ne relève,
        // mais mieux vaut ne pas en dépendre seul).
        if ($this->is_linked_dossier) {
            return \App\Support\DossierContext::validate($this) === null;
        }

        return true;
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    /**
     * Compte « membre » (App\Enums\AccountKind::Member) : accès gratuit d'indivisaire,
     * créé uniquement depuis une invitation de dossier valide. N'a jamais de dossier à
     * lui, ne saisit rien dans son espace principal — voir le commentaire de l'enum.
     */
    public function isMemberAccount(): bool
    {
        return $this->account_kind === AccountKind::Member;
    }

    /**
     * Compte de démonstration, éphémère ou fixe.
     *
     * ⚠️ Deux mécanismes coexistent : les bacs à sable créés par /demo portent
     * `is_demo = true`, tandis que le compte de démonstration public, dont les
     * identifiants sont publiés, porte `is_demo = false` et se reconnaît à son
     * email. Les deux doivent être traités comme des comptes de démonstration —
     * c'est déjà le croisement fait par LifecycleSignalsController.
     */
    public function isDemo(): bool
    {
        return (bool) $this->is_demo
            || ($this->email !== null && $this->email === config('demo.email'));
    }

    public function documentVault(): HasOne
    {
        return $this->hasOne(DocumentVault::class);
    }

    public function isSimpleMode(): bool
    {
        return $this->nav_mode === NavMode::Simple;
    }

    public function isAdvancedMode(): bool
    {
        return $this->nav_mode === NavMode::Advanced;
    }

    public function isGuidedMode(): bool
    {
        return $this->nav_mode === NavMode::Guided;
    }

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }

    public function fiscalYears(): HasMany
    {
        return $this->hasMany(FiscalYear::class);
    }

    public function userBadges(): HasMany
    {
        return $this->hasMany(UserBadge::class);
    }

    public function badgeProgress(): HasMany
    {
        return $this->hasMany(BadgeProgress::class);
    }

    public function mcpAuditLogs(): HasMany
    {
        return $this->hasMany(McpAuditLog::class);
    }

    public function aiUsageLogs(): HasMany
    {
        return $this->hasMany(AiUsageLog::class);
    }

    public function hasBadge(string $code, ?int $fiscalYear = null): bool
    {
        return $this->userBadges()
            ->whereHas('definition', fn ($q) => $q->where('code', $code))
            ->when($fiscalYear !== null, fn ($q) => $q->where('fiscal_year', $fiscalYear))
            ->when($fiscalYear === null, fn ($q) => $q->whereNull('fiscal_year'))
            ->exists();
    }

    public function awardBadge(BadgeDefinition $badge, ?int $fiscalYear = null, array $context = []): ?UserBadge
    {
        $fy = $badge->is_yearly ? $fiscalYear : null;

        if ($this->hasBadge($badge->code, $fy)) {
            return null;
        }

        return $this->userBadges()->create([
            'badge_definition_id' => $badge->id,
            'unlocked_at' => now(),
            'fiscal_year' => $fy,
            'context' => $context,
        ]);
    }

    public function badgeCount(): int
    {
        return $this->userBadges()->count();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'is_demo' => 'boolean',
            'is_linked_dossier' => 'boolean',
            'account_kind' => AccountKind::class,
            'is_indivision' => 'boolean',
            'co_owners' => 'array',
            'demo_expires_at' => 'datetime',
            'mcp_enabled' => 'boolean',
            'nav_mode' => NavMode::class,
            'onboarding_dismissed_at' => 'datetime',
            'suspended_at' => 'datetime',
            'feedback_prompted_at' => 'datetime',
            'feedback_answered_at' => 'datetime',
            'demo_reminders_seen' => 'array',
            'demo_seed' => 'array',
            'demo_email_consent_at' => 'datetime',
            'demo_newsletter_at' => 'datetime',
            'demo_extended_at' => 'datetime',
            'demo_promoted_at' => 'datetime',
            // Chiffrée au repos par APP_KEY ; jamais sérialisée (voir #[Hidden]).
            'ai_api_key' => 'encrypted',
            'ai_consented_at' => 'datetime',
            'ai_data_access' => 'boolean',
        ];
    }

    /**
     * Adresse réelle de destination des e-mails.
     *
     * Un compte de démonstration porte une adresse technique en `@demo.local`, qui n'existe
     * pas : lui écrire ne part nulle part. Dès que le visiteur a laissé la sienne pour
     * prolonger son bac à sable, c'est elle qui reçoit.
     *
     * ⚠️ Le routage est ICI et pas dans la notification : `MailMessage` n'expose pas de
     * `->to()` (c'est une méthode de `Mailable`), et l'y appeler lève une `Error` fatale au
     * moment de l'envoi. Laravel ne consulte ce point d'entrée que pour le canal `mail`.
     *
     * ⚠️ La condition porte sur `is_demo`, PAS sur la seule présence de `demo_email`.
     * `ProvisioningController::promoteSandbox()` transforme un bac à sable en compte payant :
     * il écrit `email` = l'adresse donnée à Stripe et `is_demo = false`, mais ne vide jamais
     * `demo_email`. Router sur l'adresse résiduelle enverrait le `WelcomeSetPassword` du
     * client qui vient de payer — son lien de création de mot de passe — à celle qu'il avait
     * laissée en essayant la démo, quand les deux diffèrent.
     */
    /**
     * ⚠️ Nullable : un dossier lié n'a AUCUN destinataire de courriel. Son adresse
     * (`dossier-{ulid}@dossiers.invalid`) n'existe pas — tout e-mail de cycle de vie,
     * de réinitialisation ou de notification doit se taire plutôt que d'échouer en
     * silence contre un domaine inexistant. Retourner `null` est le seul point que
     * Laravel consulte pour le canal `mail` (voir le commentaire au-dessus pour le
     * même principe appliqué au compte démo).
     */
    public function routeNotificationForMail(): ?string
    {
        if ($this->is_linked_dossier) {
            return null;
        }

        return $this->is_demo && filled($this->demo_email) ? $this->demo_email : $this->email;
    }

    /** Présent seulement si `$this` EST le `User` d'un dossier lié. */
    public function linkedDossier(): HasOne
    {
        return $this->hasOne(\App\Models\LinkedDossier::class);
    }
}
