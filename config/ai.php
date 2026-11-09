<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Fonctions d'intelligence artificielle
    |--------------------------------------------------------------------------
    |
    | Socle commun à toutes les fonctionnalités IA (analyse de justificatifs,
    | assistant conversationnel). Un seul client HTTP, un seul point de
    | résolution de la clé, un seul journal d'usage.
    |
    | Le fournisseur par défaut est OpenRouter, mais il n'a rien d'obligatoire :
    | voir la section « Fournisseurs » plus bas. Une instance auto-hébergée peut
    | viser Ollama, LM Studio, vLLM ou tout serveur compatible OpenAI pour que
    | ses documents ne sortent jamais de chez elle.
    |
    | Interrupteur général : tant que AI_ENABLED est faux, aucune interface IA
    | n'apparaît (même logique que MCP_ENABLED).
    |
    */
    'enabled' => env('AI_ENABLED', false),

    /*
    | Fournisseur visé. `auto` le déduit du nom d'hôte d'AI_BASE_URL (voir la
    | clé `hosts` de chaque fournisseur ci-dessous) et retombe sur OpenRouter
    | quand aucune adresse n'est donnée — c'est-à-dire sur le comportement
    | historique. Une valeur inconnue échoue bruyamment : une faute de frappe
    | ne doit pas envoyer les justificatifs ailleurs qu'où on croit.
    */
    'provider' => env('AI_PROVIDER', 'auto'),

    // Vide = adresse par défaut du fournisseur choisi. Renseignée, elle PRIME
    // toujours : c'est ainsi qu'on place un serveur local, un proxy ou une
    // passerelle d'entreprise devant n'importe quel protocole.
    'base_url' => env('AI_BASE_URL'),

    // Clé d'instance : si elle est renseignée, elle sert à tous les comptes et
    // les utilisateurs ne voient jamais de champ « clé » dans leur profil.
    'api_key' => env('AI_API_KEY'),

    // Autorise chaque utilisateur à saisir SA propre clé dans Mon profil
    // (auto-hébergement multi-comptes, ou instance sans clé globale).
    'allow_user_keys' => env('AI_ALLOW_USER_KEYS', true),

    /*
    | Bouton « Se connecter avec … » dans Mon profil : l'utilisateur autorise
    | l'instance depuis son compte chez le fournisseur, qui renvoie une clé LUI
    | APPARTENANT — sans copier-coller, sans inscription d'application, sans
    | identifiant ni secret côté instance.
    |
    | Sans effet chez un fournisseur qui ne propose pas cette remise de clé.
    */
    'oauth_enabled' => env('AI_OAUTH_ENABLED', true),

    // Délai HTTP en secondes. L'application n'a pas de file d'attente :
    // les appels tournent dans la requête (voir docs/INSTALLATION.md).
    'timeout' => (int) env('AI_TIMEOUT', 60),

    // Transmis à OpenRouter (`provider.data_collection`) pour n'utiliser que
    // des fournisseurs qui ne conservent ni n'entraînent sur les données.
    'data_collection' => env('AI_DATA_COLLECTION', 'deny'),

    // En-têtes d'attribution recommandés par OpenRouter.
    'app_referer' => env('AI_APP_REFERER', 'https://openlmnp.fr'),
    'app_title' => env('AI_APP_TITLE', 'OpenLMNP'),

    // Rétention du journal d'usage, purgé par `openlmnp:ai-usage-cleanup`.
    'usage_retention_days' => (int) env('AI_USAGE_RETENTION', 90),

    /*
    |--------------------------------------------------------------------------
    | Fournisseurs
    |--------------------------------------------------------------------------
    |
    | Identité, adresse par défaut, aide de saisie de la clé et modèles par
    | défaut. `driver` désigne le protocole parlé, `hosts` sert à la détection
    | automatique.
    |
    | ⚠️ Les CAPACITÉS ne sont volontairement PAS ici : elles vivent dans le
    | pilote (App\Services\Ai\Providers\*::supports()). Une capacité est une
    | propriété du protocole, pas un réglage — laisser un administrateur cocher
    | « lecture des PDF » sur un Ollama ne produirait qu'une panne silencieuse.
    |
    */
    'providers' => [

        'openrouter' => [
            'driver' => 'openrouter',
            'label' => 'OpenRouter',
            'key_label' => 'OpenRouter',
            'base_url' => 'https://openrouter.ai/api/v1',
            'hosts' => ['openrouter.ai'],
            'key_hint' => ['placeholder' => 'sk-or-v1-…', 'url' => 'https://openrouter.ai/keys'],
            'country' => 'US',
            'models' => [
                'documents' => 'google/gemini-3.8-flash',
                'documents_fallback' => 'google/gemini-3.6-flash',
                'chat' => 'google/gemini-3.8-flash',
            ],
            'catalog' => [
                'documents' => [
                    ['model' => 'google/gemini-3.8-flash', 'label' => 'Gemini 3.8 Flash', 'country' => 'US', 'note' => 'notre défaut : bon compromis lecture/prix'],
                    ['model' => 'google/gemini-3.6-flash', 'label' => 'Gemini 3.6 Flash', 'country' => 'US', 'note' => 'meilleure lecture des scans, même prix'],
                    ['model' => 'google/gemini-3.5-flash-lite', 'label' => 'Gemini 3.5 Flash Lite', 'country' => 'US', 'note' => 'le moins cher'],
                    ['model' => 'anthropic/claude-haiku-4.5', 'label' => 'Claude Haiku 4.5', 'country' => 'US', 'note' => 'autre éditeur, utile en cas de panne'],
                    ['model' => 'mistralai/mistral-medium-3-5', 'label' => 'Mistral Medium 3.5', 'country' => 'FR', 'note' => 'modèle européen, routé par OpenRouter'],
                ],
                'chat' => [
                    ['model' => 'google/gemini-3.8-flash', 'label' => 'Gemini 3.8 Flash', 'country' => 'US', 'note' => 'notre défaut : bon compromis lecture/prix'],
                    ['model' => 'google/gemini-3.5-flash-lite', 'label' => 'Gemini 3.5 Flash Lite', 'country' => 'US', 'note' => 'le moins cher'],
                    ['model' => 'anthropic/claude-haiku-4.5', 'label' => 'Claude Haiku 4.5', 'country' => 'US'],
                    ['model' => 'mistralai/mistral-medium-3-5', 'label' => 'Mistral Medium 3.5', 'country' => 'FR', 'note' => 'modèle européen, routé par OpenRouter'],
                ],
            ],
        ],

        'openai' => [
            'driver' => 'openai',
            'label' => 'OpenAI',
            'key_label' => 'OpenAI',
            'base_url' => 'https://api.openai.com/v1',
            'hosts' => ['api.openai.com'],
            'key_hint' => ['placeholder' => 'sk-…', 'url' => 'https://platform.openai.com/api-keys'],
            'country' => 'US',
            'models' => [
                'documents' => 'gpt-5-mini',
                'documents_fallback' => '',
                'chat' => 'gpt-5-mini',
            ],
        ],

        /*
        | Anthropic et Google, en protocole NATIF.
        |
        | Ils lisent un PDF sans passer par l'extraction de sa couche texte,
        | contrairement à un serveur compatible OpenAI. Ce qu'ils ne savent en
        | revanche pas faire : rendre le coût réel de l'appel (renseigner
        | AI_MODEL_PRICES, sinon le journal reste sans coût) et rejouer un
        | parsing déjà facturé — une réparation ou une cascade renvoie donc le
        | document, et le refacture.
        |
        | ⚠️ Contrairement à OpenRouter, ces deux protocoles n'ont pas été
        | validés par un appel réel depuis ce dépôt : éprouvez-les sur un
        | document de test avant de vous en servir en production, comme pour
        | tout nouveau modèle.
        */
        'anthropic' => [
            'driver' => 'anthropic',
            'label' => 'Anthropic',
            'key_label' => 'Anthropic',
            'base_url' => 'https://api.anthropic.com/v1',
            'hosts' => ['api.anthropic.com'],
            'key_hint' => ['placeholder' => 'sk-ant-…', 'url' => 'https://console.anthropic.com/settings/keys'],
            'country' => 'US',
            'catalog' => [
                'documents' => [
                    ['model' => 'claude-sonnet-5', 'label' => 'Claude Sonnet 5', 'country' => 'US'],
                ],
                'chat' => [
                    ['model' => 'claude-haiku-4-5-20251001', 'label' => 'Claude Haiku 4.5', 'country' => 'US', 'note' => 'le plus rapide'],
                    ['model' => 'claude-sonnet-5', 'label' => 'Claude Sonnet 5', 'country' => 'US'],
                ],
            ],
            'models' => [
                'documents' => 'claude-sonnet-5',
                // Pas de cascade par défaut : un second appel chez le même
                // éditeur coûterait sans rien apporter de plus à la lecture.
                'documents_fallback' => '',
                'chat' => 'claude-haiku-4-5-20251001',
            ],
        ],

        'gemini' => [
            'driver' => 'gemini',
            'label' => 'Google Gemini',
            'key_label' => 'Google Gemini',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'hosts' => ['generativelanguage.googleapis.com'],
            'key_hint' => ['placeholder' => 'AIza…', 'url' => 'https://aistudio.google.com/apikey'],
            'country' => 'US',
            'catalog' => [
                'documents' => [
                    ['model' => 'gemini-3.8-flash', 'label' => 'Gemini 3.8 Flash', 'country' => 'US', 'note' => 'notre défaut : bon compromis lecture/prix'],
                    ['model' => 'gemini-3.6-flash', 'label' => 'Gemini 3.6 Flash', 'country' => 'US', 'note' => 'meilleure lecture des scans'],
                ],
                'chat' => [
                    ['model' => 'gemini-3.8-flash', 'label' => 'Gemini 3.8 Flash', 'country' => 'US'],
                    ['model' => 'gemini-3.5-flash-lite', 'label' => 'Gemini 3.5 Flash Lite', 'country' => 'US', 'note' => 'le moins cher'],
                ],
            ],
            'models' => [
                'documents' => 'gemini-3.8-flash',
                'documents_fallback' => 'gemini-3.6-flash',
                'chat' => 'gemini-3.8-flash',
            ],
        ],

        /*
        | Mistral AI, par son point d'entrée compatible OpenAI.
        |
        | Le seul fournisseur français de la liste : les documents restent en
        | Europe, ce qui est un critère à part entière pour une comptabilité.
        | Comme tout service compatible OpenAI, il ne reçoit pas de PDF — la
        | couche texte est extraite, et un scan doit être déposé en image.
        |
        | ⚠️ Identifiants de modèles relevés dans la documentation de Mistral le
        | 2026-09-04, JAMAIS validés par un appel réel depuis ce dépôt. Mistral
        | maintient aussi des alias `-latest`, non retenus faute d'avoir pu
        | vérifier vers quoi ils pointent aujourd'hui.
        */
        'mistral' => [
            'driver' => 'openai',
            'label' => 'Mistral AI',
            'key_label' => 'Mistral AI',
            'base_url' => 'https://api.mistral.ai/v1',
            'hosts' => ['api.mistral.ai', 'mistral.ai'],
            'key_hint' => ['placeholder' => '', 'url' => 'https://console.mistral.ai/api-keys'],
            'country' => 'FR',
            'catalog' => [
                'documents' => [
                    ['model' => 'mistral-medium-3-5-26-04', 'label' => 'Mistral Medium 3.5', 'country' => 'FR', 'note' => 'sait lire une photo'],
                    ['model' => 'mistral-large-3-25-12', 'label' => 'Mistral Large 3', 'country' => 'FR', 'note' => 'plus grand, plus cher'],
                ],
                'chat' => [
                    ['model' => 'mistral-small-4-0-26-03', 'label' => 'Mistral Small 4', 'country' => 'FR', 'note' => 'économique'],
                    ['model' => 'ministral-3-8b-25-12', 'label' => 'Ministral 3 8B', 'country' => 'FR', 'note' => 'le plus léger'],
                    ['model' => 'mistral-medium-3-5-26-04', 'label' => 'Mistral Medium 3.5', 'country' => 'FR'],
                ],
            ],
            'models' => [
                // Multimodal : indispensable, un justificatif arrive souvent en photo.
                'documents' => 'mistral-medium-3-5-26-04',
                // Pas de cascade par défaut : un second appel chez le même
                // éditeur coûterait sans améliorer la lecture, et rien n'a été
                // mesuré ici. `AI_MODEL_DOCUMENTS_FALLBACK` reste disponible.
                'documents_fallback' => '',
                'chat' => 'mistral-small-4-0-26-03',
            ],
        ],

        /*
        | Serveur compatible OpenAI auto-hébergé : Ollama, LM Studio, vLLM,
        | llama.cpp. Aucun modèle par défaut — cela dépend de ce qui y est
        | installé — et AI_BASE_URL est donc obligatoire.
        */
        'local' => [
            'driver' => 'openai',
            'label' => 'Serveur compatible OpenAI',
            'key_label' => '',
            'base_url' => '',
            'hosts' => [],
            'key_hint' => ['placeholder' => '', 'url' => null],
            'country' => 'self',
            'models' => ['documents' => '', 'documents_fallback' => '', 'chat' => ''],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pays d'édition des modèles
    |--------------------------------------------------------------------------
    |
    | Sert à afficher un drapeau à côté d'un identifiant de modèle, y compris
    | quand il ne figure dans aucun catalogue. La clé est le préfixe d'éditeur
    | des identifiants d'OpenRouter ; ailleurs, l'éditeur est le fournisseur
    | lui-même et c'est sa clé `country` qui répond.
    |
    | ⚠️ Le pays de l'ÉDITEUR n'est pas celui du service qui ROUTE la requête :
    | un modèle français appelé via OpenRouter passe par un intermédiaire
    | américain. L'interface montre les deux, jamais l'un pour l'autre.
    |
    */
    'model_publishers' => [
        'google/' => 'US',
        'openai/' => 'US',
        'anthropic/' => 'US',
        'meta-llama/' => 'US',
        'x-ai/' => 'US',
        'microsoft/' => 'US',
        'nvidia/' => 'US',
        'amazon/' => 'US',
        'mistralai/' => 'FR',
        'qwen/' => 'CN',
        'deepseek/' => 'CN',
        'moonshotai/' => 'CN',
        'z-ai/' => 'CN',
        'minimax/' => 'CN',
        'baidu/' => 'CN',
        'cohere/' => 'CA',
        'ai21/' => 'IL',
    ],

    /*
    |--------------------------------------------------------------------------
    | Modèles
    |--------------------------------------------------------------------------
    |
    | Ils se changent par variable d'environnement, sans déploiement : le marché
    | des modèles bouge tous les mois.
    |
    | Vide = modèle par défaut du fournisseur configuré (voir `providers`). Une
    | valeur renseignée prime. Les slugs ne sont PAS portables d'un fournisseur
    | à l'autre (`google/gemini-3.8-flash` chez OpenRouter contre
    | `gemini-3.8-flash` chez Google) : laisser vide est le choix sûr quand on
    | change de fournisseur.
    |
    */
    'models' => [
        // Extraction de documents (vision + PDF natif + sorties structurées).
        'documents' => env('AI_MODEL_DOCUMENTS'),

        // Modèle de repli, appelé si le premier renvoie un JSON invalide après
        // réparation, est indisponible, ou si la confiance est trop basse.
        // Vide = celui du fournisseur ; `none` = pas de cascade du tout.
        //
        // Le cas dominant est une confiance basse, c'est-à-dire un document mal
        // lisible : ce qu'il faut alors, c'est le meilleur OCR, pas le meilleur
        // raisonneur. gemini-3.6-flash est n° 1 sur ce critère et coûte 2,7 fois
        // moins cher qu'un repli chez un autre éditeur (voir la comparaison des
        // modèles dans les notes d'exploitation).
        'documents_fallback' => env('AI_MODEL_DOCUMENTS_FALLBACK'),

        // Assistant conversationnel (texte + appel d'outils).
        'chat' => env('AI_MODEL_CHAT'),
    ],

    // Seuil de confiance globale sous lequel la cascade vers le modèle de repli
    // se déclenche automatiquement.
    'fallback_confidence' => (float) env('AI_FALLBACK_CONFIDENCE', 0.6),

    /*
    | Effort de raisonnement demandé au modèle (`reasoning.effort` d'OpenRouter),
    | par fonction : low | medium | high, ou none pour ne pas envoyer le paramètre
    | (serveur local qui le refuserait).
    |
    | ⚠️ Sur un modèle « thinking » (Gemini 3.x Flash), le raisonnement est
    | facturé dans completion_tokens sans jamais atteindre l'utilisateur, et il
    | consomme max_tokens : mesuré à 532 jetons sur 761 pour une question simple,
    | avec une réponse coupée en plein mot. `low` le ramène à 0 (mesuré).
    | `enabled: false` est refusé en 400 (mesuré sur Gemini 3.7 Flash, présumé
    | valable sur la lignée Flash — non revérifié sur 3.8), `exclude: true`
    | masque sans rien économiser : seul `effort` agit.
    |
    | L'effet de `low` sur l'exactitude d'extraction des documents reste à
    | mesurer sur le corpus réel (ai:eval-documents) ; `medium` est la valeur
    | de repli si les scans y perdent.
    */
    'reasoning' => [
        'chat' => env('AI_CHAT_REASONING', 'low'),
        'documents' => env('AI_DOCS_REASONING', 'low'),
    ],

    /*
    | Seuil au-dessus duquel le bouton « Réanalyser avec le modèle renforcé »
    | disparaît de l'écran de relecture : au-delà, l'extraction est sûre et
    | proposer un appel supplémentaire — facturé — n'a pas de sens.
    |
    | 0,85 est le seuil « Lu avec certitude » des pastilles de confiance :
    | l'écran reste cohérent avec lui-même.
    */
    'reanalysis_threshold' => (float) env('AI_REANALYSIS_THRESHOLD', 0.85),

    /*
    | Moteur PDF du plugin OpenRouter `file-parser` :
    |   native        le modèle lit le PDF lui-même (facturé en jetons ordinaires)
    |   cloudflare-ai conversion Markdown, GRATUITE
    |   mistral-ocr   OCR payant (2 $ / 1 000 pages), réservé aux scans
    |   pdf-text      DÉPRÉCIÉ — redirigé par OpenRouter vers `cloudflare-ai`
    |
    | ⚠️ Toujours explicite : sans moteur, OpenRouter tente d'abord le PDF natif
    | du modèle, puis bascule sur `mistral-ocr` (payant).
    |
    | ⚠️ Un modèle SANS entrée `file` n'est pas disqualifié pour autant :
    | OpenRouter parse le PDF EN AMONT et lui transmet le résultat. Le critère
    | qui discrimine vraiment, c'est `image` — sans lui, un modèle ne dégrade
    | pas sur une photo ou un scan, il REFUSE.
    */
    'pdf_engine' => env('AI_PDF_ENGINE', 'native'),

    /*
    | Le plugin `file-parser` ci-dessus appartient à OpenRouter : un serveur
    | local compatible OpenAI (Ollama, LM Studio, vLLM) reçoit la partie `file`
    | sans savoir la lire. Dans ce cas, OpenLMNP extrait la couche texte du PDF
    | et l'envoie en texte ordinaire.
    |
    |   auto    replie sur le texte dès que AI_BASE_URL n'est pas OpenRouter
    |   always  replie toujours (passerelle d'entreprise devant OpenRouter…)
    |   never   n'essaie jamais — le service sait recevoir les PDF
    |
    | Un PDF SCANNÉ n'a pas de couche texte : il reste à envoyer en image.
    */
    'pdf_text_fallback' => env('AI_PDF_TEXT_FALLBACK', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | Quotas par utilisateur
    |--------------------------------------------------------------------------
    |
    | 0 = illimité. Fenêtres calculées dans le fuseau horaire de l'utilisateur
    | (mois civil pour les documents, jour civil pour le chat).
    |
    | Un utilisateur qui fournit sa propre clé n'est pas soumis aux quotas
    | (c'est sa clé, son coût), sauf si l'administrateur force le contraire.
    |
    */
    'docs_monthly_quota' => (int) env('AI_DOCS_MONTHLY_QUOTA', 0),
    'chat_daily_quota' => (int) env('AI_CHAT_DAILY_QUOTA', 0),

    // Budget de dépense de TOUTE l'instance sur sa propre clé, en dollars par jour (UTC).
    // Les quotas ci-dessus bornent chaque compte, pas le nombre de comptes : ce plafond
    // borne la facture. Atteint, les fonctions d'IA sur la clé de l'instance se mettent en
    // pause jusqu'au lendemain ; une clé personnelle n'est pas concernée. 0 = désactivé.
    'daily_budget_usd' => (float) env('AI_DAILY_BUDGET_USD', 0),
    'quota_applies_to_user_keys' => env('AI_QUOTA_APPLIES_TO_USER_KEYS', false),

    /*
    |--------------------------------------------------------------------------
    | Assistant conversationnel
    |--------------------------------------------------------------------------
    */
    'chat_max_turns' => (int) env('AI_CHAT_MAX_TURNS', 20),
    'chat_tool_calls_max' => (int) env('AI_CHAT_TOOL_CALLS_MAX', 4),

    /*
    | Outils MCP que l'assistant peut consulter quand l'utilisateur a activé
    | l'accès à ses données. Sous-ensemble STRICT de l'allowlist démo
    | (config('mcp.demo.tools')) : lecture et calculs non persistants
    | uniquement, jamais de create_/update_/delete_/import_/export_.
    |
    | Source de vérité unique — verrouillée par un test.
    */
    'chat_tools' => [
        // Lecture
        'list_properties', 'get_property',
        'list_fiscal_years', 'get_fiscal_year',
        'get_onboarding_status', 'get_dashboard_summary',
        'list_expenses', 'list_incomes',
        'list_loans', 'get_loan',
        'list_furniture', 'list_property_works', 'list_property_components',
        'list_categories',
        // Simulation (aucune écriture)
        'compare_micro_bic', 'get_simulation',
    ],

    /*
    |--------------------------------------------------------------------------
    | Tarifs des modèles (facultatif)
    |--------------------------------------------------------------------------
    |
    | OpenRouter renvoie le coût réel de chaque appel (`usage.cost`) : c'est
    | cette valeur qui est journalisée. Cette table ne sert qu'aux serveurs
    | compatibles OpenAI qui ne renvoient pas de coût ; sans elle, le coût
    | reste simplement vide dans le journal.
    |
    | Prix en dollars par MILLION de jetons, en chaînes (calcul bcmath), sous
    | forme d'un objet JSON dans AI_MODEL_PRICES :
    |   {"gemini-3.8-flash": {"in": "0.750", "out": "3.750"}}
    |
    | Volontairement VIDE par défaut, et aucun tarif n'est écrit dans ce
    | fichier : ils changent tous les mois, une table figée dans le code serait
    | fausse en quelques semaines — et un chiffre faux vaut moins que pas de
    | chiffre du tout.
    |
    */
    'model_prices' => json_decode((string) env('AI_MODEL_PRICES', '{}'), associative: true) ?: [],
];
