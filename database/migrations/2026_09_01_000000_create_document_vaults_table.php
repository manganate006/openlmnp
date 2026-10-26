<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coffre-fort des justificatifs : une ligne par utilisateur ayant activé le
 * chiffrement.
 *
 * La phrase de passe n'est stockée NULLE PART, ni en clair ni hachée. Cette
 * table ne contient que le sel de dérivation, les paramètres Argon2id retenus
 * et la clé de données scellée : sans la phrase de passe, rien ici ne permet
 * de retrouver un fichier. L'échec du descellement AEAD tient lieu de
 * vérificateur de saisie.
 *
 * Les valeurs binaires sont stockées en base64 dans des colonnes texte, pour
 * rester portables entre SQLite (défaut) et PostgreSQL (option SaaS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_vaults', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // Dérivation de la clé de chiffrement de clé (KEK) à partir de la
            // phrase de passe. Les paramètres sont figés par utilisateur : faire
            // évoluer le profil par défaut de l'instance n'invalide aucun coffre.
            $table->string('kdf_salt');
            $table->unsignedInteger('kdf_ops');
            $table->unsignedBigInteger('kdf_mem');
            $table->unsignedTinyInteger('kdf_alg');

            // Clé de données (DEK) scellée par la KEK. Changer de phrase de passe
            // ne rescelle que ces deux colonnes : aucun fichier n'est réécrit.
            $table->text('wrapped_dek');
            $table->string('wrap_nonce');

            // Empreinte de la DEK : permet de valider une clé venue du cookie de
            // session sans redemander la phrase de passe, et d'écarter un cookie
            // périmé plutôt que de chiffrer avec une clé fantaisiste.
            $table->string('dek_fingerprint');

            $table->unsignedInteger('key_version')->default(1);

            // active | encrypting | decrypting — une migration interrompue laisse
            // l'état intermédiaire visible et reprenable.
            $table->string('status', 20)->default('active');

            $table->timestamp('enabled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_vaults');
    }
};
