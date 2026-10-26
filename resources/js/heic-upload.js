/*
 * Conversion HEIC → JPEG dans le navigateur, AVANT téléversement.
 *
 * Les modèles de vision ne lisent pas le HEIC (photos iPhone) et le serveur ne
 * l'ouvre volontairement pas : décoder du HEVC côté serveur sur des fichiers
 * fournis par des inconnus ouvrirait une surface d'attaque (libheif/libde265).
 * La conversion se fait donc dans l'onglet de l'utilisateur, en WebAssembly,
 * avec la bibliothèque heic2any chargée À LA DEMANDE — aucun octet n'est
 * téléchargé tant qu'aucun fichier HEIC n'est déposé.
 *
 * Accroche : chaque instance FilePond du panel (champs « Téléverser » de
 * Filament) reçoit un `beforeAddFile` ; un HEIC est remplacé par son JPEG, le
 * fichier d'origine n'est jamais envoyé. Sans JavaScript ou en cas d'échec, le
 * serveur garde son message d'aide (DocumentFile::unsupportedMessage).
 */
(() => {
    const HEIC_EXTENSION = /\.(heic|heif)$/i;
    const HEIC_MIMES = ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence'];
    const MAX_ATTACH_ATTEMPTS = 20;

    const isHeic = (file) => Boolean(file)
        && (HEIC_MIMES.includes(String(file.type || '').toLowerCase()) || HEIC_EXTENSION.test(file.name || ''));

    let loader = null;

    /** Charge heic2any une seule fois, et seulement quand c'est nécessaire. */
    const loadHeic2any = () => {
        if (window.heic2any) {
            return Promise.resolve(window.heic2any);
        }

        loader ??= new Promise((resolve, reject) => {
            const src = window.filamentData?.heic2anySrc;

            if (!src) {
                reject(new Error('heic2any : adresse du script absente'));

                return;
            }

            const script = document.createElement('script');
            script.src = src;
            script.async = true;
            script.onload = () => (window.heic2any ? resolve(window.heic2any) : reject(new Error('heic2any : chargement incomplet')));
            script.onerror = () => {
                loader = null;
                reject(new Error('heic2any : échec du chargement'));
            };
            document.head.appendChild(script);
        });

        return loader;
    };

    const toJpeg = async (file) => {
        const heic2any = await loadHeic2any();
        const result = await heic2any({ blob: file, toType: 'image/jpeg', quality: 0.9 });
        const blob = Array.isArray(result) ? result[0] : result;
        const name = `${(file.name || 'photo').replace(HEIC_EXTENSION, '')}.jpg`;

        return new File([blob], name, { type: 'image/jpeg', lastModified: Date.now() });
    };

    const notify = (title, body, status) => {
        try {
            new window.FilamentNotification().title(title).body(body).status(status).send();
        } catch (error) {
            console.warn(`${title} — ${body}`);
        }
    };

    /** Installe la conversion sur une instance FilePond (une seule fois). */
    const hook = (pond) => {
        if (!pond || pond.__heicHooked) {
            return;
        }

        pond.__heicHooked = true;

        const previous = typeof pond.beforeAddFile === 'function' ? pond.beforeAddFile : null;

        pond.setOptions({
            beforeAddFile: async (item) => {
                const file = item.file;

                if (!isHeic(file) || item.getMetadata('heicConverted')) {
                    return previous ? previous(item) : true;
                }

                try {
                    notify('Photo HEIC détectée', 'Conversion en JPEG dans votre navigateur…', 'info');
                    const jpeg = await toJpeg(file);
                    pond.addFile(jpeg, { metadata: { heicConverted: true } });
                } catch (error) {
                    console.warn('Conversion HEIC impossible :', error);
                    notify(
                        'Conversion HEIC impossible',
                        'Convertissez la photo en JPEG (Photos → Exporter, ou « Réglages → Appareil photo → Formats → Le plus compatible » sur iPhone), puis déposez-la à nouveau.',
                        'danger',
                    );
                }

                // Le HEIC d'origine n'est jamais téléversé.
                return false;
            },
        });
    };

    /** Retrouve l'instance FilePond de Filament depuis la racine rendue. */
    const attach = (root, attempt = 0) => {
        const scope = root.closest('[x-data]');
        let data = null;

        try {
            data = scope && window.Alpine ? window.Alpine.$data(scope) : null;
        } catch (error) {
            data = null;
        }

        if (data?.pond) {
            hook(data.pond);

            return;
        }

        if (attempt < MAX_ATTACH_ATTEMPTS) {
            setTimeout(() => attach(root, attempt + 1), 250);
        }
    };

    const scan = (node) => {
        if (!node?.querySelectorAll) {
            return;
        }

        if (node.matches?.('.filepond--root')) {
            attach(node);
        }

        node.querySelectorAll('.filepond--root').forEach((root) => attach(root));
    };

    const start = () => {
        scan(document.body);

        new MutationObserver((mutations) => {
            mutations.forEach((mutation) => mutation.addedNodes.forEach((node) => {
                if (node.nodeType === Node.ELEMENT_NODE) {
                    scan(node);
                }
            }));
        }).observe(document.body, { childList: true, subtree: true });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
