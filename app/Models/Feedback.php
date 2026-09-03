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

    protected $fillable = [
        'sentiment',
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
