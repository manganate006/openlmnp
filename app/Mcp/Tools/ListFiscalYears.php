<?php

namespace App\Mcp\Tools;

use App\Models\FiscalYear;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Liste tous les exercices fiscaux de l\'utilisateur triés par année décroissante. Pour chaque exercice : statut, résultat fiscal, recettes totales, charges totales, amortissements, amortissements plafonnés et report déficitaire.')]
#[IsReadOnly]
class ListFiscalYears extends Tool
{
    protected string $name = 'list_fiscal_years';

    public function handle(Request $request): Response
    {
        // BelongsToUserScope filtre automatiquement par user_id
        $fiscalYears = FiscalYear::orderBy('year', 'desc')->get();

        $statusLabels = FiscalYear::statusLabels();

        $data = $fiscalYears->map(function (FiscalYear $fy) use ($statusLabels) {
            return [
                'id'                        => $fy->id,
                'year'                      => $fy->year,
                'status'                    => $fy->status,
                'status_label'              => ($statusLabels[$fy->status] ?? $fy->status),
                'total_income_eur'          => bcdiv((string) $fy->total_income, '100', 2),
                'total_expenses_eur'        => bcdiv((string) $fy->total_expenses, '100', 2),
                'total_depreciation_eur'    => bcdiv((string) $fy->total_depreciation, '100', 2),
                'capped_depreciation_eur'   => bcdiv((string) $fy->capped_depreciation, '100', 2),
                'deferred_depreciation_eur' => bcdiv((string) $fy->deferred_depreciation, '100', 2),
                'previous_deferred_eur'     => bcdiv((string) $fy->previous_deferred, '100', 2),
                'fiscal_result_eur'         => $fy->fiscal_result_euros,
                'has_pdf'                   => $fy->pdf_path !== null,
                'has_fec'                   => $fy->fec_path !== null,
                'transmitted_at'            => $fy->transmitted_at?->toDateTimeString(),
                'ack_number'                => $fy->ack_number ?? null,
            ];
        });

        return Response::json([
            'count'        => $fiscalYears->count(),
            'fiscal_years' => $data,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
