<?php

use App\Models\AiUsageLog;
use App\Models\User;
use App\Support\AiQuota;

/** AI_DAILY_BUDGET_USD : la facture de l'instance, tous comptes confondus, sur sa seule clé. */
beforeEach(function () {
    config(['ai.enabled' => true, 'ai.provider' => 'openrouter', 'ai.base_url' => 'https://openrouter.test/api/v1',
        'ai.api_key' => 'sk-or-v1-factice', 'ai.chat_daily_quota' => 0, 'ai.daily_budget_usd' => 1.0,
        'demo.email' => 'demo@openlmnp.fr']);
    $this->user = User::factory()->create();
});

function depense(float $cout, string $source = 'instance', ?Carbon\CarbonInterface $quand = null): void
{
    AiUsageLog::create(['user_id' => User::factory()->create()->id, 'feature' => 'chat', 'model' => 'm',
        'key_source' => $source, 'status' => 'ok', 'cost_usd' => $cout, 'created_at' => $quand ?? now()]);
}

it('laisse passer sous le budget, tous comptes confondus', function () {
    depense(0.4);
    depense(0.5);

    expect(AiQuota::check($this->user, AiQuota::FEATURE_CHAT))->toBeTrue();
});

it('met l\'IA en pause une fois le budget du jour atteint par d\'autres comptes', function () {
    depense(0.6);
    depense(0.4);

    $quota = AiQuota::for($this->user, AiQuota::FEATURE_DOCUMENTS);

    expect($quota->allowed())->toBeFalse()
        ->and($quota->exhaustedMessage())->toContain('réessayez demain');
});

it('ne compte ni la veille ni les clés personnelles', function () {
    depense(5.0, 'instance', now()->subDay()->startOfDay()->addHour());
    depense(5.0, 'user');

    expect(AiQuota::check($this->user, AiQuota::FEATURE_CHAT))->toBeTrue();
});

it('n\'arrête pas un compte qui paie avec sa propre clé', function () {
    config(['ai.api_key' => '', 'ai.allow_user_keys' => true]);
    $this->user->forceFill(['ai_api_key' => 'sk-or-v1-perso'])->save();
    depense(5.0);

    expect(AiQuota::check($this->user->fresh(), AiQuota::FEATURE_CHAT))->toBeTrue();
});

it('est désactivé à 0', function () {
    config(['ai.daily_budget_usd' => 0]);
    depense(50.0);

    expect(AiQuota::check($this->user, AiQuota::FEATURE_CHAT))->toBeTrue();
});
