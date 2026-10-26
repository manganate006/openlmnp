<?php

namespace App\Services\Ai\Analysis;

/**
 * Proposition d'écriture issue de l'analyse d'un justificatif.
 *
 * DTO volontairement PLAT et sérialisable : il est stocké tel quel dans l'état
 * d'un composant Livewire (`public ?array $analysis`), et le jour où l'analyse
 * passerait en file d'attente (CDC § 4.8), rien ne changerait pour les
 * appelants.
 *
 * Il ne contient QUE des valeurs déjà normalisées par le Normalizer : montants
 * en centimes, dates ISO, catégorie parmi les onze de l'énumération SQL. Le
 * formulaire de prévisualisation les affiche, l'utilisateur les corrige, et
 * c'est SA version qui est enregistrée — jamais celle-ci directement.
 */
final class AnalysisResult
{
    /** Type de document -> famille d'entité comptable visée. */
    private const ENTITY_BY_TYPE = [
        'expense_invoice' => 'expense',
        'property_tax_notice' => 'expense',
        'insurance' => 'expense',
        'furniture_invoice' => 'furniture',
        'works_invoice' => 'work',
        'loan_schedule' => 'loan',
        'bank_statement' => 'statement',
        'income_statement' => 'earnings',
        // La liasse ne crée AUCUNE écriture : elle pré-remplit l'assistant de
        // reprise, qui a sa propre relecture. apply() la refuse.
        'liasse_2033' => 'reprise',
        'unknown' => 'expense',
    ];

    /**
     * @param  array<string, mixed>   $values          Attributs prêts pour le formulaire (centimes, ISO…).
     * @param  array<string, float>   $confidences     Confiance par champ, plafonnée après normalisation.
     * @param  array<string, string>  $evidence        Extrait du document par champ (infobulle « Lu : … »).
     * @param  array<int, string>     $warnings        Avertissements français destinés à l'utilisateur.
     * @param  array<int, array>      $rows            Articles, échéances ou opérations selon le type.
     * @param  array<string, mixed>|null $duplicate    Entité existante ressemblante, ou null.
     */
    public function __construct(
        public readonly string $documentType,
        public readonly float $confidence,
        public readonly array $values = [],
        public readonly array $confidences = [],
        public readonly array $evidence = [],
        public readonly array $warnings = [],
        public readonly array $rows = [],
        public readonly ?array $duplicate = null,
        public readonly string $model = '',
        public readonly bool $fallbackUsed = false,
        public readonly ?int $propertyId = null,
        public readonly ?string $filePath = null,
        public readonly ?string $fileName = null,
    ) {}

    /** Famille d'entité comptable visée : expense | furniture | work | loan | statement. */
    public function entity(): string
    {
        return self::ENTITY_BY_TYPE[$this->documentType] ?? 'expense';
    }

    /** Le document n'a pas pu être identifié de façon exploitable. */
    public function isUnrecognised(): bool
    {
        return $this->documentType === 'unknown' || $this->confidence < 0.3;
    }

    /** Tranche de confiance, pour les analytics et le journal (jamais la valeur exacte). */
    public function confidenceBucket(): string
    {
        return match (true) {
            $this->confidence >= 0.85 => 'high',
            $this->confidence >= 0.6 => 'medium',
            default => 'low',
        };
    }

    /** Copie avec un type de document imposé par l'utilisateur (sélecteur du bandeau). */
    public function withDocumentType(string $documentType): self
    {
        return $this->with(['document_type' => $documentType]);
    }

    /** Copie avec l'information de doublon renseignée. */
    public function withDuplicate(?array $duplicate): self
    {
        return $this->with(['duplicate' => $duplicate]);
    }

    /** Copie avec le fichier en attente rattaché. */
    public function withFile(?string $filePath, ?string $fileName): self
    {
        return $this->with(['file_path' => $filePath, 'file_name' => $fileName]);
    }

    /**
     * Copie avec les lignes remplacées (marquage des doublons d'un relevé).
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function withRows(array $rows): self
    {
        return $this->with(['rows' => $rows]);
    }

    /**
     * Copie avec les avertissements remplacés.
     *
     * Sert à la garde contre le double comptage d'une année : elle s'exécute APRÈS
     * la normalisation, donc hors du Normalizer qui collecte les autres.
     *
     * @param  array<int, string>  $warnings
     */
    public function withWarnings(array $warnings): self
    {
        return $this->with(['warnings' => $warnings]);
    }

    /** @param array<string, mixed> $changes */
    private function with(array $changes): self
    {
        return self::fromArray(array_replace($this->toArray(), $changes));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'document_type' => $this->documentType,
            'confidence' => $this->confidence,
            'values' => $this->values,
            'confidences' => $this->confidences,
            'evidence' => $this->evidence,
            'warnings' => $this->warnings,
            'rows' => $this->rows,
            'duplicate' => $this->duplicate,
            'model' => $this->model,
            'fallback_used' => $this->fallbackUsed,
            'property_id' => $this->propertyId,
            'file_path' => $this->filePath,
            'file_name' => $this->fileName,
        ];
    }

    /** @param array<string, mixed> $state */
    public static function fromArray(array $state): self
    {
        return new self(
            documentType: (string) ($state['document_type'] ?? 'unknown'),
            confidence: (float) ($state['confidence'] ?? 0),
            values: (array) ($state['values'] ?? []),
            confidences: (array) ($state['confidences'] ?? []),
            evidence: (array) ($state['evidence'] ?? []),
            warnings: (array) ($state['warnings'] ?? []),
            rows: (array) ($state['rows'] ?? []),
            duplicate: $state['duplicate'] ?? null,
            model: (string) ($state['model'] ?? ''),
            fallbackUsed: (bool) ($state['fallback_used'] ?? false),
            propertyId: isset($state['property_id']) ? (int) $state['property_id'] : null,
            filePath: $state['file_path'] ?? null,
            fileName: $state['file_name'] ?? null,
        );
    }
}
