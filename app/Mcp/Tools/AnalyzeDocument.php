<?php

namespace App\Mcp\Tools;

use App\Models\Property;
use App\Services\Ai\Analysis\AnalysisResult;
use App\Services\Ai\Analysis\DocumentFile;
use App\Services\Ai\Analysis\Prompts\Prompt;
use App\Services\Ai\DocumentAnalysisService;
use App\Services\Ai\Exceptions\AiException;
use App\Support\RemoteFile;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use RuntimeException;

#[Description(
    'Analyse un justificatif (facture, avis de taxe foncière, attestation d\'assurance, tableau d\'amortissement, relevé bancaire) '
    . 'et retourne une proposition de saisie structurée, SANS RIEN CRÉER. '
    . 'Trois modes d\'entrée mutuellement exclusifs : file_base64, file_path (nécessite MCP_FILE_PATH_PREFIX), file_url. '
    . 'Formats acceptés : PDF, JPEG, PNG, WebP (10 Mo max). '
    . 'Enchaînez ensuite vous-même avec create_expense / create_furniture / create_property_work / attach_document '
    . 'après avoir fait valider les valeurs par l\'utilisateur : elles proviennent d\'un modèle de langage et peuvent être erronées. '
    . 'Chaque appel consomme le quota d\'analyse mensuel du compte. Tous les montants sont en euros.'
)]
#[IsReadOnly]
class AnalyzeDocument extends Tool
{
    protected string $name = 'analyze_document';

    public function handle(Request $request, DocumentAnalysisService $service): Response
    {
        $validated = $request->validate([
            'property_id' => 'required|integer',
            'file_base64' => 'nullable|string',
            'file_path' => 'nullable|string|max:1024',
            'file_url' => 'nullable|url|max:2048',
            'filename' => 'nullable|string|max:255',
            'document_type' => 'nullable|string|in:' . implode(',', Prompt::TYPES),
        ]);

        // BelongsToUserScope sur Property : un bien étranger est introuvable.
        $property = Property::findOrFail($validated['property_id']);

        try {
            [$contents, $filename] = RemoteFile::resolve($validated, $validated['filename'] ?? null);

            $result = $service->analyze(
                DocumentFile::fromContents($contents, $filename),
                $property,
                $validated['document_type'] ?? null,
            );
        } catch (AiException|RuntimeException $e) {
            // Message français fixe : ni la clé, ni la réponse brute du fournisseur.
            return Response::error($e->getMessage());
        }

        return Response::json($this->present($result, $property));
    }

    /**
     * Proposition en euros — convention MCP : les outils parlent en euros,
     * la base en centimes.
     *
     * @return array<string, mixed>
     */
    private function present(AnalysisResult $result, Property $property): array
    {
        return [
            'document_type' => $result->documentType,
            'target_entity' => $result->entity(),
            'confidence' => round($result->confidence, 2),
            'recognised' => ! $result->isUnrecognised(),
            'model' => $result->model,
            'property' => ['id' => $property->id, 'name' => $property->name],

            'proposal' => $this->toEuros($result->values),
            'field_confidence' => array_map(fn ($c) => round((float) $c, 2), $result->confidences),
            'evidence' => $result->evidence,
            'warnings' => $result->warnings,
            'rows' => array_map(fn (array $row) => $this->toEuros($row), $result->rows),
            'duplicate' => $result->duplicate === null ? null : [
                'id' => $result->duplicate['id'],
                'label' => $result->duplicate['label'],
                'date' => $result->duplicate['date'],
                'amount_eur' => bcdiv((string) $result->duplicate['amount'], '100', 2),
            ],

            'next_steps' => 'Faites valider ces valeurs par l\'utilisateur, puis créez l\'écriture avec '
                . 'create_expense / create_furniture / create_property_work / create_income, et joignez le fichier avec attach_document. '
                . 'Cet outil ne crée rien.',
        ];
    }

    /**
     * Convertit en euros les champs monétaires, en renommant la clé pour que
     * l'unité soit explicite (`amount` -> `amount_eur`) : un agent qui
     * renverrait 14320 dans create_expense créerait une charge de 143 200 €.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function toEuros(array $values): array
    {
        $monetaires = [
            'amount', 'monthly_payment', 'insurance_monthly',
            'capital_amount', 'interest_amount', 'insurance_amount', 'remaining_capital',
        ];

        foreach ($monetaires as $champ) {
            if (! array_key_exists($champ, $values) || $values[$champ] === null) {
                continue;
            }

            $values[$champ . '_eur'] = bcdiv((string) (int) $values[$champ], '100', 2);
            unset($values[$champ]);
        }

        return $values;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'property_id' => $schema->integer('Identifiant du bien concerné par le justificatif')->required(),
            'file_base64' => $schema->string('Contenu du fichier encodé en base64 (PDF, JPG, PNG, WebP — max 10 Mo). Mutuellement exclusif avec file_path et file_url.')->nullable(),
            'file_path' => $schema->string('Chemin absolu du fichier sur le serveur (nécessite MCP_FILE_PATH_PREFIX configuré). Mutuellement exclusif avec file_base64 et file_url.')->nullable(),
            'file_url' => $schema->string('URL http/https du fichier à télécharger par le serveur. Mutuellement exclusif avec file_base64 et file_path.')->nullable(),
            'filename' => $schema->string('Nom du fichier avec extension (ex: facture.pdf). Déduit de file_path ou file_url si absent.')->nullable(),
            'document_type' => $schema->string('Force le type de document au lieu de le laisser détecter : ' . implode(', ', Prompt::TYPES))->nullable(),
        ];
    }
}
