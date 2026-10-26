<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un retour d'utilisateur sur le logiciel.
 *
 * ⚠️ Ce modèle NE PORTE PAS `BelongsToUserScope`, contrairement à toutes les autres
 * entités rattachées à un utilisateur, et c'est délibéré :
 *
 *  - rien dans l'application ne relit un retour côté utilisateur — l'écriture est le
 *    seul geste exposé ;
 *  - ses lignes survivent volontairement à leur auteur (`user_id` passe à `null` quand
 *    un compte de démonstration est purgé), et un scope les rendrait invisibles à tous.
 *
 * Si un écran de lecture apparaît un jour, il devra filtrer explicitement — comme le
 * fait `LifecycleSignalsController`, pour la même raison.
 */
class Feedback extends Model
{
    use HasFactory;

    public const SENTIMENT_POSITIVE = 'positive';

    public const SENTIMENT_NEGATIVE = 'negative';

    public const AUDIENCE_DEMO = 'demo';

    public const AUDIENCE_USER = 'user';

    public const TRIGGER_SESSION = 'session';

    public const TRIGGER_RETURN = 'return';

    /**
     * Les freins proposés au visiteur de la démonstration, dans l'ordre d'affichage.
     *
     * ⚠️ **La clé est stockée en base, jamais le libellé.** Reformuler une case ne doit ni
     * casser l'historique ni fausser les comptages du rapport hebdomadaire.
     *
     * Chaque entrée vient d'une source constatée, pas d'une intuition :
     *  - `reprise`          issue GitHub #8, verbatim : « quasi impossible de basculer une
     *                       comptabilité LMNP existante », suivie d'une 2ᵉ demande le lendemain
     *  - `prix`             notre propre comparatif l'admet : plus cher sur l'année qu'une
     *                       offre payée en une fois
     *  - `confiance`        FAQ « faut-il un expert-comptable » + persona « peur de faire une
     *                       erreur fiscale »
     *  - `avis`             constat interne : 1 abonné actif, 0 témoignage publié
     *  - `teletransmission` la page tarifs annonce « bientôt disponible »
     *  - `fonctionnalite`   regroupe accès comptable, import bancaire et confort mobile :
     *                       trois hypothèses trop spéculatives pour trois cases séparées
     *
     * Volontairement ABSENTS faute de source : la TVA (le produit la couvre), la
     * para-hôtellerie (aucune trace), la SCI/indivision et « pas les compétences pour
     * auto-héberger » — ces deux derniers sont déduits du modèle de données et de la structure
     * de l'offre, jamais formulés par un prospect. Les proposer reviendrait à souffler la
     * réponse.
     *
     * @var array<string, string>
     */
    public const BLOCKERS = [
        'reprise' => 'Reprendre une comptabilité déjà commencée ailleurs',
        'prix' => 'Le coût de l\'abonnement',
        'confiance' => 'Ne pas avoir de professionnel qui engage sa responsabilité',
        'avis' => 'Le manque d\'avis d\'autres utilisateurs',
        'teletransmission' => 'Ne pas pouvoir télétransmettre la liasse aux impôts',
        'fonctionnalite' => 'Une fonctionnalité dont j\'ai besoin manque',
    ];

    protected $fillable = [
        'sentiment',
        'blockers',
        'variant',
        'dismissed_at',
        'message',
        'author_name',
        'author_email',
        'can_publish',
        'audience',
        'trigger',
        'context',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'can_publish' => 'boolean',
            'context' => 'array',
            'blockers' => 'array',
            'dismissed_at' => 'datetime',
        ];
    }

    /**
     * L'invitation a-t-elle obtenu une réponse ? Une ligne sans sentiment est une
     * impression : elle compte au dénominateur, pas au numérateur.
     */
    public function isAnswered(): bool
    {
        return $this->sentiment !== null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPositive(): bool
    {
        return $this->sentiment === self::SENTIMENT_POSITIVE;
    }

    /**
     * Un retour venu de la démonstration porte sur un jeu de données fictif, pas sur
     * la comptabilité réelle de son auteur. Il reste précieux pour le produit, mais
     * il n'est pas un témoignage d'usage et ne doit jamais être publié comme tel.
     */
    public function isPublishableAsTestimonial(): bool
    {
        return $this->can_publish
            && $this->audience === self::AUDIENCE_USER
            && filled($this->message);
    }
}
