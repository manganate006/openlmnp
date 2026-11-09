<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\KeyStatus;

/**
 * OpenRouter : protocole OpenAI, plus ses champs propriétaires.
 *
 * C'est le fournisseur par défaut, et le seul qui apporte les quatre choses qui
 * comptent pour l'analyse de justificatifs : la lecture des PDF par un plugin à
 * moteur choisi, le rejeu d'un parsing déjà facturé, le coût réel de l'appel, et
 * une politique de conservation transmise au fournisseur final.
 */
final class OpenRouterProvider extends OpenAiProvider
{
    public function supports(Capability $capability): bool
    {
        return match ($capability) {
            Capability::PdfUpload,
            Capability::FileParserPlugin,
            Capability::FileAnnotations,
            Capability::DataCollectionPolicy,
            Capability::AttributionHeaders,
            Capability::ReportedCost,
            Capability::KeyIntrospection,
            Capability::StructuredOutput,
            Capability::OauthKeyHandover => true,
        };
    }

    public function headers(): array
    {
        // Attribution : crée la page d'application OpenLMNP chez OpenRouter et
        // l'y fait apparaître dans les classements d'usage. Aucune donnée
        // personnelle — une adresse de site et un titre, surchargeables par
        // AI_APP_REFERER / AI_APP_TITLE.
        return [
            'HTTP-Referer' => (string) config('ai.app_referer'),
            'X-Title' => (string) config('ai.app_title'),
        ];
    }

    protected function decorate(array $payload, ChatRequest $request): array
    {
        // Politique de conservation demandée au fournisseur final. ⚠️ C'est une
        // préférence de routage transmise à un intermédiaire, pas un engagement
        // opposable : ne jamais la décrire à l'indicatif dans un texte destiné
        // à l'utilisateur.
        $payload['provider'] = ['data_collection' => (string) config('ai.data_collection', 'deny')];

        // Fait renvoyer le coût réel de l'appel, journalisé tel quel.
        $payload['usage'] = ['include' => true];

        return $payload;
    }

    protected function decorateFiles(array $payload, ChatRequest $request): array
    {
        // Plugin de lecture de fichiers : uniquement si un PDF est joint, et
        // avec un moteur explicite pour ne pas déclencher l'OCR payant.
        if ($request->hasFilePart()) {
            $payload['plugins'] = [[
                'id' => 'file-parser',
                'pdf' => ['engine' => (string) config('ai.pdf_engine', 'native')],
            ]];
        }

        // Annotations d'un parsing précédent : évite de re-parser et de repayer
        // le même document lors d'une réparation ou d'une cascade.
        if ($request->fileAnnotations !== []) {
            $payload['messages'] = $this->attachAnnotations($payload['messages'], $request->fileAnnotations);
        }

        return $payload;
    }

    /**
     * Rejoue les annotations d'un parsing déjà facturé.
     *
     * OpenRouter les attend sur un message ASSISTANT de la requête de suivi, le
     * message utilisateur conservant sa partie fichier. On insère donc, juste
     * après le premier message utilisateur qui porte un fichier, un message
     * assistant ne contenant que les annotations — sauf si la conversation en
     * porte déjà un (réinjection directe d'AiResult->message). Le consommateur
     * envoie `[system, user(fichier), user(consigne)]` et le fournisseur reçoit
     * `[system, user(fichier), assistant(annotations), user(consigne)]` sans
     * re-parser le document.
     *
     * @param  array<mixed>  $messages
     * @param  array<mixed>  $annotations
     * @return array<mixed>
     */
    private function attachAnnotations(array $messages, array $annotations): array
    {
        foreach ($messages as $message) {
            if (($message['role'] ?? null) === 'assistant' && ! empty($message['annotations'])) {
                return $messages;
            }
        }

        foreach ($messages as $i => $message) {
            if (($message['role'] ?? null) !== 'user') {
                continue;
            }

            $carriesFile = false;

            foreach ((array) ($message['content'] ?? []) as $part) {
                if (is_array($part) && ($part['type'] ?? null) === 'file') {
                    $carriesFile = true;

                    break;
                }
            }

            if (! $carriesFile) {
                continue;
            }

            array_splice($messages, $i + 1, 0, [[
                'role' => 'assistant',
                'content' => 'Document lu.',
                'annotations' => $annotations,
            ]]);

            return $messages;
        }

        return $messages;
    }

    protected function reportedCost(array $body): mixed
    {
        return $body['usage']['cost'] ?? null;
    }

    public function keyCheckPath(): string
    {
        // Libellé, usage et crédit restant : seul OpenRouter les expose.
        return '/auth/key';
    }

    public function parseKeyCheck(array $body): KeyStatus
    {
        $data = (array) ($body['data'] ?? []);

        return new KeyStatus(
            valid: true,
            label: isset($data['label']) ? (string) $data['label'] : null,
            limitRemaining: isset($data['limit_remaining']) && $data['limit_remaining'] !== null
                ? (string) $data['limit_remaining']
                : null,
            usage: isset($data['usage']) ? (string) $data['usage'] : null,
        );
    }

    public function authorizeUrl(): ?string
    {
        return $this->siteUrl() . '/auth';
    }

    public function keyExchangeUrl(): ?string
    {
        return $this->baseUrl() . '/auth/keys';
    }

    public function creditsUrl(): ?string
    {
        return $this->siteUrl() . '/settings/credits';
    }

    /**
     * Racine du site, dérivée de la base URL de l'API : une passerelle
     * d'entreprise placée devant OpenRouter garde ainsi ses propres adresses,
     * et il n'y a qu'une variable d'environnement à renseigner.
     */
    private function siteUrl(): string
    {
        $parts = parse_url($this->baseUrl());

        if (! isset($parts['scheme'], $parts['host'])) {
            return 'https://openrouter.ai';
        }

        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return $parts['scheme'] . '://' . $parts['host'] . $port;
    }
}
