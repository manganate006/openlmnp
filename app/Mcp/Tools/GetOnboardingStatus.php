<?php

namespace App\Mcp\Tools;

use App\Services\OnboardingChecklistService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Retourne l\'état d\'avancement de l\'onboarding LMNP de l\'utilisateur pour l\'année courante : création du bien, saisie des recettes et charges, configuration des amortissements, clôture de l\'exercice et génération de la liasse fiscale PDF.')]
#[IsReadOnly]
class GetOnboardingStatus extends Tool
{
    protected string $name = 'get_onboarding_status';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'year' => 'nullable|integer|min:2000|max:2099',
        ]);

        $user    = $request->user();
        $year    = (int) ($validated['year'] ?? now()->format('Y') - 1);
        $service = app(OnboardingChecklistService::class);

        $checklist = $service->getChecklist($user, $year);
        $progress  = $service->getProgress($user, $year);
        $complete  = $service->isComplete($user, $year);

        // Nettoyage : on retire le champ icon (pas utile en contexte MCP)
        $steps = array_map(function (array $step) {
            unset($step['icon']);
            return $step;
        }, $checklist);

        $completedCount = count(array_filter($steps, fn ($s) => $s['done'] ?? false));
        $totalCount     = count($steps);

        return Response::json([
            'year'            => $year,
            'progress_pct'    => $progress,
            'is_complete'     => $complete,
            'completed_steps' => $completedCount,
            'total_steps'     => $totalCount,
            'steps'           => $steps,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'year' => $schema->integer('Année fiscale (défaut : année précédente)'),
        ];
    }
}
