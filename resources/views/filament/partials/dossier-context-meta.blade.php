{{--
    Vieil onglet (voir App\Support\DossierContext et App\Http\Middleware\VerifyDossierContext) —
    identifiant de CONTEXTE de la session, distinct du jeton CSRF : il change à chaque bascule
    d'identité d'une session déjà authentifiée (bascule vers un dossier lié, retour au principal).

    Posé dans CHAQUE page (balise <meta>, lue par le hook JS ci-dessous) et renvoyé par CHAQUE
    requête Livewire via l'en-tête `X-OpenLMNP-Context`. Les formulaires POST classiques (bascule
    et retour de dossier) portent le même identifiant dans un champ caché — voir
    `dossier-context-field.blade.php`.

    Rien à faire si personne n'est connecté : pas d'identité à protéger avant la connexion, et
    App\Http\Middleware\VerifyDossierContext laisse de toute façon passer une requête sans
    utilisateur authentifié.

    Réponse 419 d'une requête Livewire (D3 du test bêta du 2026-10-15) : Livewire affiche sinon
    sa boîte native, en anglais (« This page has expired. Would you like to refresh the
    page? »). On l'intercepte pour TOUTE page du panel, connecté ou non (la page de connexion
    est elle aussi un composant Livewire, et un jeton CSRF y expire comme ailleurs) : fenêtre
    en français, qui recharge la page. Deux messages, selon l'en-tête
    `X-OpenLMNP-Context-Stale` (App\Exceptions\StaleDossierContextException) : changement de
    dossier dans un autre onglet, ou simple session expirée.
--}}
@auth
    <meta name="openlmnp-context" content="{{ \App\Support\DossierContext::contextId() }}">
@endauth
<script>
    (function () {
        var STALE_HEADER = @json(\App\Exceptions\StaleDossierContextException::HEADER);
        var MESSAGES = {
            stale: {
                title: 'Vous avez changé de dossier dans un autre onglet',
                body: 'Cette page affichait encore le dossier d\'avant la bascule. Par sécurité, rien n\'a été enregistré. '
                    + 'Rechargez la page pour continuer dans le dossier ouvert.',
            },
            expired: {
                title: 'Cette page a expiré',
                body: 'Elle est restée ouverte trop longtemps : rien n\'a été enregistré. '
                    + 'Rechargez la page, puis recommencez votre saisie.',
            },
        };
        var shown = false;

        // Fenêtre dans la page plutôt qu'un `alert()` : elle reprend les couleurs du panel,
        // empêche toute autre saisie sur la page périmée (fond bloquant) et n'a qu'une issue,
        // le rechargement.
        function showExpired(stale) {
            if (shown) {
                return;
            }
            shown = true;

            var text = stale ? MESSAGES.stale : MESSAGES.expired;
            var backdrop = document.createElement('div');
            backdrop.id = 'openlmnp-expired';
            backdrop.setAttribute('role', 'alertdialog');
            backdrop.setAttribute('aria-modal', 'true');
            backdrop.setAttribute('aria-labelledby', 'openlmnp-expired-title');
            backdrop.setAttribute('aria-describedby', 'openlmnp-expired-body');
            backdrop.style.cssText = 'position:fixed;inset:0;z-index:2147483647;display:flex;align-items:center;'
                + 'justify-content:center;padding:1rem;background:rgba(15,23,42,.55)';

            var card = document.createElement('div');
            card.style.cssText = 'max-width:28rem;width:100%;border-radius:.75rem;padding:1.5rem;'
                + 'background:var(--olmnp-surface,#fff);color:var(--olmnp-fg,#0f172a);'
                + 'border:1px solid var(--olmnp-border,#e4e4e7);box-shadow:0 10px 30px rgba(0,0,0,.25);'
                + 'font-family:inherit';

            var title = document.createElement('h2');
            title.id = 'openlmnp-expired-title';
            title.textContent = text.title;
            title.style.cssText = 'margin:0 0 .5rem;font-size:1.05rem;font-weight:600;color:var(--olmnp-fg-strong,inherit)';

            var body = document.createElement('p');
            body.id = 'openlmnp-expired-body';
            body.textContent = text.body;
            body.style.cssText = 'margin:0 0 1.25rem;font-size:.9rem;line-height:1.5;color:var(--olmnp-fg-muted,inherit)';

            var button = document.createElement('button');
            button.type = 'button';
            button.textContent = 'Recharger la page';
            button.style.cssText = 'padding:.55rem 1.1rem;border-radius:.5rem;border:0;cursor:pointer;font-weight:600;'
                + 'background:var(--olmnp-success-solid-hover,#059669);color:var(--olmnp-on-solid,#fff)';
            button.addEventListener('click', function () {
                window.location.reload();
            });

            card.appendChild(title);
            card.appendChild(body);
            card.appendChild(button);
            backdrop.appendChild(card);
            document.body.appendChild(backdrop);
            button.focus();
        }

        // Exposé pour la vérification au navigateur et pour qui voudrait l'appeler ailleurs.
        window.openlmnpShowExpired = showExpired;

        // `livewire:init` : le document écoute cet événement AVANT même que le script
        // Livewire (chargé en fin de page) ne soit évalué — voir
        // vendor/livewire/livewire/src/js/lifecycle.js, `start()` le déclenche sur
        // `document` dès l'initialisation, APRÈS que `window.Livewire` existe déjà
        // (assignation synchrone au chargement du module).
        document.addEventListener('livewire:init', function () {
            var Livewire = window.Livewire;

            if (typeof Livewire === 'undefined') {
                return;
            }

            var metaEl = document.querySelector('meta[name="openlmnp-context"]');
            var context = metaEl ? metaEl.getAttribute('content') : null;

            // `Livewire.hook('request', …)` reste l'API publique de Livewire 4 pour ajouter un
            // en-tête à CHAQUE requête — vérifié dans vendor/livewire/livewire/dist/livewire.esm.js :
            // le pont `registerLegacyEventSupport()` relie toujours ce hook historique au nouveau
            // système d'intercepteurs, et `options` y est le MÊME objet (muté, pas copié) que
            // celui passé ensuite à `fetch()`.
            if (context && typeof Livewire.hook === 'function') {
                Livewire.hook('request', function ({ options }) {
                    options.headers = options.headers || {};
                    options.headers['X-OpenLMNP-Context'] = context;
                });
            }

            // Réponse 419 : `interceptRequest()` (API Livewire 4) donne accès à la réponse
            // elle-même, donc à ses en-têtes ; `preventDefault()` y supprime la boîte native
            // (`error:` de `sendRequest()`, livewire.esm.js). Le hook historique `fail` ne
            // transmet que le statut et le corps : il ne sert que de repli.
            if (typeof Livewire.interceptRequest === 'function') {
                Livewire.interceptRequest(function ({ onError }) {
                    onError(function ({ response, preventDefault }) {
                        if (!response || response.status !== 419) {
                            return;
                        }
                        preventDefault();
                        showExpired(response.headers && response.headers.get(STALE_HEADER) === '1');
                    });
                });
            } else if (typeof Livewire.hook === 'function') {
                Livewire.hook('request', function ({ fail }) {
                    fail(function ({ status, content, preventDefault }) {
                        if (status !== 419) {
                            return;
                        }
                        preventDefault();
                        showExpired(typeof content === 'string' && content.indexOf('changé de dossier') !== -1);
                    });
                });
            }
        });
    })();
</script>
