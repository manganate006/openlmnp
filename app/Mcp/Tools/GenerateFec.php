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

#[Description('Génère le Fichier des Écritures Comptables (FEC) pour un exercice fiscal. Le FEC est un fichier réglementaire au format normé (article A.47 A-1 du LPF, 18 colonnes, séparateur TAB) requis en cas de contrôle fiscal. L\'exercice est calculé avant la génération si nécessaire. Retourne le chemin du fichier généré.')]
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

        // Génération du FEC
        $filePath = $this->fecService->generate($fiscalYear);

        // Mise à jour du chemin sur l'exercice
        $fiscalYear->update(['fec_path' => $filePath]);

        $siren      = $user->siren ?? '000000000';
        $filename   = "{$siren}FEC{$year}1231.txt";
        $fileExists = file_exists($filePath);
        $fileSize   = $fileExists ? filesize($filePath) : null;

        return Response::json([
            'year'        => $year,
            'fiscal_year' => [
                'id'             => $fiscalYear->id,
                'status'         => $fiscalYear->status,
                'fiscal_result_eur' => bcdiv((string) $fiscalYear->fiscal_result, '100', 2),
            ],
            'fec' => [
                'filename'    => $filename,
                'file_exists' => $fileExists,
                'file_size_bytes' => $fileSize,
                'format'      => 'TXT, 18 colonnes, séparateur TAB, UTF-8',
                'legal_ref'   => 'Article A.47 A-1 du LPF',
            ],
            'message' => $fileExists
                ? "FEC {$year} généré avec succès : {$filename}"
                : "Génération initiée mais le fichier n'a pas pu être vérifié.",
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'year' => $schema->integer('Année fiscale pour laquelle générer le FEC (ex : 2025)')->required(),
        ];
    }
}
