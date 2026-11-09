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

    /**
     * Intitulés de colonne qui ne laissent guère de doute sur la nature d'un fichier.
     *
     * ⚠️ Rien à voir avec `aliases()`, et c'est délibéré. Là-bas, « Montant » désigne le
     * champ *montant* pour les quatre cibles ; ici on ne retient que ce qui n'existe QUE
     * dans un export de cette nature-là. « Code de confirmation » ne figure pas dans un
     * inventaire de mobilier ; « Acheté d'occasion » ne figure pas dans un relevé de
     * loyers. Un intitulé partagé — date, montant, libellé, catégorie — ne prouve rien et
     * n'a pas sa place dans cette liste.
     *
     * ⚠️ Cette liste se périmera : un nouvel export inventera des intitulés qu'elle
     * ignore. C'est pourquoi elle ne sert QU'À POSER UNE QUESTION (`suspectedTarget()`),
     * jamais à décider. Une liste d'intitulés qui imposerait une nature finirait par
     * imposer la fausse, en silence — et se tromper de nature inverse le signe du
     * résultat fiscal.
     *
     * @return array<string, list<string>>
     */
    public static function signatures(): array
    {
        return [
            self::TARGET_INCOME => [
                'code de confirmation', 'confirmation code', 'reference de reservation',
                'numero de reservation', 'booking id', 'gross earnings', 'payout date',
                'date de versement', 'voyageur', 'nom du voyageur', 'guest', 'guest name',
                'taxe de sejour', 'tourist tax', 'host fee', 'frais de service hote',
                'debut du sejour', 'fin du sejour', 'check-in', 'check-out', 'nuits', 'nights',
                'loyer', 'loyers', 'date d\'encaissement', 'date de la recette',
            ],
            self::TARGET_EXPENSE => [
                'date de la charge', 'fournisseur', 'taxe fonciere', 'charge', 'charges',
                'depense', 'depenses', 'facture fournisseur',
            ],
            self::TARGET_FURNITURE => [
                'occasion', 'd\'occasion', 'seconde main', 'second hand', 'used',
                'meuble', 'meubles', 'mobilier', 'equipement', 'equipements',
            ],
            self::TARGET_WORK => [
                'nature des travaux', 'travaux', 'chantier', 'devis travaux',
            ],
        ];
    }

    /**
     * Nature que les intitulés du fichier suggèrent, si elle contredit celle choisie.
     *
     * Rend `null` dès qu'il y a le moindre doute — c'est le comportement recherché. Le
     * seuil de DEUX intitulés distincts n'est pas décoratif : « Travaux » peut figurer
     * comme catégorie dans un tableau de charges, « Loyer » comme libellé dans un relevé
     * bancaire. Un seul indice ne vaut rien ; deux, sur une cible autre que celle choisie,
     * et mieux notés qu'elle, valent une question.
     *
     * ⚠️ Cette question est le second filet, pas le premier. Le premier est la
     * PRÉSÉLECTION, qui vient de l'écran d'où l'on arrive et ne devine rien. Deviner la
     * nature d'après le contenu du fichier serait exactement ce que l'import CSV refuse
     * de faire pour le mappage des colonnes, et pour la même raison.
     *
     * @param  list<string>  $header
     */
    public static function suspectedTarget(string $chosen, array $header): ?string
    {
        $normalized = array_map(fn ($column) => self::normalize($column), $header);
        $scores = [];

        foreach (self::signatures() as $target => $markers) {
            $scores[$target] = count(array_unique(array_intersect(
                array_map(fn ($marker) => self::normalize($marker), $markers),
                $normalized,
            )));
        }

        arsort($scores);
        $best = array_key_first($scores);

        if ($best === $chosen || $scores[$best] < 2) {
            return null;
        }

        return $scores[$best] > ($scores[$chosen] ?? 0) ? $best : null;
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
