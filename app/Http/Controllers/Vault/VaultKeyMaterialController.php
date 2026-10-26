<?php

namespace App\Http\Controllers\Vault;

use App\Http\Controllers\Controller;
use App\Support\Vault;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sert au navigateur de quoi dériver sa clé et desceller la clé privée, LUI-MÊME.
 *
 * Ce que cet endpoint rend n'est pas un secret : la clé privée y est **scellée**,
 * et l'ouvrir demande la phrase de passe, qui ne transite jamais. Les paramètres
 * KDF sont publics par construction — ils voyagent d'ordinaire dans l'en-tête du
 * fichier chiffré, chez tous les logiciels qui font ça.
 *
 * ⚠️ Ce qui compte ici est ce qu'on ne rend PAS : ni la DEK, ni la clé privée en
 * clair, ni quoi que ce soit qui permette de déchiffrer sans la phrase de passe.
 * L'intérêt du chantier tient à cette ligne — si le serveur pouvait servir la clé
 * ouverte, confiner le déchiffrement au navigateur ne protégerait de rien.
 */
class VaultKeyMaterialController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $vault = Vault::vaultOf($request->user());

        if ($vault === null) {
            return response()->json(['message' => "Le coffre-fort n'est pas activé sur ce compte."], 404);
        }

        if (blank($vault->wrapped_secret_key)) {
            // Coffre d'avant la paire de clés : rien à desceller côté navigateur.
            // Le dire explicitement évite que le client conclue à une panne.
            return response()->json([
                'message' => 'Ce coffre-fort date d\'avant la paire de clés : le déchiffrement '
                    . 'se fait encore côté serveur. Terminez sa migration pour en profiter.',
                'scheme' => 'legacy',
            ], 409);
        }

        return response()->json([
            'scheme' => 'sealed',
            'kdf' => [
                'salt' => $vault->kdf_salt,
                'ops' => $vault->kdf_ops,
                'mem' => $vault->kdf_mem,
                'alg' => $vault->kdf_alg,
            ],
            'public_key' => $vault->public_key,
            'wrapped_secret_key' => $vault->wrapped_secret_key,
            'secret_key_nonce' => $vault->secret_key_nonce,
        ]);
    }
}
