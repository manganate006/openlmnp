<?php

namespace App\Services\Ai\Exceptions;

/**
 * Réponse absente, tronquée, ou JSON non conforme au schéma demandé.
 *
 * `schemaRejected` distingue le seul cas qui justifie un second appel (payant) :
 * un serveur compatible OpenAI qui refuse `response_format: json_schema`. Un
 * contenu simplement mal formé ne doit PAS relancer la requête.
 *
 * `fileAnnotations` porte, quand la réponse en contenait, le résultat du parsing
 * de fichier déjà facturé : la passe de RÉPARATION (que le consommateur décide
 * de lancer) peut ainsi le rejouer via $options['file_annotations'] au lieu de
 * faire re-parser — et repayer — le même PDF.
 */
final class AiBadResponse extends AiException
{
    /** @param array<mixed> $fileAnnotations */
    public function __construct(
        string $message = "La réponse du modèle est inexploitable. Réessayez ou changez de modèle.",
        public readonly bool $schemaRejected = false,
        public readonly array $fileAnnotations = [],
    ) {
        parent::__construct($message);
    }
}
