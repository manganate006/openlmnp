<?php

namespace App\Console\Commands;

use App\Models\Property;
use App\Models\User;
use App\Services\DemoDataService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Provisionne le token MCP démo public (lecture seule), de façon idempotente.
 *
 * - garantit le compte démo (config('demo.email'), is_demo=false → jamais purgé),
 *   avec un jeu de données fictif s'il est vide (DemoDataService::seedForUser),
 * - active l'accès MCP dessus (mcp_enabled=true, non suspendu),
 * - (re)crée un PersonalAccessToken DÉTERMINISTE depuis MCP_DEMO_TOKEN, pour que
 *   la valeur publiée reste stable au fil des redéploys/reseeds.
 *
 * Lancé par l'entrypoint après migrations/seed. No-op si MCP_DEMO_ENABLED=false.
 */
class McpDemoTokenCommand extends Command
{
    protected $signature = 'openlmnp:mcp-demo-token {--seed : Force la (re)génération du jeu de données démo}';

    protected $description = 'Provisionne le compte + le token MCP démo public en lecture seule';

    public const TOKEN_NAME = 'demo-public-readonly';

    public function handle(): int
    {
        if (! config('mcp.demo.enabled')) {
            $this->info('Démo MCP désactivée (MCP_DEMO_ENABLED=false) — rien à faire.');

            return self::SUCCESS;
        }

        $plain = (string) config('mcp.demo.token');

        if ($plain === '') {
            $this->error('MCP_DEMO_TOKEN manquant : définissez une valeur stable (sans « | »).');

            return self::FAILURE;
        }

        if (str_contains($plain, '|')) {
            $this->error("MCP_DEMO_TOKEN ne doit pas contenir le caractère « | » (format Sanctum).");

            return self::FAILURE;
        }

        $email = config('mcp.demo.email');

        // 1) Compte démo (mêmes identité/mot de passe que DemoSeeder).
        $user = User::updateOrCreate(
            ['email' => $email],
            ['name' => 'Marie Dupont', 'password' => Hash::make('demo2026')],
        );

        // 2) Accès MCP autorisé, jamais purgé, non suspendu.
        $user->forceFill([
            'mcp_enabled' => true,
            'is_demo' => false,
            'suspended_at' => null,
        ])->save();

        // 3) Jeu de données démo si vide (ou --seed pour forcer).
        $hasData = Property::withoutGlobalScopes()->where('user_id', $user->id)->exists();

        if ($this->option('seed') || ! $hasData) {
            app(DemoDataService::class)->seedForUser($user);
            $this->info('Jeu de données démo (re)généré.');
        }

        // 4) Token déterministe : on supprime l'ancien puis on recrée avec le hash courant.
        $user->tokens()->where('name', self::TOKEN_NAME)->delete();
        $user->tokens()->create([
            'name' => self::TOKEN_NAME,
            'token' => hash('sha256', $plain),
            'abilities' => ['*'],
        ]);

        $allowed = count(config('mcp.demo.tools', []));

        $this->info("Token MCP démo prêt pour {$email} (lecture seule, {$allowed} outils exécutables).");
        $this->line('  Endpoint : ' . config('app.url') . '/mcp');
        $this->line('  Header   : Authorization: Bearer <MCP_DEMO_TOKEN>');

        return self::SUCCESS;
    }
}
