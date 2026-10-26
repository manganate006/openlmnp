<?php

namespace App\Services\Ai\Assistant;

use App\Support\HelpContentRegistry;
use Illuminate\Support\Facades\Route;

/**
 * Table « page → adresse » fournie au modèle : c'est la SEULE source de liens qu'il a le
 * droit de produire. Elle sert aussi de liste blanche au nettoyage du Markdown rendu,
 * pour qu'aucun lien inventé (ou injecté) n'atteigne l'utilisateur.
 */
final class RouteLinks
{
    private const LINKED_DOSSIERS_ROUTE = 'filament.admin.pages.linked-dossiers';

    /** @var array<string, string>|null */
    private ?array $table = null;

    /**
     * Adresses internes autorisées, indexées par libellé lisible.
     *
     * @return array<string, string>
     */
    public function table(): array
    {
        if ($this->table !== null) {
            return $this->table;
        }

        $table = [];

        foreach (HelpContentRegistry::all() as $routeName => $entry) {
            // La route des dossiers liés existe toujours, mais la page reste fermée tant que
            // la fonctionnalité est désactivée : ne pas y envoyer l'utilisateur. Son absence
            // de la table est aussi ce qui dit au modèle que la fonctionnalité est coupée.
            if ($routeName === self::LINKED_DOSSIERS_ROUTE && ! config('dossiers.enabled')) {
                continue;
            }

            $url = $this->url($routeName);

            if ($url !== null) {
                $table[$entry['title']] = $url;
            }
        }

        return $this->table = $table;
    }

    /**
     * @return array<int, string>
     */
    public function allowedUrls(): array
    {
        return array_values(array_unique(array_values($this->table())));
    }

    public function isAllowed(string $url): bool
    {
        return $this->internalUrl($url) !== null;
    }

    /**
     * L'adresse interne correspondante si le lien est autorisé, sinon null. Un lien
     * donné en absolu par le modèle est ramené à son chemin.
     */
    public function internalUrl(string $url): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        $path = $this->normalize($url);

        return in_array($path, $this->allowedUrls(), true) ? $path : null;
    }

    /**
     * L'URL d'une page connue, par son libellé (pour les messages de refus).
     */
    public function urlFor(string $title): ?string
    {
        return $this->table()[$title] ?? null;
    }

    /**
     * La table telle qu'elle est insérée dans le prompt système.
     */
    public function forPrompt(): string
    {
        $lines = [];

        foreach ($this->table() as $title => $url) {
            $lines[] = "- {$title} : {$url}";
        }

        return implode("\n", $lines);
    }

    private function url(string $routeName): ?string
    {
        foreach ([$routeName, $routeName.'.index'] as $candidate) {
            if (Route::has($candidate)) {
                try {
                    return $this->normalize(route($candidate, absolute: false));
                } catch (\Throwable) {
                    return null;
                }
            }
        }

        return null;
    }

    private function normalize(string $url): string
    {
        // Les liens du modèle peuvent arriver en absolu : on compare des chemins.
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return $url;
        }

        return rtrim($path, '/') ?: '/';
    }
}
