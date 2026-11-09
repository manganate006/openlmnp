<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Exceptions\AiMisconfigured;

/**
 * Quel fournisseur cette instance utilise-t-elle, et à quelle adresse ?
 *
 * Une seule règle de détection dans tout le projet — c'est elle qui a remplacé
 * le `isOpenRouter()` autrefois codé en dur dans PdfTextFallback.
 */
final class AiProviders
{
    /** Pilotes disponibles, par nom de `driver` déclaré dans config('ai.providers'). */
    private const DRIVERS = [
        'openai' => OpenAiProvider::class,
        'openrouter' => OpenRouterProvider::class,
        'anthropic' => AnthropicProvider::class,
        'gemini' => GeminiProvider::class,
    ];

    /**
     * Fournisseur configuré, adresse effective résolue.
     *
     * ⚠️ Aucun cache statique : la configuration change d'un test à l'autre, et
     * un administrateur qui corrige son .env ne doit pas avoir à vider un cache
     * dont personne ne lui a parlé.
     *
     * @throws AiMisconfigured
     */
    public static function resolve(): AiProvider
    {
        $key = self::resolveKey();
        $definitions = (array) config('ai.providers', []);

        if (! isset($definitions[$key]) || ! is_array($definitions[$key])) {
            throw AiMisconfigured::unknownProvider($key);
        }

        $definition = $definitions[$key];

        // Une base URL explicite PRIME toujours : c'est elle qui permet de
        // placer une passerelle d'entreprise, un proxy ou un serveur local
        // devant n'importe quel protocole.
        $baseUrl = trim((string) config('ai.base_url', ''));

        if ($baseUrl === '') {
            $baseUrl = trim((string) ($definition['base_url'] ?? ''));
        }

        if ($baseUrl === '') {
            throw AiMisconfigured::missingBaseUrl($key);
        }

        $driver = (string) ($definition['driver'] ?? 'openai');
        $class = self::DRIVERS[$driver] ?? OpenAiProvider::class;

        return new $class($key, $baseUrl, $definition);
    }

    /**
     * Identifiant du fournisseur : la valeur explicite d'AI_PROVIDER, ou la
     * déduction depuis le nom d'hôte d'AI_BASE_URL.
     *
     * Sans rien : OpenRouter, comme avant. Avec une base URL dont l'hôte n'est
     * déclaré par aucun fournisseur : `local`, ce qui décrit exactement un
     * Ollama ou un vLLM — et c'est ce que faisait déjà l'ancienne détection.
     */
    public static function resolveKey(): string
    {
        $configured = strtolower(trim((string) config('ai.provider', 'auto')));

        if ($configured !== '' && $configured !== 'auto') {
            return $configured;
        }

        $baseUrl = trim((string) config('ai.base_url', ''));

        if ($baseUrl === '') {
            return 'openrouter';
        }

        return self::detectFromHost($baseUrl);
    }

    /** Fournisseur déclarant cet hôte, ou `local` à défaut. */
    private static function detectFromHost(string $baseUrl): string
    {
        $host = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));

        if ($host === '') {
            return 'local';
        }

        foreach ((array) config('ai.providers', []) as $key => $definition) {
            foreach ((array) ($definition['hosts'] ?? []) as $known) {
                $known = strtolower((string) $known);

                if ($host === $known || str_ends_with($host, '.' . $known)) {
                    return (string) $key;
                }
            }
        }

        return 'local';
    }
}
