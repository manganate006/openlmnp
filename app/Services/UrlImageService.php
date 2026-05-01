<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Récupère automatiquement l'image Open Graph (og:image)
 * depuis une URL d'annonce (Airbnb, Booking, Abritel, etc.)
 */
class UrlImageService
{
    /**
     * Tente de récupérer l'image OG d'une URL et la sauvegarde.
     *
     * @return string|null Chemin du fichier sauvegardé ou null si échec
     */
    public function fetchAndSave(string $url, string $directory = 'properties'): ?string
    {
        $ogImageUrl = $this->extractOgImage($url);

        if (! $ogImageUrl) {
            return null;
        }

        return $this->downloadImage($ogImageUrl, $directory);
    }

    /**
     * Extrait l'URL de l'image og:image depuis une page web.
     */
    public function extractOgImage(string $url): ?string
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml',
                    'Accept-Language' => 'fr-FR,fr;q=0.9',
                ])
                ->get($url);

            if (! $response->successful()) {
                return null;
            }

            $html = $response->body();

            // Chercher og:image
            if (preg_match('/property=["\']og:image["\'].*?content=["\']([^"\']+)["\']/', $html, $matches)) {
                return html_entity_decode($matches[1]);
            }

            // Format inversé (content avant property)
            if (preg_match('/content=["\']([^"\']+)["\'].*?property=["\']og:image["\']/', $html, $matches)) {
                return html_entity_decode($matches[1]);
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Télécharge une image et la sauvegarde dans le storage.
     */
    private function downloadImage(string $imageUrl, string $directory): ?string
    {
        try {
            $response = Http::timeout(15)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
                ->get($imageUrl);

            if (! $response->successful()) {
                return null;
            }

            $extension = $this->guessExtension($response->header('Content-Type'), $imageUrl);
            $filename = Str::slug(Str::random(16)) . '.' . $extension;
            $path = "{$directory}/{$filename}";

            Storage::disk('public')->put($path, $response->body());

            return $path;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function guessExtension(?string $contentType, string $url): string
    {
        $map = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];

        if ($contentType && isset($map[$contentType])) {
            return $map[$contentType];
        }

        // Deviner depuis l'URL
        if (preg_match('/\.(jpg|jpeg|png|webp|gif)/i', parse_url($url, PHP_URL_PATH), $m)) {
            return strtolower($m[1]);
        }

        return 'jpg';
    }
}
