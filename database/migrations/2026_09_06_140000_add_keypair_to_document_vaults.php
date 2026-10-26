<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paire de clés X25519 du coffre-fort : chiffrer sans pouvoir déchiffrer.
 *
 * Jusqu'ici une DEK symétrique unique servait aux deux sens. Conséquence directe :
 * coffre verrouillé, on ne pouvait pas non plus ÉCRIRE — le champ de dépôt était
 * grisé, et l'API MCP refusait tout justificatif. Avec une paire de clés, la clé
 * publique reste en clair et suffit à chiffrer ; seule la lecture demande la
 * phrase de passe.
 *
 * Additive et nullable : un coffre existant continue de fonctionner en
 * `key_version = 1` tant qu'il n'a pas été remigré, et un déploiement peut être
 * rebasculé en arrière sans perdre de donnée.
 *
 * ⚠️ `secret_key_nonce` est distinct de `wrap_nonce` : réutiliser un nonce entre
 * deux scellements sous la MÊME clé (la KEK dérivée de la phrase de passe) casse
 * la sécurité de XSalsa20-Poly1305. Deux secrets scellés, deux nonces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_vaults', function (Blueprint $table) {
            // Clé publique X25519, en CLAIR et c'est le principe : elle ne
            // permet que de chiffrer.
            $table->text('public_key')->nullable()->after('dek_fingerprint');

            // Clé privée scellée par la clé dérivée de la phrase de passe,
            // exactement comme la DEK l'est déjà.
            $table->text('wrapped_secret_key')->nullable()->after('public_key');
            $table->string('secret_key_nonce')->nullable()->after('wrapped_secret_key');

            // Combien de fichiers restent à l'ancien schéma. La DEK ne peut être
            // oubliée que lorsque ce compteur tombe à zéro — un compteur, pas une
            // date : une migration interrompue laisse un reste, pas un délai.
            $table->unsignedInteger('legacy_files_remaining')->nullable()->after('key_version');
        });
    }

    public function down(): void
    {
        Schema::table('document_vaults', function (Blueprint $table) {
            $table->dropColumn([
                'public_key',
                'wrapped_secret_key',
                'secret_key_nonce',
                'legacy_files_remaining',
            ]);
        });
    }
};
