<?php

namespace App\Support;

use App\Models\User;

/**
 * Dossier tenu au nom d'une indivision.
 *
 * Une indivision qui loue en meublé dépose UNE liasse à son nom, et son résultat est réparti
 * entre indivisaires sur l'annexe 2031 bis-SD, cadre E (« Répartition des bénéfices et des
 * déficits des sociétés » — l'indivision y figure au titre des sociétés créées de fait). Chaque
 * indivisaire reporte ensuite sa quote-part sur sa propre 2042-C-PRO.
 *
 * Les charges, amortissements et emprunts se saisissent pour leur montant TOTAL : la clé de
 * répartition ne s'applique qu'au résultat. La lui appliquer aussi en amont la compterait deux
 * fois (même piège que la quote-part locative, cf. `Property::quota_share`).
 *
 * Les quotes-parts sont en points de base (10 000 = 100 %) : la répartition reste en entiers,
 * et la somme des parts réparties retombe exactement sur le résultat.
 *
 * ⚠️ Micro-BIC : simplification du 2026-10-15 (piège fiscal P13). Une indivision gérée en
 * commun peut former une société de fait, exclue du micro-BIC (art. 50-0, 2-c du CGI) selon
 * le Conseil d'État (14/11/2025, n° 495516) — mais la question posée pour le savoir
 * (« Chaque indivisaire participe-t-il à la gestion ? ») s'est avérée trop incertaine pour être
 * tranchée correctement par le logiciel : la réponse dépend de faits (qui signe les baux, qui
 * décide des travaux…) qu'aucun champ ne peut capturer sans se tromper. Le micro-BIC d'une
 * indivision suit donc désormais les **mêmes règles que celui d'un dossier individuel**,
 * partout dans l'application — ni exclusion, ni mise en garde spécifique. Ne jamais revenir à
 * « une indivision est au réel de plein droit » : c'était, et ça reste, faux sans condition.
 */
final class Indivision
{
    public const SHARE_TOTAL = 10000;

    public static function isIndivision(?User $user): bool
    {
        return (bool) $user?->is_indivision;
    }

    /**
     * Indivisaires du dossier, normalisés.
     *
     * @return list<array{name: string, address: ?string, birth: ?string, tax_id: ?string, share_bp: int}>
     */
    public static function coOwners(User $user): array
    {
        return collect($user->co_owners ?? [])
            ->filter(fn ($row) => is_array($row) && filled($row['name'] ?? null))
            ->map(fn (array $row) => [
                'name' => trim((string) $row['name']),
                'address' => filled($row['address'] ?? null) ? trim((string) $row['address']) : null,
                'birth' => filled($row['birth'] ?? null) ? trim((string) $row['birth']) : null,
                'tax_id' => filled($row['tax_id'] ?? null) ? preg_replace('/\s+/', '', (string) $row['tax_id']) : null,
                'share_bp' => (int) ($row['share_bp'] ?? 0),
            ])
            ->values()
            ->all();
    }

    public static function totalShare(array $coOwners): int
    {
        return array_sum(array_column($coOwners, 'share_bp'));
    }

    /**
     * Répartition exploitable : au moins deux indivisaires, parts positives, total de 100 %.
     */
    public static function isComplete(array $coOwners): bool
    {
        if (count($coOwners) < 2) {
            return false;
        }

        foreach ($coOwners as $row) {
            if ($row['share_bp'] <= 0) {
                return false;
            }
        }

        return self::totalShare($coOwners) === self::SHARE_TOTAL;
    }

    /**
     * Répartit un montant en centimes (bénéfice positif, déficit négatif) selon les parts.
     *
     * Chaque part est tronquée vers zéro ; l'écart d'arrondi (quelques centimes au plus) va au
     * dernier indivisaire, pour que la somme des quotes-parts égale le résultat de la liasse.
     *
     * @param  list<array{share_bp: int}>  $coOwners
     * @return list<int>
     */
    public static function split(int $amountCents, array $coOwners): array
    {
        if ($coOwners === []) {
            return [];
        }

        $parts = [];
        foreach ($coOwners as $row) {
            $parts[] = (int) bcdiv(bcmul((string) $amountCents, (string) $row['share_bp'], 0), (string) self::SHARE_TOTAL, 0);
        }

        $parts[count($parts) - 1] += $amountCents - array_sum($parts);

        return $parts;
    }

    /**
     * Saisie en pourcentage (« 50 », « 33,33 ») → points de base. `null` si illisible.
     */
    public static function percentToBasisPoints(mixed $percent): ?int
    {
        $value = str_replace([',', ' ', '%'], ['.', '', ''], trim((string) $percent));

        if (! preg_match('/^\d{1,3}(\.\d{1,2})?$/', $value)) {
            return null;
        }

        return (int) bcmul($value, '100', 0);
    }

    public static function basisPointsToPercent(int $basisPoints): string
    {
        return rtrim(rtrim(number_format($basisPoints / 100, 2, ',', ''), '0'), ',');
    }
}
