<?php

namespace App\Mcp\Tools;

use App\Services\DocumentExportService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
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

        // L'archive est rangée dans le dossier de l'utilisateur, seul endroit que la route
        // de téléchargement accepte de servir, et le lien est réellement signé. L'ancien
        // `?signature=mcp` était refusé à tous les coups (GHSA-j4gm-g8m8-93x2, point 14).
        $path = 'documents/' . Auth::id() . '/exports/' . basename($result['path']);
        Storage::disk('local')->move($result['path'], $path);

        return Response::json([
            'success'      => true,
            'count'        => $result['count'],
            'download_url' => URL::temporarySignedRoute('documents.show', now()->addHour(), ['path' => $path]),
            'expires_in'   => '1 heure',
            'note'         => 'Lien valable une heure, pour le compte connecté. L\'archive est supprimée au bout de 24 heures.',
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
