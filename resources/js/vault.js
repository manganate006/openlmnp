/**
 * Coffre-fort : déchiffrement DANS LE NAVIGATEUR.
 *
 * La phrase de passe ne quitte jamais ce poste. Le serveur ne fournit que du
 * matériel scellé — paramètres de dérivation, clé publique, clé privée scellée —
 * et du chiffré brut. Tout le reste se passe ici.
 *
 * ⚠️ Ce module ne doit JAMAIS renvoyer quoi que ce soit au serveur. Ni la phrase
 * de passe, ni une clé dérivée, ni un fragment de clair. C'est la seule règle qui
 * fasse la différence entre « chiffré au repos » et « chiffré de bout en bout ».
 */
// ⚠️ La variante SUMO, pas `libsodium-wrappers` : la version standard n'embarque pas
// `crypto_pwhash` (Argon2id). Avec elle, aucun déverrouillage n'aboutissait, et le
// `TypeError` était présenté comme un manque de mémoire (constaté le 2026-10-16).
import sodium from 'libsodium-wrappers-sumo';
import { zip } from 'fflate';

/** Doit rester en phase avec App\Services\Vault\DocumentCipher. */
const MAGIC = 'OLMNPENC';
const HEADER_BYTES = 46;
const FORMAT_SEALED = 2;

/** Mémoire de page uniquement : jamais de localStorage, jamais de cookie. */
let paire = null;

const b64 = (s) => sodium.from_base64(s, sodium.base64_variants.ORIGINAL);

export const estDeverrouille = () => paire !== null;

export function verrouiller() {
    if (paire) {
        // Écrase la clé avant de lâcher la référence : un ramasse-miettes ne
        // garantit rien sur le moment où la mémoire est réellement rendue.
        sodium.memzero(paire.privateKey);
        paire = null;
    }
}

/**
 * Dérive la clé à partir de la phrase de passe et descelle la clé privée.
 *
 * @throws {Error} `MEMOIRE` si l'appareil ne peut pas allouer ce que le profil
 *                 demande — un refus explicite, jamais un repli silencieux vers
 *                 le serveur : ce serait annuler la promesse sans le dire.
 * @throws {Error} `PHRASE` si la phrase de passe est fausse.
 */
export async function deverrouiller(phrase, materiel) {
    await sodium.ready;

    const sel = b64(materiel.kdf.salt);
    let kek;

    try {
        kek = sodium.crypto_pwhash(
            sodium.crypto_secretbox_KEYBYTES,
            phrase,
            sel,
            materiel.kdf.ops,
            materiel.kdf.mem,
            materiel.kdf.alg,
        );
    } catch (e) {
        // Seul un échec d'allocation devient « mémoire ». Tout le reste remonte
        // avec sa vraie cause : déguiser une fonction absente en manque de mémoire
        // a caché pendant des semaines que le déverrouillage ne marchait nulle part.
        if (!(e instanceof RangeError) && !/memory|alloc|enlarge|OOM/i.test(String(e?.message ?? e))) {
            const err = new Error(`Déverrouillage impossible : ${e?.message ?? e}`);
            err.code = 'INTERNE';
            throw err;
        }

        // WASM ne peut pas allouer le bloc demandé : le dire, et laisser
        // l'utilisateur décider. Un basculement automatique vers le serveur
        // enverrait sa phrase de passe sans le lui demander.
        const err = new Error(
            `Ce navigateur n'a pas pu réserver les ${Math.round(materiel.kdf.mem / 1048576)} Mo `
            + `nécessaires au déverrouillage. Essayez depuis un ordinateur, ou fermez des onglets.`,
        );
        err.code = 'MEMOIRE';
        throw err;
    }

    let privee;

    try {
        privee = sodium.crypto_secretbox_open_easy(
            b64(materiel.wrapped_secret_key),
            b64(materiel.secret_key_nonce),
            kek,
        );
    } catch (e) {
        privee = null;
    } finally {
        sodium.memzero(kek);
    }

    if (!privee) {
        const err = new Error('Phrase de passe incorrecte.');
        err.code = 'PHRASE';
        throw err;
    }

    paire = { privateKey: privee, publicKey: b64(materiel.public_key) };

    return true;
}

/**
 * Déchiffre un fichier du coffre. Rend un Blob prêt à afficher ou télécharger.
 *
 * Le format est celui de `DocumentCipher` : en-tête auto-descriptif, clé du
 * fichier scellée à la clé publique, puis des blocs `secretstream` authentifiés.
 */
export async function dechiffrer(octets) {
    await sodium.ready;

    if (!paire) {
        const err = new Error('Coffre-fort verrouillé.');
        err.code = 'VERROUILLE';
        throw err;
    }

    const vue = new Uint8Array(octets);

    if (sodium.to_string(vue.slice(0, MAGIC.length)) !== MAGIC) {
        // Fichier en clair : le coffre n'était pas actif quand il a été déposé.
        return new Blob([vue]);
    }

    const format = vue[8];

    if (format !== FORMAT_SEALED) {
        // Format 1 : la clé est la DEK, qui ne voyage pas dans le fichier — donc
        // illisible ici. C'est un fichier restant à migrer, pas une corruption.
        const err = new Error(
            'Ce justificatif date d\'avant la migration du coffre-fort : terminez-la '
            + 'depuis la page « Coffre-fort » pour pouvoir l\'ouvrir.',
        );
        err.code = 'A_MIGRER';
        throw err;
    }

    const tailleBloc = new DataView(vue.buffer, vue.byteOffset + 10, 4).getUint32(0, false);
    const enTeteFlux = vue.slice(22, 22 + sodium.crypto_secretstream_xchacha20poly1305_HEADERBYTES);
    const scellee = vue.slice(HEADER_BYTES, HEADER_BYTES + sodium.crypto_box_SEALBYTES + 32);

    let cle;

    try {
        cle = sodium.crypto_box_seal_open(scellee, paire.publicKey, paire.privateKey);
    } catch (e) {
        cle = null;
    }

    if (!cle) {
        const err = new Error('Ce justificatif n\'a pas pu être ouvert : fichier altéré ou clé étrangère.');
        err.code = 'ALTERE';
        throw err;
    }

    const etat = sodium.crypto_secretstream_xchacha20poly1305_init_pull(enTeteFlux, cle);
    const morceaux = [];
    let position = HEADER_BYTES + scellee.length;
    const tailleChiffree = tailleBloc + sodium.crypto_secretstream_xchacha20poly1305_ABYTES;

    while (position < vue.length) {
        const bloc = vue.slice(position, position + tailleChiffree);
        const res = sodium.crypto_secretstream_xchacha20poly1305_pull(etat, bloc);

        if (!res) {
            // Un bloc qui ne s'authentifie pas : altération ou troncature. On
            // refuse tout le fichier plutôt que de rendre un début plausible.
            const err = new Error('Ce justificatif est altéré : son contenu n\'a pas pu être authentifié.');
            err.code = 'ALTERE';
            throw err;
        }

        morceaux.push(res.message);
        position += tailleChiffree;
    }

    sodium.memzero(cle);

    return new Blob(morceaux);
}

/**
 * Assemble l'archive des justificatifs ICI, dans le navigateur.
 *
 * ⚠️ Ce n'était pas un choix d'ergonomie mais une contrainte : une archive lisible
 * contient du clair, donc quelqu'un doit détenir du clair pour l'assembler. Si
 * c'était le serveur, il lui faudrait les clés — et la promesse tomberait pour
 * TOUS les fichiers, pas seulement ceux de l'export.
 *
 * @param {Array<{url: string, chemin: string}>} entrees
 * @param {(fait: number, total: number) => void} progres
 */
export async function archiver(entrees, progres) {
    await sodium.ready;

    const contenus = {};
    let fait = 0;

    for (const e of entrees) {
        try {
            const blob = await dechiffrer(await chiffre(e.url));
            contenus[e.chemin] = new Uint8Array(await blob.arrayBuffer());
        } catch (err) {
            // Un justificatif illisible ne doit pas faire échouer toute l'archive,
            // mais il ne doit pas non plus disparaître en silence : on écrit la
            // raison à sa place. Une archive incomplète qui ne le dit pas est le
            // pire cas pour un export d'archivage.
            contenus[e.chemin + '.ILLISIBLE.txt'] = new TextEncoder()
                .encode(`Ce justificatif n'a pas pu être déchiffré.\n\nRaison : ${err.message}\n`);
        }

        progres?.(++fait, entrees.length);
    }

    return new Promise((ok, ko) => {
        // `zip` de fflate écrit de façon incrémentale : on ne tient jamais
        // l'archive entière ET tous les fichiers en mémoire simultanément.
        zip(contenus, { level: 0 }, (err, donnees) => {
            err ? ko(err) : ok(new Blob([donnees], { type: 'application/zip' }));
        });
    });
}

/** Le matériel de clé, demandé une seule fois par page. */
export async function materiel() {
    const r = await fetch('/vault/key-material', { headers: { Accept: 'application/json' } });

    if (!r.ok) {
        const corps = await r.json().catch(() => ({}));
        const err = new Error(corps.message ?? 'Coffre-fort indisponible.');
        err.code = r.status === 409 ? 'HISTORIQUE' : 'INDISPONIBLE';
        throw err;
    }

    return r.json();
}

/** Récupère le chiffré brut d'un justificatif, sans que le serveur le déchiffre. */
export async function chiffre(url) {
    const u = new URL(url, window.location.origin);
    u.searchParams.set('raw', '1');

    const r = await fetch(u, { headers: { Accept: 'application/octet-stream' } });

    if (!r.ok) {
        throw new Error(`Justificatif introuvable (${r.status}).`);
    }

    return r.arrayBuffer();
}

// Exposé à Alpine, qui pilote les écrans du panel — c'est le seul JS du panel.
window.OpenlmnpVault = {
    estDeverrouille, deverrouiller, verrouiller, dechiffrer, materiel, chiffre, archiver,
};
