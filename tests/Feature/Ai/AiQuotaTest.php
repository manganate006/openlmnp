<?php

use App\Models\AiUsageLog;
use App\Models\User;
use App\Support\AiQuota;

beforeEach(function () {
    config([
        'ai.enabled' => true,
        'ai.api_key' => 'sk-or-v1-instance-0000',
        'ai.allow_user_keys' => true,
        'ai.docs_monthly_quota' => 0,
        'ai.chat_daily_quota' => 0,
        'ai.quota_applies_to_user_keys' => false,
        'demo.email' => 'demo@openlmnp.fr',
    ]);

    $this->user = User::factory()->create(['timezone' => 'Europe/Paris']);
});

/** Journalise $count appels pour l'utilisateur, à la date indiquée. */
function logAiCalls(User $user, string $feature, int $count, string $status = 'ok', ?Carbon\CarbonInterface $at = null): void
{
    foreach (range(1, $count) as $ignored) {
        AiUsageLog::create([
            'user_id' => $user->id,
            'feature' => $feature,
            'model' => 'google/gemini-3.7-flash',
            'key_source' => 'instance',
            'status' => $status,
            'created_at' => $at ?? now(),
        ]);
    }
}

// === Plafond mensuel (documents) ===

it('autorise sous le plafond mensuel et refuse une fois atteint', function () {
    config(['ai.docs_monthly_quota' => 3]);

    logAiCalls($this->user, 'documents', 2);
    expect(AiQuota::check($this->user, 'documents'))->toBeTrue()
        ->and(AiQuota::for($this->user, 'documents')->remaining())->toBe(1);

    logAiCalls($this->user, 'documents', 1);
    expect(AiQuota::check($this->user, 'documents'))->toBeFalse()
        ->and(AiQuota::for($this->user, 'documents')->remaining())->toBe(0)
        ->and(AiQuota::for($this->user, 'documents')->exhaustedMessage())->toContain('3/3');
});

it('traite 0 comme illimité', function () {
    config(['ai.docs_monthly_quota' => 0]);

    logAiCalls($this->user, 'documents', 50);

    expect(AiQuota::check($this->user, 'documents'))->toBeTrue()
        ->and(AiQuota::for($this->user, 'documents')->remaining())->toBeNull();
});

// === Plafond journalier (chat) ===

it('applique un plafond journalier distinct à l\'assistant', function () {
    config(['ai.chat_daily_quota' => 2, 'ai.docs_monthly_quota' => 10]);

    logAiCalls($this->user, 'chat', 2);

    expect(AiQuota::check($this->user, 'chat'))->toBeFalse()
        // Les documents ont leur propre compteur.
        ->and(AiQuota::check($this->user, 'documents'))->toBeTrue();
});

it('ne fait consommer aucun quota aux appels d\'outils ni aux vérifications de clé', function () {
    config(['ai.chat_daily_quota' => 1]);

    logAiCalls($this->user, 'chat_tool', 20);
    logAiCalls($this->user, 'key_check', 20);

    expect(AiQuota::check($this->user, 'chat'))->toBeTrue()
        ->and(AiQuota::for($this->user, 'chat_tool')->limit())->toBe(0);
});

// === Ce qui compte ===

it('ne compte que les appels réussis', function () {
    config(['ai.docs_monthly_quota' => 2]);

    logAiCalls($this->user, 'documents', 5, status: 'error');
    logAiCalls($this->user, 'documents', 3, status: 'quota');

    expect(AiQuota::for($this->user, 'documents')->used())->toBe(0)
        ->and(AiQuota::check($this->user, 'documents'))->toBeTrue();
});

it('ne compte pas les appels des autres utilisateurs', function () {
    config(['ai.docs_monthly_quota' => 2]);

    logAiCalls(User::factory()->create(), 'documents', 10);

    expect(AiQuota::for($this->user, 'documents')->used())->toBe(0);
});

// === Fenêtres et fuseau horaire ===

it('repart de zéro au changement de mois', function () {
    config(['ai.docs_monthly_quota' => 2]);

    logAiCalls($this->user, 'documents', 5, at: now()->subMonth());

    expect(AiQuota::for($this->user, 'documents')->used())->toBe(0)
        ->and(AiQuota::check($this->user, 'documents'))->toBeTrue();
});

it('calcule la fenêtre dans le fuseau de l\'utilisateur, pas celui du serveur', function () {
    config(['ai.docs_monthly_quota' => 5]);

    // 1er du mois à 00h30 à Paris = encore le dernier jour du mois précédent en UTC.
    $this->travelTo(Carbon\CarbonImmutable::parse('2026-03-01 00:30', 'Europe/Paris'));

    $paris = User::factory()->create(['timezone' => 'Europe/Paris']);
    $tokyo = User::factory()->create(['timezone' => 'Asia/Tokyo']);

    expect(AiQuota::for($paris, 'documents')->windowStart()->format('Y-m-d'))->toBe('2026-03-01')
        ->and(AiQuota::for($tokyo, 'documents')->windowStart()->format('Y-m-d'))->toBe('2026-03-01');

    // Un appel de la veille (28 février à Paris) est hors fenêtre.
    logAiCalls($paris, 'documents', 1, at: Carbon\CarbonImmutable::parse('2026-02-28 12:00', 'Europe/Paris'));

    expect(AiQuota::for($paris, 'documents')->used())->toBe(0);
});

it('compare la borne de fenêtre en UTC, comme created_at', function () {
    // Kiritimati (UTC+14) : le 1ᵉʳ mars y commence le 28 février à 10:00 UTC.
    // Un appel fait le 1ᵉʳ mars à 02:00 local (= 28 février 12:00 UTC) est DANS
    // la fenêtre de mars — une comparaison naïve de la borne locale l'exclurait.
    config(['ai.docs_monthly_quota' => 5]);
    $this->travelTo(Carbon\CarbonImmutable::parse('2026-03-01 14:30', 'Pacific/Kiritimati'));

    $kiritimati = User::factory()->create(['timezone' => 'Pacific/Kiritimati']);
    logAiCalls($kiritimati, 'documents', 1, at: Carbon\CarbonImmutable::parse('2026-02-28 12:00', 'UTC'));

    expect(AiQuota::for($kiritimati, 'documents')->used())->toBe(1);

    // New York (UTC−5) : le 28 février 23:00 local (= 1ᵉʳ mars 04:00 UTC) est
    // encore en février — une comparaison naïve le compterait dans mars.
    $this->travelTo(Carbon\CarbonImmutable::parse('2026-03-01 09:00', 'America/New_York'));

    $newYork = User::factory()->create(['timezone' => 'America/New_York']);
    logAiCalls($newYork, 'documents', 1, at: Carbon\CarbonImmutable::parse('2026-03-01 04:00', 'UTC'));

    expect(AiQuota::for($newYork, 'documents')->used())->toBe(0);
});

it('réinitialise le quota journalier du chat à minuit local, pas à minuit UTC', function () {
    config(['ai.chat_daily_quota' => 5]);
    // Paris, 21 juillet 00:30 local = 20 juillet 22:30 UTC.
    $this->travelTo(Carbon\CarbonImmutable::parse('2026-07-21 00:30', 'Europe/Paris'));

    $paris = User::factory()->create(['timezone' => 'Europe/Paris']);
    // Appel à 00:10 local (= 22:10 UTC la veille) : déjà dans la journée locale du 21.
    logAiCalls($paris, 'chat', 1, at: Carbon\CarbonImmutable::parse('2026-07-20 22:10', 'UTC'));
    // Appel à 23:50 local le 20 (= 21:50 UTC) : journée précédente.
    logAiCalls($paris, 'chat', 1, at: Carbon\CarbonImmutable::parse('2026-07-20 21:50', 'UTC'));

    expect(AiQuota::for($paris, 'chat')->used())->toBe(1);
});

it('borne la fenêtre du chat au jour civil de l\'utilisateur', function () {
    config(['ai.chat_daily_quota' => 5]);

    logAiCalls($this->user, 'chat', 3, at: now()->subDays(2));
    logAiCalls($this->user, 'chat', 1);

    expect(AiQuota::for($this->user, 'chat')->used())->toBe(1);
});

// === Exemption « clé de l'utilisateur » ===

it('exempte de quota l\'utilisateur qui fournit sa propre clé', function () {
    config(['ai.api_key' => null, 'ai.docs_monthly_quota' => 1]);
    $this->user->update(['ai_api_key' => 'sk-or-v1-utilisateur-9999']);

    logAiCalls($this->user->fresh(), 'documents', 10);

    expect(AiQuota::check($this->user->fresh(), 'documents'))->toBeTrue()
        ->and(AiQuota::for($this->user->fresh(), 'documents')->limit())->toBe(0);
});

it('applique quand même le quota aux clés utilisateur si l\'administrateur le force', function () {
    config([
        'ai.api_key' => null,
        'ai.docs_monthly_quota' => 1,
        'ai.quota_applies_to_user_keys' => true,
    ]);
    $this->user->update(['ai_api_key' => 'sk-or-v1-utilisateur-9999']);

    logAiCalls($this->user->fresh(), 'documents', 1);

    expect(AiQuota::check($this->user->fresh(), 'documents'))->toBeFalse();
});

it('n\'exempte pas les administrateurs', function () {
    config(['ai.docs_monthly_quota' => 1]);
    $admin = User::factory()->create(['is_admin' => true, 'timezone' => 'Europe/Paris']);

    logAiCalls($admin, 'documents', 1);

    expect(AiQuota::check($admin, 'documents'))->toBeFalse();
});
