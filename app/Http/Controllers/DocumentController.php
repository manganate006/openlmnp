<?php

namespace App\Http\Controllers;

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

        if (! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        return Storage::disk('local')->response($path, headers: [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);
    }
}
