<?php

namespace App\Mcp\Tools;

use App\Models\FiscalYear;
use App\Services\FiscalYearService;
use App\Services\TaxReturnService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Génère la liasse fiscale LMNP au format PDF (formulaires 2031, 2033-A à 2033-G) pour un exercice fiscal donné. L\'exercice est recalculé avant la génération si nécessaire. Retourne le chemin du PDF généré et un résumé des montants clés de la déclaration.')]
class GenerateTaxReturn extends Tool
{
    protected string $name = 'generate_tax_return';

    public function __construct(
        private TaxReturnService $taxReturnService,
        private FiscalYearService $fiscalYearService,
    ) {}

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'year' => 'required|integer|min:2000|max:2099',
        ]);

        $user = $request->user();
        $year = (int) $validated['year'];

        // Crée ou recalcule l'exercice fiscal pour avoir des totaux à jour
        $fiscalYear = $this->fiscalYearService->getOrCreate($user, $year);

        // Génération du PDF liasse fiscale
        $pdfPath = $this->taxReturnService->generatePdf($fiscalYear);

        // Persistance du chemin PDF
        $fiscalYear->update(['pdf_path' => $pdfPath]);

        $fileExists = file_exists($pdfPath);
        $fileSize   = $fileExists ? filesize($pdfPath) : null;
        $filename   = basename($pdfPath);

        return Response::json([
            'year'        => $year,
            'fiscal_year' => [
                'id'     => $fiscalYear->id,
                'status' => $fiscalYear->status,
            ],

            // Résumé des montants clés de la liasse
            'declaration_summary' => [
                'total_income_eur'          => bcdiv((string) $fiscalYear->total_income, '100', 2),
                'total_expenses_eur'        => bcdiv((string) $fiscalYear->total_expenses, '100', 2),
                'total_depreciation_eur'    => bcdiv((string) $fiscalYear->total_depreciation, '100', 2),
                'capped_depreciation_eur'   => bcdiv((string) $fiscalYear->capped_depreciation, '100', 2),
                'deferred_depreciation_eur' => bcdiv((string) $fiscalYear->deferred_depreciation, '100', 2),
                'fiscal_result_eur'         => bcdiv((string) $fiscalYear->fiscal_result, '100', 2),
            ],

            'pdf' => [
                'filename'        => $filename,
                'file_exists'     => $fileExists,
                'file_size_bytes' => $fileSize,
                'format'          => 'PDF (DomPDF)',
                'forms'           => ['2031', '2033-A', '2033-B', '2033-C', '2033-D', '2033-E', '2033-G'],
            ],

            'message' => $fileExists
                ? "Liasse fiscale {$year} générée avec succès : {$filename}"
                : "Génération initiée mais le fichier n'a pas pu être vérifié.",
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'year' => $schema->integer('Année fiscale pour laquelle générer la liasse (ex : 2025)')->required(),
        ];
    }
}
