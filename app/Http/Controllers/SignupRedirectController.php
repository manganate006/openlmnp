<?php

namespace App\Http\Controllers;

use Filament\Facades\Filament;

/**
 * /register quand l'inscription publique est fermée.
 *
 * Filament ne déclare sa route `register` QUE si le panel a appelé ->registration() :
 * porte fermée, l'URL n'existait plus du tout et Laravel rendait un 404 nu. Un prospect
 * qui tape l'adresse, ou qui suit un vieux lien, n'avait alors aucun recours — constaté
 * au support. Cette route de repli est posée à la place, par AdminPanelProvider (voir le
 * commentaire là-bas, qui explique pourquoi ce n'est pas dans routes/web.php).
 *
 * Deux destinations, selon que l'instance déclare ou non une page de souscription :
 * celle-ci si elle existe, la page de connexion et une explication sinon. Une instance
 * auto-hébergée n'a rien à vendre : elle tombe dans le second cas, et c'est le défaut.
 */
class SignupRedirectController extends Controller
{
    public function __invoke()
    {
        $url = trim((string) config('services.website.signup_url'));

        if ($url !== '') {
            // 302 et surtout pas 301 : ALLOW_REGISTRATION peut repasser à `true`, et un
            // 301 resterait dans le cache du navigateur — /register ne reviendrait jamais
            // pour qui l'a visité une fois.
            //
            // away() et non to() : la cible est hors application et ne doit pas être
            // réécrite par le générateur d'URL. Aucun risque de redirection ouverte, la
            // valeur vient de la configuration de l'exploitant et jamais de la requête.
            return redirect()->away($url, 302);
        }

        return redirect()->to(
            Filament::getCurrentOrDefaultPanel()->getLoginUrl(['inscription' => 'fermee']),
        );
    }
}
