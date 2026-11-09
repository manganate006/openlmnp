<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\Ai\Exceptions\AiOauthFailed;
use App\Services\Ai\OpenRouter\OpenRouterOauth;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Aller-retour « Se connecter avec OpenRouter ».
 *
 * Ces deux routes sont déclarées dans `Panel::authenticatedRoutes()` : elles
 * héritent ainsi du middleware du panel ET de son garde d'authentification, qui
 * conserve l'URL demandée et refuse un compte suspendu — sans une ligne de
 * garde ici.
 *
 * ⚠️ Le code d'autorisation arrive en paramètre d'URL, ce que le fournisseur
 * impose. Sur l'image Docker, `php artisan serve` écrit chaque ligne de requête
 * avec sa chaîne de requête sur la sortie d'erreur : le code se retrouve donc
 * dans `docker logs`. Il est à usage unique, consommé dans la seconde, expire en
 * dix minutes et reste inutilisable sans le vérificateur de la session — mais
 * cela s'assume par écrit plutôt que de se taire. La CLÉ, elle, ne transite
 * jamais par une URL : elle arrive dans le corps d'une réponse POST.
 */
class OpenRouterOauthController extends Controller
{
    public function __construct(private readonly OpenRouterOauth $flow) {}

    /** Départ : pose le vérificateur en session et redirige vers le fournisseur. */
    public function start(Request $request): RedirectResponse
    {
        $user = $this->user();

        // 404 et non 403 : sur une instance qui n'a pas cette fonctionnalité,
        // le nier vaut mieux que renseigner sur sa configuration.
        abort_unless(OpenRouterOauth::isAvailableFor($user), 404);

        try {
            // Construit depuis la requête, jamais depuis APP_URL : une instance
            // atteinte par son adresse de réseau local doit revenir au même
            // endroit, pas sur un nom d'hôte que le navigateur ne connaît pas.
            $url = $this->flow->begin($user, route('filament.admin.ai.openrouter.callback'));
        } catch (AiOauthFailed $e) {
            return $this->backToProfile($e->getMessage(), success: false);
        }

        return redirect()->away($url);
    }

    /** Retour : échange le code contre la clé de l'utilisateur. */
    public function callback(Request $request): RedirectResponse
    {
        $user = $this->user();

        abort_unless(OpenRouterOauth::isAvailableFor($user), 404);

        try {
            $this->flow->exchange($user, $request->query('code'));
        } catch (AiOauthFailed $e) {
            return $this->backToProfile($e->getMessage(), success: false);
        }

        return $this->backToProfile($this->successMessage($user->refresh()), success: true);
    }

    /**
     * Message de succès, enrichi du crédit restant quand le fournisseur le dit.
     *
     * Une clé toute neuve n'a souvent aucun crédit : le dire tout de suite évite
     * la première analyse qui échoue sans qu'on comprenne pourquoi.
     */
    private function successMessage(User $user): string
    {
        $base = "Les fonctions d'intelligence artificielle sont activées pour votre compte.";

        try {
            $status = AiClient::for($user)->checkKey();
        } catch (Throwable) {
            // La vérification est un bonus : elle ne doit jamais faire échouer
            // une connexion qui a réussi.
            return $base;
        }

        if ($status->valid && $status->limitRemaining !== null && (float) $status->limitRemaining <= 0.0) {
            return "Votre compte n'a pas de crédit disponible : les analyses échoueront tant "
                . "qu'il n'est pas rechargé.";
        }

        return $status->valid && filled($status->limitRemaining)
            ? $base . " Crédit restant : {$status->limitRemaining} \$."
            : $base;
    }

    private function backToProfile(string $message, bool $success): RedirectResponse
    {
        $notification = Notification::make()
            ->title($success ? 'Clé enregistrée' : "La connexion n'a pas abouti")
            ->body($message);

        $success ? $notification->success() : $notification->danger();

        $notification->send();

        return redirect()->route('filament.admin.auth.profile');
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
