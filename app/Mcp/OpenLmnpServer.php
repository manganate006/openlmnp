<?php

namespace App\Mcp;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('OpenLMNP')]
#[Version('1.0.0')]
#[Instructions(<<<'MARKDOWN'
Serveur MCP pour OpenLMNP — logiciel de comptabilité LMNP (Location Meublée Non Professionnelle).

Ce serveur permet de gérer les données comptables d'un loueur meublé :
- Consulter et gérer les biens immobiliers, revenus, charges, emprunts
- Calculer les amortissements, résultats fiscaux, TVA
- Comparer les régimes micro-BIC et réel
- Générer les exports comptables (FEC) et la liasse fiscale (PDF)
- Joindre des justificatifs aux dépenses, travaux et mobilier

Tous les montants sont exprimés en euros dans les entrées et sorties.
Les dates sont au format ISO 8601 (YYYY-MM-DD).
MARKDOWN)]
class OpenLmnpServer extends Server
{
    public int $defaultPaginationLength = 50;

    protected array $tools = [
        // Lecture
        Tools\ListProperties::class,
        Tools\GetProperty::class,
        Tools\ListIncomes::class,
        Tools\GetIncome::class,
        Tools\ListExpenses::class,
        Tools\GetExpense::class,
        Tools\ListLoans::class,
        Tools\GetLoan::class,
        Tools\GetLoanSchedule::class,
        Tools\ListFiscalYears::class,
        Tools\GetFiscalYear::class,
        Tools\ListFurniture::class,
        Tools\ListPropertyWorks::class,
        Tools\ListPropertyComponents::class,
        Tools\GetOnboardingStatus::class,
        Tools\GetDashboardSummary::class,

        // Écriture
        Tools\CreateIncome::class,
        Tools\UpdateIncome::class,
        Tools\CreateExpense::class,
        Tools\UpdateExpense::class,
        Tools\CreateFurniture::class,
        Tools\UpdateFurniture::class,
        Tools\CreatePropertyWork::class,
        Tools\UpdatePropertyWork::class,
        Tools\AttachDocument::class,

        // Calcul & export
        Tools\ComputeDepreciation::class,
        Tools\ComputeFiscalYear::class,
        Tools\CompareMicroBic::class,
        Tools\ComputeLoanSchedule::class,
        Tools\ComputeTva::class,
        Tools\GenerateFec::class,
        Tools\GenerateTaxReturn::class,

        // Gestion biens
        Tools\CreateProperty::class,
        Tools\UpdateProperty::class,

        // Import & analyse
        Tools\ImportAirbnbCsv::class,
        Tools\GetProjection::class,
        Tools\GetSimulation::class,
    ];

    protected array $resources = [];

    protected array $prompts = [];
}
