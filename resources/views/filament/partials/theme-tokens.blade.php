{{--
    Jetons de couleur du panel OpenLMNP — SOURCE UNIQUE.

    Pourquoi ce fichier existe : les vues du panel stylaient leurs cartes avec
    `var(--fi-body-bg, white)`, `var(--fi-fg-muted, #6b7280)`… Or Filament 5 (Tailwind 4)
    n'expose AUCUNE variable `--fi-*` — `grep -c -- "--fi-" public/css/filament/filament/app.css`
    renvoie 0. Chaque `var()` retombait donc toujours sur son repli clair : carte blanche même
    en thème sombre, texte clair hérité de Filament par-dessus, donc blanc sur blanc
    (issue #5). Même mécanique d'échec silencieux que les classes `fi-*` inventées.

    Règles :
    - Aucune vue du panel ne code une couleur en dur. Elle utilise ces jetons.
      `PanelStylesheetTest` échoue sur tout littéral hexadécimal ailleurs qu'ici.
    - Les gris suivent Filament via `var(--gray-N)`, injectée à l'exécution par `->colors()`
      (palette `gray` par défaut = Zinc, cf. `ColorManager::$colors`). Le repli statique est
      la valeur Zinc correspondante — contrairement aux `--fi-*`, ce repli est légitime :
      la variable existe vraiment, le repli ne sert qu'aux contextes hors panel.
    - Les couleurs sémantiques sont en dur ici, et NULLE PART AILLEURS.
    - Le sélecteur sombre est `.dark` sur `<html>` (le CSS compilé porte
      `:root.dark{color-scheme:dark}` et `:where(.dark, .dark *)`).
--}}
<style>
    :root {
        /* Surfaces et texte — alignés sur Filament (carte claire = blanc, `--gray-50` en secondaire). */
        --olmnp-surface: #ffffff;
        --olmnp-surface-muted: var(--gray-50, #fafafa);
        --olmnp-surface-alt: var(--gray-100, #f4f4f5);
        --olmnp-border: var(--gray-200, #e4e4e7);
        --olmnp-border-strong: var(--gray-300, #d4d4d8);
        --olmnp-fg-strong: var(--gray-900, #18181b);
        --olmnp-fg: var(--gray-700, #3f3f46);
        --olmnp-fg-muted: var(--gray-500, #71717a);
        --olmnp-fg-subtle: var(--gray-400, #a1a1aa);

        /* Bloc préformaté : volontairement sombre dans les deux thèmes (sortie de console). */
        --olmnp-code-bg: #1f2937;
        --olmnp-code-fg: #e5e7eb;

        /*
            Six rôles par famille, parce qu'ils ne se comportent PAS pareil en thème sombre :
            - `bg` / `bg-strong` / `border` : encart coloré, texte `fg` par-dessus ;
            - `fg`   : texte posé sur `bg` — remonte vers le ton 300 en sombre ;
            - `accent` : texte ou icône coloré posé sur une surface NEUTRE — remonte aussi ;
            - `solid` / `solid-hover` : aplat de fond (bouton, badge plein) portant
              `--olmnp-on-solid` : il doit au contraire RESTER foncé en sombre, sinon le
              blanc par-dessus devient illisible.
        */
        --olmnp-on-solid: #ffffff;

        /* Succès / marque (émeraude). */
        --olmnp-success-bg: #ecfdf5;
        --olmnp-success-bg-strong: #d1fae5;
        --olmnp-success-border: #86efac;
        --olmnp-success-accent: #10b981;
        --olmnp-success-solid: #10b981;
        --olmnp-success-solid-hover: #059669;
        --olmnp-success-fg: #065f46;

        /* Avertissement (ambre). */
        --olmnp-warning-bg: #fffbeb;
        --olmnp-warning-bg-strong: #fef3c7;
        --olmnp-warning-border: #fcd34d;
        --olmnp-warning-accent: #f59e0b;
        --olmnp-warning-solid: #d97706;
        --olmnp-warning-solid-hover: #b45309;
        --olmnp-warning-fg: #92400e;

        /* Danger (rouge). */
        --olmnp-danger-bg: #fef2f2;
        --olmnp-danger-bg-strong: #fee2e2;
        --olmnp-danger-border: #fca5a5;
        --olmnp-danger-accent: #dc2626;
        --olmnp-danger-solid: #dc2626;
        --olmnp-danger-solid-hover: #b91c1c;
        --olmnp-danger-fg: #991b1b;

        /* Information (bleu). */
        --olmnp-info-bg: #eff6ff;
        --olmnp-info-bg-strong: #dbeafe;
        --olmnp-info-border: #93c5fd;
        --olmnp-info-accent: #2563eb;
        --olmnp-info-solid: #2563eb;
        --olmnp-info-solid-hover: #1d4ed8;
        --olmnp-info-fg: #1e40af;

        /* Accent secondaire (indigo/violet) — badges, jetons MCP. */
        --olmnp-accent-bg: #eef2ff;
        --olmnp-accent-border: #c7d2fe;
        --olmnp-accent-accent: #6366f1;
        --olmnp-accent-solid: #4f46e5;
        --olmnp-accent-solid-hover: #4338ca;
        --olmnp-accent-fg: #3730a3;

        /* Accent rose — badges de progression. */
        --olmnp-pink-bg: #fce7f3;
        --olmnp-pink-accent: #ec4899;
        --olmnp-pink-solid: #db2777;
        --olmnp-pink-fg: #9d174d;
    }

    /*
        Thème sombre. Les fonds colorés deviennent des teintes translucides posées sur la
        surface sombre (même parti pris que les règles `.dark` du wizard d'exercice, validées
        visuellement), et les textes colorés remontent vers les tons 300 pour rester lisibles.
    */
    :root.dark {
        --olmnp-surface: var(--gray-900, #18181b);
        --olmnp-surface-muted: var(--gray-800, #27272a);
        --olmnp-surface-alt: var(--gray-800, #27272a);
        --olmnp-border: var(--gray-700, #3f3f46);
        --olmnp-border-strong: var(--gray-600, #52525b);
        --olmnp-fg-strong: var(--gray-50, #fafafa);
        --olmnp-fg: var(--gray-200, #e4e4e7);
        --olmnp-fg-muted: var(--gray-400, #a1a1aa);
        --olmnp-fg-subtle: var(--gray-500, #71717a);

        --olmnp-success-bg: rgba(6, 95, 70, 0.3);
        --olmnp-success-bg-strong: rgba(6, 95, 70, 0.5);
        --olmnp-success-border: #065f46;
        --olmnp-success-accent: #34d399;
        --olmnp-success-solid: #059669;
        --olmnp-success-solid-hover: #047857;
        --olmnp-success-fg: #6ee7b7;

        --olmnp-warning-bg: rgba(120, 53, 15, 0.3);
        --olmnp-warning-bg-strong: rgba(120, 53, 15, 0.5);
        --olmnp-warning-border: #78350f;
        --olmnp-warning-accent: #fbbf24;
        --olmnp-warning-solid: #b45309;
        --olmnp-warning-solid-hover: #92400e;
        --olmnp-warning-fg: #fde68a;

        --olmnp-danger-bg: rgba(127, 29, 29, 0.3);
        --olmnp-danger-bg-strong: rgba(127, 29, 29, 0.5);
        --olmnp-danger-border: #7f1d1d;
        --olmnp-danger-accent: #f87171;
        --olmnp-danger-solid: #b91c1c;
        --olmnp-danger-solid-hover: #991b1b;
        --olmnp-danger-fg: #fca5a5;

        --olmnp-info-bg: rgba(30, 58, 138, 0.3);
        --olmnp-info-bg-strong: rgba(30, 58, 138, 0.5);
        --olmnp-info-border: #1e3a8a;
        --olmnp-info-accent: #60a5fa;
        --olmnp-info-solid: #1d4ed8;
        --olmnp-info-solid-hover: #1e40af;
        --olmnp-info-fg: #93c5fd;

        --olmnp-accent-bg: rgba(49, 46, 129, 0.3);
        --olmnp-accent-border: #312e81;
        --olmnp-accent-accent: #818cf8;
        --olmnp-accent-solid: #4338ca;
        --olmnp-accent-solid-hover: #3730a3;
        --olmnp-accent-fg: #a5b4fc;

        --olmnp-pink-bg: rgba(131, 24, 67, 0.3);
        --olmnp-pink-accent: #f472b6;
        --olmnp-pink-solid: #be185d;
        --olmnp-pink-fg: #f9a8d4;
    }
</style>
