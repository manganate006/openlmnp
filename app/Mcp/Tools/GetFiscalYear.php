<?php

namespace App\Mcp\Tools;

use App\Models\FiscalYear;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Retourne le détail complet d\'un exercice fiscal par son année : résultat fiscal, recettes, charges, amortissements (total, plafonné, reporté), TVA, form_data du formulaire 2031/2033, et informations de télétransmission.')]
#[IsReadOnly]
class GetFiscalYear extends Tool
{
    protected string $name = 'get_fiscal_year';

    public function handle(Request $request): Response
    {
        // BelongsToUserScope filtre par user_id automatiquement
        $fy = FiscalYear::where('year', $request->get('year'))->firstOrFail();

        $statusLabels = FiscalYear::statusLabels();

        return Response::json([
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
            'tva'                       => [
                'total_collected_eur'   => bcdiv((string) $fy->total_tva_collected, '100', 2),
                'total_deductible_eur'  => bcdiv((string) $fy->total_tva_deductible, '100', 2),
                'balance_eur'           => bcdiv((string) $fy->tva_balance, '100', 2),
            ],
            'form_data'           => $fy->form_data,
            'has_pdf'             => $fy->pdf_path !== null,
            'pdf_path'            => $fy->pdf_path,
            'has_fec'             => $fy->fec_path !== null,
            'fec_path'            => $fy->fec_path,
            'transmitted_at'      => $fy->transmitted_at?->toDateTimeString(),
            'ack_number'          => $fy->ack_number ?? null,
            'created_at'          => $fy->created_at?->toDateTimeString(),
            'updated_at'          => $fy->updated_at?->toDateTimeString(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'year' => $schema->integer('Année fiscale à récupérer, ex: 2024')->required(),
        ];
    }
}
