<?php

namespace App\Http\Controllers;

use App\Services\Vault\DocumentCipher;
use App\Services\Vault\VaultException;
use App\Support\DocumentStorage;
use App\Support\Vault;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Service des justificatifs, sur URL signée.
 *
 * Le fichier est envoyé par l'utilisateur et servi depuis NOTRE origine : s'il
 * s'exécutait, ce serait un XSS stocké avec la session de son propriétaire.
 * Trois garde-fous, dans cet ordre :
 *
 *   1. le `Content-Type` est IMPOSÉ depuis l'extension, jamais déduit du
 *      contenu. Laravel, laissé à lui-même, sert un HTML nommé `facture.pdf`
 *      en `text/html` (détection par `finfo` sur les octets) : c'est
 *      exactement le scénario dangereux ;
 *   2. tout ce qui n'est pas affichable (archive, extension inconnue) part en
 *      pièce jointe, donc n'est jamais rendu ;
 *   3. la CSP interdit script, cadre et formulaire.
 *
 * ⚠️ La CSP ne porte volontairement PAS la directive `sandbox` nue : elle
 * imposerait au document une origine opaque et empêcherait la visionneuse PDF
 * de Chrome de s'ouvrir — l'aperçu affichait « Cette page a été bloquée ».
 * C'est le point 1 qui rend le contenu inoffensif, pas le bac à sable.
 */
class DocumentController extends Controller
{
    /**
     * Types servis tels quels, par extension. Tout le reste est téléchargé.
     *
     * Liste alignée sur App\Services\Ai\Analysis\DocumentFile::ACCEPTED_MIME,
     * plus les formats historiquement acceptés par la section « Pièces
     * justificatives » (HEIC, archives).
     */
    private const INLINE_TYPES = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    public function __invoke(Request $request, string $path)
    {
        // Signature vérifiée par le middleware 'signed'.
        // Vérifier que l'utilisateur est connecté.
        if (! $request->user()) {
            abort(403);
        }

        // Flysystem résout les « .. » internes sans lever d'erreur : documents/5/../7/x
        // passerait le contrôle ci-dessous puis ouvrirait le dossier de l'utilisateur 7.
        if (str_contains($path, '..')) {
            abort(404);
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
        //
        // ⚠️ Le disque résolu ici commande TOUT ce qui suit — détection du chiffrement,
        // lecture du flux et service du clair : le coffre-fort s'applique au fichier
        // trouvé, quelle que soit la racine où il l'a été. Le détail de ce croisement
        // est dans `DocumentStorage::readDisk()`.
        $disk = DocumentStorage::readDisk($path);

        if (! $disk->exists($path)) {
            abort(404);
        }

        $cipher = app(DocumentCipher::class);
        $absolute = $disk->path($path);

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $inlineType = self::INLINE_TYPES[$extension] ?? null;

        // Fichier en clair (coffre-fort jamais activé, ou pas encore migré) :
        // comportement d'origine, mais avec la politique d'en-têtes durcie — un
        // HTML déguisé en « facture.pdf » ne doit pas être servi en text/html, que
        // le coffre soit actif ou non. Le chemin chiffré l'imposait déjà ; celui-ci
        // s'en remettait encore au finfo de Laravel.
        //
        // ⚠️ `$disk` et non `Storage::disk('local')` : c'est lui qui sert encore les
        // justificatifs déposés avant Laravel 11 (voir le repli ci-dessus). L'écrire
        // en dur les mettrait en 404 sur toute instance qui n'a jamais lancé
        // `openlmnp:migrate-document-storage`.
        if (! $cipher->isEncryptedFile($absolute)) {
            return $disk->response(
                $path,
                headers: $this->headers() + [
                    'Content-Type' => $inlineType ?? 'application/octet-stream',
                    'X-Content-Type-Options' => 'nosniff',
                ],
                disposition: $inlineType === null ? 'attachment' : 'inline',
            );
        }

        // Déchiffrement navigateur : servir le CHIFFRÉ tel quel, à charge du client
        // de l'ouvrir avec la clé qu'il a descellée lui-même. Le serveur n'a alors
        // besoin d'aucune clé — c'est tout l'intérêt.
        if ($request->boolean('raw')) {
            return $disk->response($path, headers: [
                'Content-Type' => 'application/octet-stream',
                'X-Vault-Encrypted' => '1',
                // Sans ça, un navigateur pourrait tenter d'afficher du binaire
                // chiffré comme s'il s'agissait du document.
                'Content-Disposition' => 'attachment',
                'Cache-Control' => 'private, no-store',
            ]);
        }

        // Fichier SCELLÉ (déposé coffre verrouillé, format 2) : il ne s'ouvre qu'avec la
        // clé privée, qui ne quitte jamais le navigateur. Le serveur ne peut pas le lire,
        // même coffre déverrouillé. Tenter quand même partait en flux tronqué
        // (ERR_HTTP2_PROTOCOL_ERROR, « Justificatif chiffré illisible » au journal) dès
        // qu'on ouvrait le lien sans passer par le module du navigateur : clic du milieu,
        // « ouvrir dans un nouvel onglet », adresse recopiée (constaté en bêta, 2026-10-25).
        if (($cipher->formatOf($absolute) ?? DocumentCipher::FORMAT_VERSION) >= DocumentCipher::FORMAT_VERSION_SEALED) {
            abort(423, 'Ce justificatif est chiffré pour votre seul navigateur : le serveur ne peut pas l\'ouvrir. '
                .'Depuis sa fiche, cliquez sur l\'icône d\'ouverture ou de téléchargement du document, puis saisissez votre phrase de passe.');
        }

        $key = Vault::keyFor($request->user());

        if ($key === null) {
            // 423 Locked : le fichier existe et appartient bien à l'utilisateur,
            // mais sa clé n'est pas disponible dans cette session.
            abort(423, Vault::blockedReasonFor($request->user()) ?? VaultException::locked()->getMessage());
        }

        $plainSize = $cipher->plainSize($absolute);

        return new StreamedResponse(
            function () use ($absolute, $key, $cipher, $path): void {
                $source = @fopen($absolute, 'rb');

                if ($source === false) {
                    return;
                }

                $output = fopen('php://output', 'wb');

                try {
                    $cipher->decrypt($source, $output, $key);
                } catch (VaultException $e) {
                    // Le chiffrement est authentifié : jamais d'octets faux servis à
                    // la place du contenu. Une altération interrompt le flux, et le
                    // Content-Length annoncé signale au client une réponse
                    // incomplète. On journalise le chemin, jamais le contenu.
                    Log::warning('Justificatif chiffré illisible', ['path' => $path]);
                } finally {
                    fclose($source);
                    fclose($output);
                }
            },
            200,
            $this->headers() + array_filter([
                'Content-Type' => DocumentStorage::mimeForExtension($path),
                'Content-Disposition' => 'inline; filename="' . addslashes(basename($path)) . '"',
                // Taille du CLAIR, lue dans l'en-tête du fichier chiffré : sans elle
                // le navigateur ne sait pas quand la réponse est complète.
                'Content-Length' => $plainSize !== null ? (string) $plainSize : null,
                // Un flux secretstream ne se découpe pas : refuser explicitement les
                // requêtes partielles plutôt que de laisser un intermédiaire les
                // annoncer à notre place.
                'Accept-Ranges' => 'none',
            ]),
        );
    }

    /**
     * En-têtes communs aux deux chemins de service.
     *
     * ⚠️ `Content-Type` est imposé d'après l'EXTENSION côté fichier chiffré : le
     * contenu déchiffré n'est jamais soumis à `finfo`, et il ne doit pas l'être —
     * un HTML nommé « facture.pdf » serait servi en text/html, exécuté dans notre
     * origine. `X-Content-Type-Options: nosniff` est posé globalement par
     * SecurityHeaders : un type erroné fait échouer le rendu, sans repli.
     */
    private function headers(): array
    {
        return [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            // ⚠️ PAS de `sandbox` : il donne au document une origine opaque et
            // empêche la visionneuse PDF de Chrome de s'ouvrir. La protection vient
            // des directives explicites, pas du bac à sable.
            'Content-Security-Policy' => implode('; ', [
                "default-src 'none'",
                "img-src 'self' data:",
                "style-src 'unsafe-inline'",
                "object-src 'self'",
                // Affichable uniquement dans nos propres pages.
                "frame-ancestors 'self'",
                "base-uri 'none'",
                "form-action 'none'",
            ]),
        ];
    }
}
