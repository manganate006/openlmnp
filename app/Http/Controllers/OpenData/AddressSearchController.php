<?php

namespace App\Http\Controllers\OpenData;

use App\Http\Controllers\Controller;
use App\Support\AddressSuggestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Autocomplétion d'adresse par la Base Adresse Nationale (API Adresse, data.geopf.fr).
 *
 * Service public, gratuit, sans clé, conçu pour l'autocomplétion, et qui rend le
 * **code postal**, la **commune** et le **code INSEE** — les trois champs qu'une saisie
 * manuelle se trompe le plus souvent à accorder entre eux.
 *
 * ⚠️ Cet endpoint ne DOIT JAMAIS bloquer un formulaire. Un service tiers tombé, lent, ou
 * qui rend une erreur, ce sont des choses qui arrivent ; un formulaire de bien qu'on ne
 * peut plus valider parce que la Base Adresse Nationale est en maintenance serait une
 * régression bien pire que l'absence de suggestions. Toute panne se traduit donc par une
 * liste VIDE et un drapeau `unavailable`, jamais par une erreur HTTP : la saisie manuelle
 * reste possible de bout en bout, et c'est le seul comportement qui compte ici.
 *
 * ⚠️ Appel sortant. Il révèle à la Géoplateforme de l'IGN le début d'adresse qu'on est en train de
 * taper. Il ne part qu'à partir de trois caractères (la garde est rejouée ici, côté
 * serveur : le navigateur n'est pas une autorisation), il est limité en débit, et
 * `ADDRESS_LOOKUP_ENABLED=false` le coupe entièrement — le script n'est alors même pas
 * injecté dans les pages.
 */
class AddressSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if (! config('opendata.address.enabled')) {
            return response()->json(['results' => [], 'unavailable' => true]);
        }

        // Garde de longueur côté serveur : le débit d'un service public gratuit ne se
        // protège pas depuis le navigateur, qui peut toujours appeler l'URL à la main.
        if (mb_strlen($query) < (int) config('opendata.address.min_length')) {
            return response()->json(['results' => []]);
        }

        // Le débit n'est pas une facturation : c'est ce qui évite qu'une frappe rapide,
        // ou un script, martèle un service public gratuit depuis une instance partagée.
        $key = 'address-lookup:'.(Auth::id() ?? 'guest');

        if (RateLimiter::tooManyAttempts($key, (int) config('opendata.address.rate_limit'))) {
            return response()->json(['results' => [], 'unavailable' => true]);
        }

        RateLimiter::hit($key, 60);

        try {
            $response = Http::timeout((int) config('opendata.address.timeout'))
                ->acceptJson()
                ->get(config('opendata.address.url'), [
                    'q' => $query,
                    'limit' => (int) config('opendata.address.limit'),
                    'autocomplete' => 1,
                ]);
        } catch (\Throwable) {
            // Réseau coupé, DNS muet, délai dépassé : rien à proposer, rien à signaler
            // de plus. Le formulaire, lui, continue de fonctionner.
            return response()->json(['results' => [], 'unavailable' => true]);
        }

        if (! $response->successful()) {
            return response()->json(['results' => [], 'unavailable' => true]);
        }

        return response()->json([
            'results' => AddressSuggestion::fromBanPayload($response->json() ?? []),
        ]);
    }
}
