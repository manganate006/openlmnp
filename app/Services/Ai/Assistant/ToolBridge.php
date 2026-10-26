<?php

namespace App\Services\Ai\Assistant;

use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Mcp\Request as McpRequest;
use Laravel\Mcp\Server\Tool;
use Throwable;

/**
 * Pont entre l'assistant et les outils MCP de lecture, exécutés **en interne** :
 * pas de requête HTTP, pas de jeton Sanctum, aucune dépendance à `MCP_ENABLED`
 * (un auto-hébergeur peut vouloir l'assistant sans exposer son serveur MCP).
 *
 * Seuls les outils de `config('ai.chat_tools')` sont accessibles — une allowlist de
 * lecture et de calculs non persistants, verrouillée par `AiConfigTest`.
 */
final class ToolBridge
{
    /**
     * Un résultat d'outil au-delà de cette taille est tronqué : il ne doit pas manger
     * tout le contexte ni faire exploser le coût de la question.
     */
    private const MAX_RESULT_CHARS = 12000;

    /**
     * Libellés lisibles pour la ligne « Consulté : … ».
     *
     * @var array<string, string>
     */
    private const LABELS = [
        'list_properties' => 'vos biens',
        'get_property' => 'un de vos biens',
        'list_fiscal_years' => 'vos exercices',
        'get_fiscal_year' => 'un de vos exercices',
        'get_onboarding_status' => 'votre progression',
        'get_dashboard_summary' => 'votre tableau de bord',
        'list_expenses' => 'vos charges',
        'list_incomes' => 'vos recettes',
        'list_loans' => 'vos emprunts',
        'get_loan' => 'un de vos emprunts',
        'list_furniture' => 'votre mobilier',
        'list_property_works' => 'vos travaux',
        'list_property_components' => 'vos composants',
        'list_categories' => 'les catégories disponibles',
        'compare_micro_bic' => 'la comparaison micro-BIC / réel',
        'get_simulation' => 'votre simulation',
    ];

    /**
     * @return array<int, string>
     */
    public function allowed(): array
    {
        return array_values(config('ai.chat_tools', []));
    }

    public function isAllowed(string $name): bool
    {
        return in_array($name, $this->allowed(), true);
    }

    /**
     * Définitions d'outils au format OpenAI, dérivées du schéma MCP de chaque outil.
     *
     * @return array<int, array<string, mixed>>
     */
    public function definitions(): array
    {
        $definitions = [];

        foreach ($this->allowed() as $name) {
            try {
                $tool = $this->resolve($name);
            } catch (Throwable) {
                continue;
            }

            $described = $tool->toArray();

            $definitions[] = [
                'type' => 'function',
                'function' => [
                    'name' => $described['name'],
                    'description' => $described['description'],
                    'parameters' => $described['inputSchema'],
                ],
            ];
        }

        return $definitions;
    }

    /**
     * Exécute un outil pour le compte de l'utilisateur courant et renvoie sa réponse
     * JSON, tronquée. Aucune exception ne remonte au modèle : une erreur devient un
     * message neutre, jamais le message d'exception d'origine.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function call(User $user, string $name, array $arguments): string
    {
        if (! $this->isAllowed($name)) {
            throw new InvalidArgumentException("Outil non autorisé pour l'assistant : {$name}");
        }

        // Défense en profondeur : l'isolation réelle vient des global scopes Eloquent,
        // mais l'assistant ne doit jamais s'exécuter sous une autre identité.
        if (Auth::id() !== $user->getKey()) {
            throw new InvalidArgumentException("L'assistant ne peut consulter que le compte connecté.");
        }

        try {
            $response = $this->resolve($name)->handle(new McpRequest($arguments));
        } catch (ValidationException $exception) {
            return $this->failure('arguments invalides', array_keys($exception->errors()));
        } catch (ModelNotFoundException) {
            return $this->failure('élément introuvable');
        } catch (Throwable) {
            return $this->failure('outil indisponible');
        }

        $content = (string) $response->content();

        if ($response->isError()) {
            return $this->failure('outil en erreur');
        }

        return Str::limit($content, self::MAX_RESULT_CHARS, ' […résultat tronqué]');
    }

    public function label(string $name): string
    {
        return self::LABELS[$name] ?? str_replace('_', ' ', $name);
    }

    /**
     * @param  array<int, string>  $names
     */
    public function labels(array $names): string
    {
        $labels = array_values(array_unique(array_map($this->label(...), $names)));

        return implode(', ', $labels);
    }

    /**
     * Le nom d'outil MCP suit la classe : `list_properties` → `App\Mcp\Tools\ListProperties`.
     * Le nom déclaré par la classe est vérifié pour éviter toute correspondance hasardeuse.
     */
    private function resolve(string $name): Tool
    {
        $class = 'App\\Mcp\\Tools\\'.Str::studly($name);

        if (! class_exists($class) || ! is_subclass_of($class, Tool::class)) {
            throw new InvalidArgumentException("Outil introuvable : {$name}");
        }

        $tool = app($class);

        if ($tool->name() !== $name) {
            throw new InvalidArgumentException("Nom d'outil inattendu pour {$class} : {$tool->name()}");
        }

        return $tool;
    }

    /**
     * @param  array<int, string>  $fields
     */
    private function failure(string $message, array $fields = []): string
    {
        return json_encode(
            array_filter(['error' => $message, 'champs' => $fields ?: null]),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ) ?: '{"error":"outil indisponible"}';
    }
}
