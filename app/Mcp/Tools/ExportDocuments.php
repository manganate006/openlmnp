<?php

namespace App\Mcp\Tools;

use App\Services\DocumentExportService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Génère un fichier ZIP contenant tous les justificatifs (factures, reçus, devis) de l\'utilisateur, organisés par année et type (charges/mobilier/travaux). Filtrage optionnel par année et/ou type. Retourne une URL de téléchargement temporaire.')]
#[IsReadOnly]
class ExportDocuments extends Tool
{
    protected string $name = 'export_documents';

    public function __construct(private DocumentExportService $exportService) {}

    public function handle(Request $request): Response
    {
        $year = $request->get('year');
        $type = $request->get('type');

        if ($year !== null) {
            $year = (int) $year;
            if ($year < 2000 || $year > 2099) {
                return Response::error('year doit être entre 2000 et 2099.');
            }
        }

        if ($type !== null && ! in_array($type, ['expense', 'furniture', 'work'])) {
            return Response::error('type doit être : expense, furniture ou work.');
        }

        $result = $this->exportService->exportZip(Auth::user(), $year, $type);

        if ($result['path'] === null) {
            return Response::json([
                'success' => false,
                'message' => 'Aucun document à exporter.',
                'count'   => 0,
            ]);
        }

        return Response::json([
            'success'      => true,
            'count'        => $result['count'],
            'download_url' => url('/d/' . $result['path']) . '?signature=mcp',
            'file_path'    => $result['path'],
            'note'         => 'Le fichier ZIP est temporaire et sera supprimé automatiquement.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'year' => $schema->integer('Filtrer par année (ex: 2025). Sans filtre : toutes les années.'),
            'type' => $schema->string('Filtrer par type : expense (charges), furniture (mobilier), work (travaux). Sans filtre : tous les types.'),
        ];
    }
}
