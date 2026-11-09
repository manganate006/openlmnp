<?php

namespace App\Exceptions;

use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Vieil onglet (App\Http\Middleware\VerifyDossierContext) : la page qui envoie la requête a été
 * rendue AVANT une bascule de dossier faite dans un autre onglet. Toujours un 419, comme un jeton
 * CSRF périmé (d'où l'héritage : `instanceof TokenMismatchException`, non journalisée, reste
 * vrai), mais distinguable de lui :
 *
 *  - en-tête `X-OpenLMNP-Context-Stale: 1`, lu par le script de
 *    `resources/views/filament/partials/dossier-context-meta.blade.php` pour afficher, sur une
 *    requête Livewire, « Vous avez changé de dossier dans un autre onglet » plutôt que la boîte
 *    native de Livewire, en anglais (D3 du test bêta du 2026-10-15) ;
 *  - page 419 dédiée pour un formulaire POST classique (bascule, retour), au lieu de
 *    « Votre session a expiré, reconnectez-vous », faux ici : la session est intacte.
 */
class StaleDossierContextException extends TokenMismatchException
{
    public const HEADER = 'X-OpenLMNP-Context-Stale';

    public function __construct()
    {
        parent::__construct('Contexte de dossier périmé : rechargez la page.');
    }

    public function render(Request $request): Response
    {
        return response()
            ->view('errors.419', ['staleContext' => true], 419)
            ->header(self::HEADER, '1');
    }
}
