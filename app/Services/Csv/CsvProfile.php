<?php

namespace App\Services\Csv;

use App\Models\Expense;
use App\Models\Furniture;
use App\Models\Income;
use App\Models\PropertyWork;

/**
 * Ce qu'une colonne de CSV peut s'appeler, cible par cible.
 *
 * Généralise `AirbnbImportService::COLUMN_MAP`, qui ne connaissait qu'Airbnb et ne
 * savait remplir que des recettes. Le mappage automatique n'est ici qu'une PROPOSITION :
 * l'écran d'import la montre et l'utilisateur la corrige. Un mappage deviné et appliqué
 * en silence est ce qui transforme un tableur de cabinet en comptabilité fausse.
 */
class CsvProfile
{
    public const TARGET_INCOME    = 'income';
    public const TARGET_EXPENSE   = 'expense';
    public const TARGET_FURNITURE = 'furniture';
    public const TARGET_WORK      = 'work';

    /** @return array<string, string> */
    public static function targetLabels(): array
    {
        return [
            self::TARGET_INCOME    => 'Recettes (loyers)',
            self::TARGET_EXPENSE   => 'Charges',
            self::TARGET_FURNITURE => 'Mobilier',
            self::TARGET_WORK      => 'Travaux',
        ];
    }

    /**
     * Champs attendus par cible : libellé français, obligatoire ou non, type.
     *
     * @return array<string, array{label: string, required: bool, type: string}>
     */
    public static function fields(string $target): array
    {
        return match ($target) {
            self::TARGET_INCOME => [
                'date'            => ['label' => 'Date de la recette',      'required' => true,  'type' => 'date'],
                'amount'          => ['label' => 'Montant brut',            'required' => true,  'type' => 'money'],
                'platform_fee'    => ['label' => 'Commission plateforme',   'required' => false, 'type' => 'money'],
                'tourist_tax'     => ['label' => 'Taxe de séjour',          'required' => false, 'type' => 'money'],
                'reservation_ref' => ['label' => 'Référence de réservation', 'required' => false, 'type' => 'text'],
                'guest_name'      => ['label' => 'Nom du voyageur',         'required' => false, 'type' => 'text'],
                'checkin_date'    => ['label' => 'Début du séjour',         'required' => false, 'type' => 'date'],
                'checkout_date'   => ['label' => 'Fin du séjour',           'required' => false, 'type' => 'date'],
                'notes'           => ['label' => 'Notes',                   'required' => false, 'type' => 'text'],
            ],
            self::TARGET_EXPENSE => [
                'date'        => ['label' => 'Date de la charge', 'required' => true,  'type' => 'date'],
                'amount'      => ['label' => 'Montant TTC',       'required' => true,  'type' => 'money'],
                'description' => ['label' => 'Libellé',           'required' => false, 'type' => 'text'],
                'category'    => ['label' => 'Catégorie',         'required' => false, 'type' => 'text'],
                'tva_rate'    => ['label' => 'Taux de TVA',       'required' => false, 'type' => 'integer'],
                'notes'       => ['label' => 'Notes',             'required' => false, 'type' => 'text'],
            ],
            self::TARGET_FURNITURE => [
                'description'    => ['label' => 'Désignation',              'required' => true,  'type' => 'text'],
                'amount'         => ['label' => 'Montant TTC',              'required' => true,  'type' => 'money'],
                'date'           => ['label' => 'Date d\'achat',            'required' => true,  'type' => 'date'],
                'duration_years' => ['label' => 'Durée d\'amortissement',   'required' => false, 'type' => 'integer'],
                'is_second_hand' => ['label' => 'Acheté d\'occasion',       'required' => false, 'type' => 'boolean'],
                'tva_rate'       => ['label' => 'Taux de TVA',              'required' => false, 'type' => 'integer'],
            ],
            self::TARGET_WORK => [
                'description'    => ['label' => 'Désignation',            'required' => true,  'type' => 'text'],
                'amount'         => ['label' => 'Montant TTC',            'required' => true,  'type' => 'money'],
                'date'           => ['label' => 'Date des travaux',       'required' => true,  'type' => 'date'],
                'duration_years' => ['label' => 'Durée d\'amortissement', 'required' => false, 'type' => 'integer'],
                'tva_rate'       => ['label' => 'Taux de TVA',            'required' => false, 'type' => 'integer'],
            ],
            default => throw new \InvalidArgumentException("Cible d'import inconnue : {$target}"),
        };
    }

    /**
     * Intitulés de colonne reconnus, par champ.
     *
     * ⚠️ La comparaison est faite SANS accents ni casse (voir `normalize()`) : un
     * tableur exporte « Libellé » ou « LIBELLE » selon l'humeur du logiciel, et une
     * comparaison stricte faisait retomber la colonne en « non mappée » sans rien dire.
     *
     * @return array<string, list<string>>
     */
    public static function aliases(string $target): array
    {
        $common = [
            'date'   => ['date', 'date operation', 'date de l\'operation', 'jour'],
            'amount' => ['montant', 'montant ttc', 'montant brut', 'amount', 'prix', 'total', 'total ttc', 'debit', 'credit'],
            'tva_rate' => ['tva', 'taux de tva', 'taux tva', 'vat', 'vat rate'],
            'notes'  => ['notes', 'note', 'commentaire', 'commentaires', 'remarque'],
        ];

        return match ($target) {
            self::TARGET_INCOME => array_merge($common, [
                'date'            => ['date', 'date du paiement', 'payout date', 'date de versement', 'date de la recette', 'date d\'encaissement'],
                'amount'          => ['montant', 'montant brut', 'amount', 'gross earnings', 'revenus', 'loyer', 'total'],
                'platform_fee'    => ['host fee', 'frais de service hote', 'commission', 'commission plateforme', 'service fee', 'frais'],
                'tourist_tax'     => ['taxe de sejour', 'tourist tax', 'taxe sejour'],
                'reservation_ref' => ['code de confirmation', 'confirmation code', 'confirmation', 'reference', 'reference de reservation', 'booking id', 'numero de reservation'],
                'guest_name'      => ['voyageur', 'nom du voyageur', 'guest', 'guest name', 'client', 'locataire'],
                'checkin_date'    => ['date de debut', 'debut du sejour', 'check-in', 'checkin', 'arrivee', 'start date'],
                'checkout_date'   => ['date de fin', 'fin du sejour', 'check-out', 'checkout', 'depart', 'end date'],
            ]),
            self::TARGET_EXPENSE => array_merge($common, [
                'date'        => ['date', 'date de la charge', 'date de facture', 'date facture', 'date operation'],
                'description' => ['libelle', 'description', 'designation', 'intitule', 'objet', 'fournisseur', 'label'],
                'category'    => ['categorie', 'category', 'type', 'nature', 'poste', 'compte'],
            ]),
            self::TARGET_FURNITURE => array_merge($common, [
                'date'           => ['date', 'date d\'achat', 'date achat', 'date de facture', 'mise en service'],
                'description'    => ['designation', 'description', 'libelle', 'intitule', 'bien', 'article', 'meuble'],
                'duration_years' => ['duree', 'duree d\'amortissement', 'duree amortissement', 'amortissement', 'annees', 'duration'],
                'is_second_hand' => ['occasion', 'd\'occasion', 'seconde main', 'second hand', 'used'],
            ]),
            self::TARGET_WORK => array_merge($common, [
                'date'           => ['date', 'date des travaux', 'date de facture', 'date facture', 'mise en service'],
                'description'    => ['designation', 'description', 'libelle', 'intitule', 'nature des travaux', 'travaux'],
                'duration_years' => ['duree', 'duree d\'amortissement', 'duree amortissement', 'amortissement', 'annees', 'duration'],
            ]),
            default => $common,
        };
    }

    /**
     * Propose un mappage colonne du fichier → champ, d'après les intitulés.
     *
     * Le premier alias trouvé gagne, et une colonne déjà attribuée n'est pas réutilisée :
     * sans cette garde, « Montant » et « Montant TTC » se disputaient le même champ et le
     * résultat dépendait de l'ordre des colonnes dans le fichier.
     *
     * @param  list<string>  $header
     * @return array<string, int> champ => index de colonne
     */
    public static function guessMapping(string $target, array $header): array
    {
        $normalized = array_map(fn ($h) => self::normalize($h), $header);
        $mapping = [];
        $taken = [];

        foreach (self::aliases($target) as $field => $variants) {
            foreach ($variants as $variant) {
                $index = array_search(self::normalize($variant), $normalized, true);

                if ($index !== false && ! in_array($index, $taken, true)) {
                    $mapping[$field] = $index;
                    $taken[] = $index;
                    break;
                }
            }
        }

        return $mapping;
    }

    /** Minuscules, sans accents ni ponctuation superflue — pour comparer des intitulés. */
    public static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e',
            'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u',
            'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'œ' => 'oe',
        ]);

        return trim(preg_replace('/\s+/', ' ', $value));
    }

    /**
     * Reconnaît une catégorie de charge écrite en clair.
     *
     * Le tableur d'un cabinet porte « Taxe foncière », pas `property_tax`. Une catégorie
     * non reconnue tombe en « Divers » plutôt que d'être refusée : perdre la ligne
     * coûterait plus cher que de la reclasser à la main ensuite.
     */
    public static function expenseCategory(?string $raw): string
    {
        if ($raw === null || trim($raw) === '') {
            return Expense::CATEGORY_OTHER;
        }

        $needle = self::normalize($raw);

        // Une clé technique passée telle quelle (export OpenLMNP vers OpenLMNP).
        if (array_key_exists($needle, Expense::categoryLabels())) {
            return $needle;
        }

        foreach (Expense::categoryLabels() as $key => $label) {
            // Les libellés portent un emoji : on ne compare que la partie textuelle.
            $text = self::normalize(preg_replace('/[^\p{L}\p{N}\s\/\(\),.-]/u', '', $label));

            if ($needle === $text || str_contains($text, $needle) || str_contains($needle, $text)) {
                return $key;
            }
        }

        return match (true) {
            str_contains($needle, 'fonciere'), str_contains($needle, 'impot') => Expense::CATEGORY_PROPERTY_TAX,
            str_contains($needle, 'assur')                                    => Expense::CATEGORY_INSURANCE,
            str_contains($needle, 'elec'), str_contains($needle, 'gaz'),
            str_contains($needle, 'eau'), str_contains($needle, 'energie')    => Expense::CATEGORY_ENERGY,
            str_contains($needle, 'entretien'), str_contains($needle, 'repar') => Expense::CATEGORY_MAINTENANCE,
            str_contains($needle, 'menage'), str_contains($needle, 'nettoyage') => Expense::CATEGORY_CLEANING,
            str_contains($needle, 'commission'), str_contains($needle, 'airbnb') => Expense::CATEGORY_PLATFORM,
            str_contains($needle, 'compta'), str_contains($needle, 'expert')  => Expense::CATEGORY_ACCOUNTING,
            str_contains($needle, 'internet'), str_contains($needle, 'telephone'),
            str_contains($needle, 'box')                                      => Expense::CATEGORY_TELECOM,
            str_contains($needle, 'deplacement'), str_contains($needle, 'trajet') => Expense::CATEGORY_TRAVEL,
            default => Expense::CATEGORY_OTHER,
        };
    }

    /** Durée d'amortissement par défaut d'une cible amortissable. */
    public static function defaultDuration(string $target): int
    {
        return match ($target) {
            self::TARGET_FURNITURE => Furniture::DURATION_NEW,
            self::TARGET_WORK      => 10,
            default                => 0,
        };
    }

    /** Modèle Eloquent visé. */
    public static function modelClass(string $target): string
    {
        return match ($target) {
            self::TARGET_INCOME    => Income::class,
            self::TARGET_EXPENSE   => Expense::class,
            self::TARGET_FURNITURE => Furniture::class,
            self::TARGET_WORK      => PropertyWork::class,
            default => throw new \InvalidArgumentException("Cible d'import inconnue : {$target}"),
        };
    }
}
