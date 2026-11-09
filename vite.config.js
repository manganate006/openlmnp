import { defineConfig } from 'vite';
import { fileURLToPath } from 'node:url';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    resolve: {
        alias: {
            // ⚠️ Contournement d'un défaut d'empaquetage de libsodium-wrappers
            // 0.7.x : son entrée ESM (`dist/modules-esm/libsodium-wrappers.mjs`)
            // fait `import './libsodium.mjs'`, or ce fichier n'est PAS dans son
            // dossier — il vit dans le paquet `libsodium`. Le build échoue donc
            // sur « Module not found » avec la configuration par défaut.
            // On pointe l'entrée CommonJS, que Vite sait convertir.
            // Chemin de FICHIER et non spécifiant de paquet : le champ `exports`
            // de libsodium-wrappers interdit les imports profonds, et refuserait
            // « libsodium-wrappers/dist/... ».
            'libsodium-wrappers': fileURLToPath(
                new URL('./node_modules/libsodium-wrappers/dist/modules/libsodium-wrappers.js', import.meta.url),
            ),
        },
    },
    build: {
        rollupOptions: {
            // Le coffre-fort est servi au panel par `FilamentAsset`, pas par le
            // helper `@vite` : le panel Filament n'a jamais chargé les assets Vite,
            // et `->viteTheme()` en avait été retiré parce qu'il cassait le CSS
            // global. On produit donc un fichier à NOM FIXE, hors du manifeste
            // haché, que Filament copie tel quel dans public/js.
            // ⚠️ Cette liste ÉCRASE celle du plugin Laravel : oublier la feuille
            // CSS l'a fait disparaître du manifeste au premier essai. Toute entrée
            // déclarée plus haut dans `laravel({ input: [...] })` doit être reprise
            // ici, sans quoi elle cesse silencieusement d'être construite.
            input: {
                'app-css': 'resources/css/app.css',
                app: 'resources/js/app.js',
                vault: 'resources/js/vault.js',
            },
            output: {
                entryFileNames: (chunk) =>
                    chunk.name === 'vault' ? 'assets/vault.js' : 'assets/[name]-[hash].js',
            },
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
