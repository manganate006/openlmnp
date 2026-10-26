<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inscription sur invitation.
 *
 * ⚠️ TABLE DÉDIÉE, et pas des colonnes sur `users` — trois raisons qui tiennent à ce code
 * précis, pas à un principe :
 *
 * 1. L'invitation PRÉCÈDE le compte. Une ligne `users` fantôme, sans mot de passe, volerait
 *    le drapeau administrateur : `AppServiceProvider` pose `is_admin = true` sur le PREMIER
 *    `User::creating` quand la table est vide. Et `RegistrationGate` en mode « auto » compte
 *    les comptes non-démo : une invitation en attente refermerait l'inscription avant que
 *    quiconque se soit inscrit.
 * 2. Relance et révocation sont des événements de l'INVITATION, pas des états du compte.
 *    Révoquer après acceptation ne doit rien faire au compte — c'est `suspended_at` qui
 *    suspend.
 * 3. Une adresse peut être invitée, révoquée, réinvitée. Une colonne écrase, une table empile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();

            // ⚠️ PAS unique : une invitation révoquée ou expirée doit pouvoir être réémise, et
            // l'historique reste. « Une seule invitation vivante par adresse » est une règle
            // métier (InvitationService), pas SQL — un index unique partiel n'est portable ni
            // vers SQLite ni vers PostgreSQL de la même façon, et il interdirait l'historique.
            $table->string('email');

            $table->string('name')->nullable();

            // SHA-256 hexadécimal du jeton — d'où les 64 caractères, qui ne disent RIEN de la
            // longueur du jeton (48). Le jeton en clair n'est jamais stocké : le vol d'une
            // sauvegarde ne doit pas livrer un jeu de liens de création de compte valides.
            $table->string('token_hash', 64)->unique();

            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            // Relancer REMPLACE le jeton : pas d'historique de jetons à tenir, seulement un
            // compteur et une date.
            $table->unsignedSmallInteger('sent_count')->default(1);
            $table->timestamp('last_sent_at')->nullable();

            // ⚠️ nullOnDelete() des deux côtés, SURTOUT PAS cascadeOnDelete() : supprimer
            // l'administrateur émetteur effacerait la trace de l'invitation, et supprimer le
            // compte invité effacerait la preuve qu'il est entré par là. Même raisonnement
            // que `feedback`, et même piège — la purge des comptes de démonstration.
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Note interne (« vient du forum », « testeur liasse »). Jamais montrée à l'invité.
            $table->string('note')->nullable();

            $table->timestamps();

            // « Existe-t-il une invitation vivante pour cette adresse ? » — la requête du service.
            $table->index(['email', 'accepted_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
