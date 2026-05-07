<?php

namespace App\Mcp\Tools;

use App\Models\Property;
use App\Services\AirbnbImportService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\UploadedFile;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Importe un fichier CSV Airbnb (historique des transactions ou réservations) dans un bien donné. Le contenu du fichier CSV doit être encodé en base64 dans le champ csv_base64. Retourne le nombre de lignes importées, ignorées et les erreurs éventuelles.')]
#[IsDestructive]
class ImportAirbnbCsv extends Tool
{
    protected string $name = 'import_airbnb_csv';

    public function __construct(private AirbnbImportService $importer) {}

    public function handle(Request $request): Response
    {
        $propertyId = $request->get('property_id');
        $csvBase64  = $request->get('csv_base64');
        $preview    = (bool) $request->get('preview', false);

        if (! $propertyId) {
            return Response::error('Le champ property_id est requis.');
        }
        if (! $csvBase64) {
            return Response::error('Le champ csv_base64 est requis (contenu CSV encodé en base64).');
        }

        $property = Property::findOrFail((int) $propertyId);

        $csvContent = base64_decode($csvBase64, strict: true);
        if ($csvContent === false) {
            return Response::error('Le champ csv_base64 n\'est pas un base64 valide.');
        }

        // Écrire dans un fichier temporaire pour créer un UploadedFile
        $tmpPath = tempnam(sys_get_temp_dir(), 'airbnb_mcp_');
        file_put_contents($tmpPath, $csvContent);

        try {
            $file = new UploadedFile(
                path: $tmpPath,
                originalName: 'airbnb_import.csv',
                mimeType: 'text/csv',
                error: UPLOAD_ERR_OK,
                test: true,
            );

            if ($preview) {
                $result = $this->importer->preview($file, $property);
                return Response::json([
                    'preview'   => true,
                    'row_count' => count($result['rows']),
                    'skipped'   => $result['skipped'],
                    'errors'    => $result['errors'],
                    'warnings'  => $result['warnings'] ?? [],
                    'rows'      => array_slice($result['rows'], 0, 5), // 5 premières lignes
                ]);
            }

            $result = $this->importer->import($file, $property);

            return Response::json([
                'success'  => true,
                'imported' => $result['imported'],
                'skipped'  => $result['skipped'],
                'errors'   => $result['errors'],
            ]);
        } finally {
            @unlink($tmpPath);
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            $schema->integer('property_id')->description('ID du bien dans lequel importer les revenus')->required(),
            $schema->string('csv_base64')->description('Contenu du fichier CSV Airbnb encodé en base64')->required(),
            $schema->boolean('preview')->description('Si true, simule l\'import sans enregistrer (affiche les 5 premières lignes)')->default(false),
        ];
    }
}
