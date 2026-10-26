<?php

namespace App\Mcp\Tools;

use App\Models\FiscalYear;
use App\Services\FecService;
use App\Services\FiscalYearService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Génère le Fichier des Écritures Comptables (FEC) pour un exercice fiscal. Le FEC est un fichier réglementaire au format normé (article A.47 A-1 du LPF, 18 colonnes, séparateur TAB) requis en cas de contrôle fiscal. L\'exercice est calculé avant la génération si nécessaire. Le fichier n\'est pas conservé sur le serveur : il se télécharge depuis la page Exercices fiscaux.')]
class GenerateFec extends Tool
{
    protected string $name = 'generate_fec';

    public function __construct(
        private FecService $fecService,
        private FiscalYearService $fiscalYearService,
    ) {}

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'year' => 'required|integer|min:2000|max:2099',
        ]);

        $user = $request->user();
        $year = (int) $validated['year'];

        // Crée ou recalcule l'exercice fiscal (nécessaire pour les écritures comptables)
        $fiscalYear = $this->fiscalYearService->getOrCreate($user, $year);

        // Génération du FEC. Rendu en mémoire : rien n'est écrit sur le disque (le fichier y
        // restait en clair, avec toutes les écritures et le SIREN, et personne ne le relisait).
        // `render()` enregistre au passage la date de génération sur l'exercice.
        $content = $this->fecService->render($fiscalYear);

        return Response::json([
            'year'        => $year,
            'fiscal_year' => [
                'id'             => $fiscalYear->id,
                'status'         => $fiscalYear->status,
                'fiscal_result_eur' => bcdiv((string) $fiscalYear->fiscal_result, '100', 2),
            ],
            'fec' => [
                'filename'     => $this->fecService->filename($fiscalYear),
                'size_bytes'   => strlen($content),
                // En-tête déduit : le FEC porte une ligne de titres puis une ligne par écriture.
                'entry_lines'  => max(0, substr_count($content, "\r\n") - 1),
                'format'       => 'TXT, 18 colonnes, séparateur TAB, UTF-8',
                'legal_ref'    => 'Article A.47 A-1 du LPF',
                'persisted'    => false,
                'generated_at' => $fiscalYear->fec_generated_at?->toIso8601String(),
            ],
            'message' => "FEC {$year} généré avec succès.",
            'note'    => 'Le FEC n\'est pas conservé sur le serveur : il est reconstruit à chaque '
                . 'téléchargement depuis la page « Exercices fiscaux ». Cet appel confirme qu\'il '
                . 'se génère et en donne la taille.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'year' => $schema->integer('Année fiscale pour laquelle générer le FEC (ex : 2025)')->required(),
        ];
    }
}
