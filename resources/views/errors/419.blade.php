{{--
    $staleContext : vieil onglet (App\Exceptions\StaleDossierContextException). La session est
    intacte, seule la page est périmée : « reconnectez-vous » serait faux.
--}}
@if($staleContext ?? false)
@include('errors._partial', [
    'code' => '419',
    'title' => 'Page périmée',
    'heading' => 'Vous avez changé de dossier dans un autre onglet',
    'message' => 'Cette page affichait encore le dossier d\'avant la bascule. Par sécurité, rien n\'a été '
        . 'fait. Revenez au tableau de bord pour continuer dans le dossier ouvert.',
    'backUrl' => url('/'),
    'backLabel' => 'Revenir au tableau de bord',
])
@else
@include('errors._partial', [
    'code' => '419',
    'title' => 'Session expirée',
    'heading' => 'Votre session a expiré',
    'message' => 'Par mesure de sécurité, les formulaires restés ouverts trop longtemps doivent '
        . 'être rechargés. Reconnectez-vous puis réessayez.',
    'backUrl' => \Illuminate\Support\Facades\Route::has('filament.admin.auth.login')
        ? route('filament.admin.auth.login')
        : url('/'),
    'backLabel' => 'Se reconnecter',
])
@endif
