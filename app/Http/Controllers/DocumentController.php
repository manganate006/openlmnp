<?php

namespace App\Http\Controllers;

use App\Support\DocumentStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    public function __invoke(Request $request, string $path)
    {
        // Signature vérifiée par le middleware 'signed'.
        // Vérifier que l'utilisateur est connecté.
        if (! $request->user()) {
            abort(403);
        }

        // Le path est de la forme : documents/{user_id}/{type}/{filename}
        // Vérifier que le user_id dans le path correspond à l'utilisateur connecté.
        if (! preg_match('#^documents/(\d+)/#', $path, $matches)) {
            abort(404);
        }

        if ((int) $matches[1] !== $request->user()->id) {
            abort(403);
        }

        // Repli sur l'ancienne racine `storage/app` pour les fichiers déposés avant que
        // Laravel 11 ne déplace le disque `local` vers `storage/app/private`. Sans lui,
        // toute instance ayant franchi cette montée de version perd silencieusement ses
        // justificatifs d'avant. `openlmnp:migrate-document-storage` règle la dette à la
        // source ; ce repli couvre les instances où elle ne sera jamais lancée.
        $disk = DocumentStorage::isLegacyOnly($path)
            ? DocumentStorage::legacyDisk()
            : Storage::disk('local');

        if (! $disk->exists($path)) {
            abort(404);
        }

        return $disk->response($path, headers: [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);
    }
}
