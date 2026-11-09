<?php

namespace App\Services\Ai\Providers;

/**
 * Ce qu'un fournisseur sait faire.
 *
 * Une capacité est une propriété du PROTOCOLE, pas un réglage : elle vit donc
 * dans le pilote et jamais dans config/ai.php. Laisser un administrateur cocher
 * « le plugin de lecture de PDF » sur un Ollama ne produirait qu'une panne
 * silencieuse, sans lui donner la moindre chance de comprendre pourquoi.
 */
enum Capability: string
{
    /** Sait recevoir un PDF binaire (sinon : envoi de la couche texte extraite). */
    case PdfUpload = 'pdf_upload';

    /** Plugin `file-parser` d'OpenRouter, piloté par AI_PDF_ENGINE. */
    case FileParserPlugin = 'file_parser_plugin';

    /** Rejeu des annotations d'un parsing déjà facturé (réparation, cascade). */
    case FileAnnotations = 'file_annotations';

    /** Champ `provider.data_collection` : politique de conservation demandée. */
    case DataCollectionPolicy = 'data_collection_policy';

    /** En-têtes d'attribution HTTP-Referer / X-Title. */
    case AttributionHeaders = 'attribution_headers';

    /** Renvoie le coût réel de l'appel (sinon : estimation via AI_MODEL_PRICES). */
    case ReportedCost = 'reported_cost';

    /** Sait décrire une clé (libellé, usage, crédit restant). */
    case KeyIntrospection = 'key_introspection';

    /**
     * Accepte un schéma JSON contraint ET peut le refuser, auquel cas un repli
     * moins strict a du sens. Faux là où le schéma passe par un autre mécanisme
     * (outil forcé, responseSchema) : un refus y est une erreur de
     * configuration, et relancer un appel payant ne réparerait rien.
     */
    case StructuredOutput = 'structured_output';

    /** Remise d'une clé utilisateur par OAuth PKCE (« Se connecter avec … »). */
    case OauthKeyHandover = 'oauth_key_handover';
}
