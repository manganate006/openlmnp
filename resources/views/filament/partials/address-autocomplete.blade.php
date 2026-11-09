{{--
    Autocomplétion d'adresse — UN composant, pour tous les écrans qui portent une adresse.

    Branché par `AdminPanelProvider` sur `panels::body.end`, donc posé une fois pour toutes
    les pages du panel. Il n'y a RIEN à faire dans un formulaire pour en profiter, sinon
    marquer ses champs :

        <input data-address-group="property" data-address-role="address">
        <input data-address-group="property" data-address-role="postal_code">
        <input data-address-group="property" data-address-role="city">

    Le groupe relie les champs entre eux (plusieurs adresses peuvent cohabiter sur un même
    écran) ; le rôle dit lequel remplir. Côté Filament, cela se pose avec
    `->extraInputAttributes([...])` ; côté vue Blade, on écrit les attributs à la main.

    ⚠️ Un branchement par formulaire aurait été plus court à écrire, et se serait périmé
    écran par écran : trois copies à corriger au premier changement d'API. C'est le motif
    pour lequel ce fichier existe.

    ⚠️ AUCUNE dépendance npm, et aucune classe CSS : les suggestions passent par un
    `<datalist>`, que le navigateur dessine lui-même. Le dépôt a déjà perdu trois jours sur
    la disparition d'un `node_modules/` ; et une liste déroulante maison serait à styler
    deux fois (thème clair et thème sombre) pour un résultat moins accessible.

    ⚠️ La saisie manuelle reste possible de bout en bout. Un `<datalist>` ne contraint rien,
    et toute panne du service — réseau coupé, délai dépassé, erreur HTTP — se solde par
    l'absence de suggestions, jamais par un champ bloqué ou un formulaire invalidable.
--}}
@if(config('opendata.address.enabled'))
<script>
    (function () {
        if (window.olmnpAddressAutocomplete) {
            return;
        }

        var ENDPOINT = @json(route('address.search'));
        var MIN_CHARS = @json((int) config('opendata.address.min_length'));
        var DEBOUNCE_MS = @json((int) config('opendata.address.debounce_ms'));

        var suggestionsByInput = new WeakMap();
        var timers = new WeakMap();
        var filling = new WeakSet();
        var listCounter = 0;

        /** Champ frère portant ce rôle dans le même groupe. */
        function companion(input, role) {
            var group = input.getAttribute('data-address-group');

            if (! group) {
                return null;
            }

            return document.querySelector(
                '[data-address-group="' + group + '"][data-address-role="' + role + '"]'
            );
        }

        /**
         * Écrit une valeur dans un champ ET le dit à Livewire.
         *
         * ⚠️ Poser `element.value` ne suffit pas : Livewire ne lit pas le DOM au moment
         * d'envoyer, il tient son propre état, mis à jour sur l'évènement `input`. Sans
         * ces deux évènements, le champ paraîtrait rempli à l'écran et repartirait vide
         * à l'enregistrement — exactement le genre de panne qu'on ne voit pas.
         */
        function fill(element, value) {
            if (! element || value === undefined || value === null || value === '') {
                return;
            }

            filling.add(element);
            element.value = value;
            element.dispatchEvent(new Event('input', { bubbles: true }));
            element.dispatchEvent(new Event('change', { bubbles: true }));
            filling.delete(element);
        }

        /** `<datalist>` attaché à ce champ, créé au premier besoin. */
        function listOf(input) {
            var id = input.getAttribute('list');
            var list = id ? document.getElementById(id) : null;

            if (list) {
                return list;
            }

            id = 'olmnp-address-list-' + (++listCounter);
            list = document.createElement('datalist');
            list.id = id;
            input.setAttribute('list', id);
            input.setAttribute('autocomplete', 'off');
            input.parentNode.appendChild(list);

            return list;
        }

        function render(input, results) {
            var list = listOf(input);
            list.textContent = '';

            results.forEach(function (result) {
                var option = document.createElement('option');
                option.value = result.label;
                list.appendChild(option);
            });
        }

        function apply(input, suggestion) {
            fill(input, suggestion.address);
            fill(companion(input, 'postal_code'), suggestion.postal_code);
            fill(companion(input, 'city'), suggestion.city);
        }

        function search(input, value) {
            fetch(ENDPOINT + '?q=' + encodeURIComponent(value), {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
            }).then(function (response) {
                // Service muet, en erreur, ou coupé sur cette instance : pas de
                // suggestions, et rien d'autre ne change. La saisie manuelle continue.
                return response.ok ? response.json() : { results: [] };
            }).then(function (payload) {
                var results = (payload && Array.isArray(payload.results)) ? payload.results : [];
                suggestionsByInput.set(input, results);
                render(input, results);
            }).catch(function () {
                // Réseau coupé : on ne casse rien, on ne dit rien.
            });
        }

        document.addEventListener('input', function (event) {
            var input = event.target;

            if (! (input instanceof HTMLInputElement)) {
                return;
            }

            if (input.getAttribute('data-address-role') !== 'address') {
                return;
            }

            // Ré-entrée : c'est nous qui venons d'écrire dans ce champ.
            if (filling.has(input)) {
                return;
            }

            var value = input.value.trim();
            var known = suggestionsByInput.get(input) || [];

            for (var i = 0; i < known.length; i++) {
                if (known[i].label === value) {
                    apply(input, known[i]);

                    return;
                }
            }

            clearTimeout(timers.get(input));

            if (value.length < MIN_CHARS) {
                return;
            }

            // Anti-rebond : on n'interroge pas un service public gratuit à chaque touche.
            timers.set(input, setTimeout(function () {
                search(input, value);
            }, DEBOUNCE_MS));
        });

        window.olmnpAddressAutocomplete = true;
    })();
</script>
@endif
