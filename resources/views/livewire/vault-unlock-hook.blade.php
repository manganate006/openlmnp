@php
    $vaultActif = \App\Support\Vault::isEnabledFor(auth()->user());
@endphp

@if ($vaultActif)
    {{-- Déverrouillage du coffre-fort, dans le navigateur.

         ⚠️ Le module pèse ~730 Ko (libsodium en WebAssembly) : il n'est PAS chargé
         avec la page. Un `import()` dynamique ne le fait venir qu'au premier clic
         sur un cadenas — la très grande majorité des pages ne le téléchargent
         jamais. C'est le seul JS du panel qui ne soit pas de l'Alpine en ligne. --}}
    <div
        x-data="{
            ouvert: false,
            phrase: '',
            erreur: '',
            occupe: false,
            progres: '',
            module: null,

            async charger() {
                if (this.module) return this.module;
                // ⚠️ Le module s'expose sur `window.OpenlmnpVault` : Vite retire les
                // `export` d'un point d'entrée d'application, si bien que l'objet
                // rendu par `import()` est VIDE. Le lire donnait « v.estDeverrouille
                // is not a function » à chaque ouverture de justificatif scellé.
                await import(@js(asset('build/assets/vault.js')));
                this.module = window.OpenlmnpVault ?? null;
                if (! this.module) throw new Error('Le module du coffre-fort ne s\'est pas chargé.');
                return this.module;
            },

            /**
             * Attend que l'utilisateur ait saisi sa phrase (ou abandonné), si le
             * coffre n'est pas déjà déverrouillé. Factorisé pour `ouvrir()`,
             * `telecharger()` et `archiver()` : une seule saisie couvre la visite
             * entière, quel que soit le document ouvert ensuite.
             */
            async attendreDeverrouillage(v) {
                if (v.estDeverrouille()) {
                    return;
                }

                this.ouvert = true;

                await new Promise((ok, ko) => {
                    const fini = () => { nettoie(); ok(); };
                    const annule = () => { nettoie(); ko(new Error('annulé')); };
                    const nettoie = () => {
                        window.removeEventListener('coffre-deverrouille', fini);
                        window.removeEventListener('coffre-abandonne', annule);
                    };
                    window.addEventListener('coffre-deverrouille', fini, { once: true });
                    window.addEventListener('coffre-abandonne', annule, { once: true });
                });
            },

            /**
             * Ouvre un justificatif chiffré : va chercher le chiffré, le déchiffre
             * ICI, et l'affiche. Demande la phrase de passe si besoin, une fois.
             */
            async ouvrir(url, nom) {
                try {
                    const v = await this.charger();
                    await this.attendreDeverrouillage(v);

                    const brut = await v.dechiffrer(await v.chiffre(url));
                    // Typé d'après l'extension, comme le fait le serveur : un blob sans
                    // type part en téléchargement au lieu de s'afficher dans l'onglet.
                    const types = { pdf: 'application/pdf', jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', webp: 'image/webp', gif: 'image/gif' };
                    const type = types[(nom.split('.').pop() || '').toLowerCase()];
                    const blob = type ? new Blob([brut], { type }) : brut;
                    const objet = URL.createObjectURL(blob);
                    window.open(objet, '_blank');
                    // Révoqué tard : trop tôt, l'onglet reçoit une URL déjà morte.
                    setTimeout(() => URL.revokeObjectURL(objet), 60000);
                } catch (e) {
                    if (e.message !== 'annulé') {
                        this.erreur = e.message;
                        this.ouvert = true;
                    }
                }
            },

            /**
             * Télécharge un justificatif chiffré : même déchiffrement que `ouvrir()`,
             * mais enregistré sous son nom plutôt qu'ouvert dans un onglet — c'est ce
             * que l'icône « télécharger » de FilePond attend.
             */
            async telecharger(url, nom) {
                try {
                    const v = await this.charger();
                    await this.attendreDeverrouillage(v);

                    const blob = await v.dechiffrer(await v.chiffre(url));
                    const a = document.createElement('a');
                    a.href = URL.createObjectURL(blob);
                    a.download = nom || 'document';
                    a.click();
                    setTimeout(() => URL.revokeObjectURL(a.href), 60000);
                } catch (e) {
                    if (e.message !== 'annulé') {
                        this.erreur = e.message;
                        this.ouvert = true;
                    }
                }
            },

            /** Assemble l'archive ici : le serveur n'a plus les clés pour le faire. */
            async archiver(entrees) {
                try {
                    const v = await this.charger();
                    await this.attendreDeverrouillage(v);

                    this.progres = `0 / ${entrees.length}`;

                    const blob = await v.archiver(entrees, (fait, total) => {
                        this.progres = `${fait} / ${total}`;
                    });

                    const a = document.createElement('a');
                    a.href = URL.createObjectURL(blob);
                    a.download = `justificatifs-${new Date().toISOString().slice(0, 10)}.zip`;
                    a.click();
                    setTimeout(() => URL.revokeObjectURL(a.href), 60000);
                    this.progres = '';
                } catch (e) {
                    this.progres = '';

                    if (e.message !== 'annulé') {
                        this.erreur = e.message;
                        this.ouvert = true;
                    }
                }
            },

            async deverrouiller() {
                this.erreur = '';
                this.occupe = true;

                try {
                    const v = await this.charger();
                    await v.deverrouiller(this.phrase, await v.materiel());
                    this.phrase = '';
                    this.ouvert = false;
                    window.dispatchEvent(new CustomEvent('coffre-deverrouille'));
                } catch (e) {
                    // Un refus explicite, jamais un repli silencieux vers le
                    // serveur : ce serait envoyer la phrase de passe sans le dire.
                    this.erreur = e.message;
                } finally {
                    this.occupe = false;
                }
            },

            /**
             * Déclencheur : intercepte le clic sur les icônes « ouvrir »/« télécharger »
             * que FilePond pose lui-même sur un justificatif (`insertOpenLink()`/
             * `insertDownloadLink()` de `file-upload.js`). SANS lui, ce clic tombe
             * sur la navigation par défaut de l'ancre — c'est le bug
             * `/expenses/1/[object Object]` : Filament n'a pas de clair à servir à
             * cette URL, et rien ne l'empêchait d'essayer.
             *
             * Le marqueur `#vault=…` posé par `DocumentStorage::vaultAwareFileUrl()`
             * distingue un justificatif CHIFFRÉ (à faire prendre en charge ici) d'un
             * lien ordinaire (laissé tel quel, y compris sur un compte au coffre
             * actif dont ce fichier précis a été déposé avant l'activation).
             */
            clicJustificatif(e) {
                const lien = e.target.closest('a.filepond--open-icon, a.filepond--download-icon');

                if (! lien) {
                    return;
                }

                const morceaux = lien.href.split('#vault=');

                if (morceaux.length < 2) {
                    return;
                }

                e.preventDefault();

                const url = morceaux[0];
                const nom = decodeURIComponent(morceaux[1]);
                const evenement = lien.classList.contains('filepond--download-icon')
                    ? 'coffre-telecharger'
                    : 'coffre-ouvrir';

                // Émis plutôt qu'un appel direct à `ouvrir()`/`telecharger()` : c'est le
                // même contrat d'événement que `coffre-archiver`, utilisable depuis
                // n'importe quel composant, pas seulement celui-ci.
                window.dispatchEvent(new CustomEvent(evenement, { detail: { url, nom } }));
            },
        }"
        x-on:click.window="clicJustificatif($event)"
        x-on:coffre-demande-deverrouillage.window="ouvert = true"
        x-on:coffre-ouvrir.window="ouvrir($event.detail.url, $event.detail.nom)"
        x-on:coffre-telecharger.window="telecharger($event.detail.url, $event.detail.nom)"
        x-on:coffre-archiver.window="archiver($event.detail.entrees)"
        x-on:keydown.escape.window="ouvert = false"
    >
        <template x-if="ouvert">
            <div class="vault-modal-fond" x-on:click.self="ouvert = false">
                <div class="vault-modal">
                    <h2>Déverrouiller le coffre-fort</h2>
                    <p>
                        Votre phrase de passe ne quitte pas cet appareil : le déchiffrement se fait
                        ici, dans votre navigateur.
                    </p>

                    <input
                        type="password"
                        x-model="phrase"
                        x-on:keydown.enter="deverrouiller()"
                        placeholder="Phrase de passe"
                        autocomplete="off"
                        x-ref="champ"
                        x-init="$nextTick(() => $refs.champ?.focus())"
                    >

                    <template x-if="erreur">
                        <p class="vault-modal-erreur" x-text="erreur"></p>
                    </template>

                    <div class="vault-modal-actions">
                        <button type="button" x-on:click="ouvert = false; window.dispatchEvent(new CustomEvent('coffre-abandonne'))" :disabled="occupe">Annuler</button>
                        <button type="button" class="vault-modal-primaire" x-on:click="deverrouiller()" :disabled="occupe || ! phrase">
                            <span x-show="! occupe">Déverrouiller</span>
                            <span x-show="occupe">Déchiffrement…</span>
                        </button>
                    </div>
                </div>
            </div>
        </template>
        <template x-if="progres">
            <div class="vault-progres">
                Déchiffrement des justificatifs… <strong x-text="progres"></strong>
            </div>
        </template>
    </div>

    <style>
        .vault-progres { position: fixed; bottom: 16px; left: 16px; z-index: 55; padding: 10px 14px;
            border-radius: 8px; font-size: 13px; background: var(--olmnp-surface);
            border: 1px solid var(--olmnp-border); color: var(--olmnp-fg);
            box-shadow: 0 4px 16px rgba(0,0,0,.15); }
        .vault-modal-fond { position: fixed; inset: 0; z-index: 60; display: flex; align-items: center;
            justify-content: center; padding: 16px; background: rgba(0,0,0,.45); }
        .vault-modal { width: 100%; max-width: 420px; border-radius: 12px; padding: 24px;
            background: var(--olmnp-surface); border: 1px solid var(--olmnp-border);
            box-shadow: 0 10px 40px rgba(0,0,0,.25); }
        .vault-modal h2 { font-size: 17px; font-weight: 600; margin-bottom: 8px; color: var(--olmnp-fg); }
        .vault-modal p { font-size: 13px; color: var(--olmnp-fg-muted); margin-bottom: 16px; line-height: 1.5; }
        .vault-modal input { width: 100%; padding: 9px 12px; border-radius: 8px; font-size: 14px;
            border: 1px solid var(--olmnp-border-strong); background: var(--olmnp-surface-alt); color: var(--olmnp-fg); }
        .vault-modal-erreur { margin: 12px 0 0; font-size: 13px; color: var(--olmnp-danger-fg); }
        .vault-modal-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 20px; }
        .vault-modal-actions button { padding: 8px 14px; border-radius: 8px; font-size: 14px; font-weight: 500;
            border: 1px solid var(--olmnp-border-strong); background: var(--olmnp-surface-alt); color: var(--olmnp-fg); cursor: pointer; }
        .vault-modal-primaire { background: var(--olmnp-accent-solid); border-color: var(--olmnp-accent-solid); color: var(--olmnp-on-solid); }
        .vault-modal-actions button:disabled { opacity: .55; cursor: default; }
    </style>
@endif
