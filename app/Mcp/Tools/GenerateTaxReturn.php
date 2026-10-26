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

// ⚠️ RÉSOLUTION DE FUSION (2026-09-11) : panachage volontaire des deux côtés.
// La LISTE DES FORMULAIRES vient de `main` — « 2031, 2033-A à 2033-G » était faux, la vue
// ne rend que cinq sections, et `TaxReturnFormsTest` ancre désormais l'annonce au document
// dans les deux sens. La NON-PERSISTANCE vient de cette branche : « fichiers en clair » ne
// laisse plus le PDF sur le disque. Prendre un seul des deux côtés aurait réintroduit soit
// une liste que le document ne tient pas, soit un chemin de fichier qui n'existe plus.
#[Description('Génère la liasse fiscale LMNP au format PDF (formulaires 2031-SD, 2033-A, 2033-B, 2033-C et 2033-D) pour un exercice fiscal donné. L\'exercice est recalculé avant la génération si nécessaire. Retourne un résumé des montants clés de la déclaration ; le PDF lui-même n\'est pas conservé sur le serveur, il se télécharge depuis la page Exercices fiscaux.')]
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

        // Génération du PDF liasse fiscale. Rendu en mémoire : rien n'est écrit sur le disque
        // (le fichier y restait en clair, avec toute la comptabilité, et personne ne le relisait).
        // `renderPdf()` enregistre au passage la date de génération sur l'exercice.
        $pdf = $this->taxReturnService->renderPdf($fiscalYear);

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
                // Clés de CETTE branche : le PDF n'existant plus sur le disque, `file_exists`
                // et `file_size_bytes` de `main` n'auraient rien à décrire — on rend la taille
                // du document tenu en mémoire, et `persisted: false` le dit explicitement.
                'filename'     => $this->taxReturnService->filename($fiscalYear),
                'size_bytes'   => strlen($pdf),
                'format'       => 'PDF (DomPDF)',
                // ⚠️ Liste prise de `main` et ANCRÉE au document par `TaxReturnFormsTest`,
                // dans les deux sens. Celle qui vivait ici annonçait « 2033-E » et « 2033-G »,
                // que la vue ne rend pas : un assistant répète l'annonce, et l'utilisateur
                // cherche des pages qui n'existent pas.
                // `formsFor()` part de `TaxReturnService::FORMS` et n'ajoute la 2031 bis-SD que
                // pour un dossier d'indivision.
                'forms'        => TaxReturnService::formsFor($user),
                'persisted'    => false,
                'generated_at' => $fiscalYear->tax_return_generated_at?->toIso8601String(),
            ],

            'message' => "Liasse fiscale {$year} générée avec succès.",
            'note'    => 'La liasse n\'est pas conservée sur le serveur : elle est reconstruite à '
                . 'chaque téléchargement depuis la page « Exercices fiscaux ». Cet appel confirme '
                . 'qu\'elle se génère et en donne les montants.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'year' => $schema->integer('Année fiscale pour laquelle générer la liasse (ex : 2025)')->required(),
        ];
    }
}
