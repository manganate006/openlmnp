<?php

namespace App\Services\Ai\Assistant;

use App\Models\Expense;
use App\Models\Furniture;
use App\Models\Income;
use App\Models\Loan;
use App\Models\Property;
use App\Models\PropertyWork;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Masque une conversation de l'assistant avant qu'elle nous soit transmise.
 *
 * Deux passes :
 * 1. les valeurs CONNUES du compte, celles que les outils de l'assistant peuvent renvoyer
 *    (nom et e-mail, noms et adresses des biens, voyageurs, banques, libellés de charges,
 *    de mobilier et de travaux), lues par les mêmes requêtes que les outils (scopes de
 *    l'utilisateur connecté) ;
 * 2. des motifs génériques pour ce que la première passe ne connaît pas : montants,
 *    e-mails, téléphones, IBAN, SIREN/SIRET, adresses web.
 *
 * Seul le texte AFFICHÉ est gardé (questions et réponses) : jamais un résultat d'outil.
 */
final class ConversationRedactor
{
    public const MAX_MESSAGES = 40;

    public const MAX_CHARS = 20000;

    /**
     * @param  array<int, array<string, mixed>>  $messages  messages affichés (`role`, `text`)
     * @return array<int, array{role: string, text: string}>
     */
    public function redact(User $user, array $messages): array
    {
        $known = $this->knownValues($user);
        $kept = [];
        $total = 0;

        foreach (array_slice($messages, -self::MAX_MESSAGES) as $message) {
            $role = ($message['role'] ?? null) === 'assistant' ? 'assistant' : 'user';
            $text = $this->redactText((string) ($message['text'] ?? ''), $known);
            $text = Str::limit($text, max(0, self::MAX_CHARS - $total), ' […]');
            $total += mb_strlen($text);
            $kept[] = ['role' => $role, 'text' => $text];

            if ($total >= self::MAX_CHARS) {
                break;
            }
        }

        return $kept;
    }

    /**
     * @param  array<string, string>  $known  valeur => étiquette
     */
    public function redactText(string $text, array $known): string
    {
        // Les plus longues d'abord : « 12 rue des Lilas » avant « Lilas ».
        foreach ($known as $value => $label) {
            $text = preg_replace('/'.$this->loosePattern((string) $value).'/iu', $label, $text) ?? $text;
        }

        $patterns = [
            '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu' => '[e-mail]',
            '/\bhttps?:\/\/\S+/iu' => '[lien]',
            '/\bFR\d{2}(?:[ ]?[A-Z0-9]{4}){5}[ ]?[A-Z0-9]{3}\b/iu' => '[IBAN]',
            '/(?<!\d)(?:\+33\s?|0)[1-9](?:[\s.\-]?\d{2}){4}(?!\d)/u' => '[téléphone]',
            '/(?<!\d)\d{3}[\s\x{202F}\x{00A0}]?\d{3}[\s\x{202F}\x{00A0}]?\d{3}(?:[\s\x{202F}\x{00A0}]?\d{5})?(?!\d)/u' => '[SIREN]',
            // Montants : « 1 234,56 € », « 800 € », « 12,5 EUR », « 1 000,00 » sans symbole.
            '/-?\d{1,3}(?:[\s\x{202F}\x{00A0}.]\d{3})*(?:,\d+)?\s?(?:€|EUR\b|euros?\b)/iu' => '[montant]',
            '/-?\d+(?:,\d+)?\s?(?:€|EUR\b|euros?\b)/iu' => '[montant]',
            '/(?<![\d\/])\d{1,3}(?:[\s\x{202F}\x{00A0}.]\d{3})*,\d{2}(?![\d\/])/u' => '[montant]',
        ];

        foreach ($patterns as $pattern => $label) {
            $text = preg_replace($pattern, $label, $text) ?? $text;
        }

        return $text;
    }

    /**
     * Valeurs propres au compte, triées de la plus longue à la plus courte.
     *
     * @return array<string, string>
     */
    public function knownValues(User $user): array
    {
        $values = [];
        $add = function (?string $value, string $label) use (&$values): void {
            $value = trim((string) $value);

            // Trop court, un « Lyon » ou un « T2 » masquerait des mots courants partout.
            if (mb_strlen($value) >= 4 && ! isset($values[$value])) {
                $values[$value] = $label;
            }
        };

        $add($user->name, '[nom]');
        $add($user->email, '[e-mail]');

        foreach (Property::query()->get(['name', 'address', 'city', 'postal_code']) as $property) {
            $add($property->name, '[bien]');
            $add($property->address, '[adresse]');
            $add($property->city, '[ville]');
            $add($property->postal_code, '[code postal]');
        }

        foreach (Income::query()->whereNotNull('guest_name')->distinct()->pluck('guest_name') as $guest) {
            $add($guest, '[voyageur]');
        }

        foreach (Loan::query()->pluck('bank_name') as $bank) {
            $add($bank, '[banque]');
        }

        foreach ([Expense::class, Furniture::class, PropertyWork::class] as $model) {
            foreach ($model::query()->distinct()->pluck('description') as $description) {
                $add($description, '[libellé]');
            }
        }

        uksort($values, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        return $values;
    }

    /** La valeur, échappée, tolérante aux espaces multiples et insécables. */
    private function loosePattern(string $value): string
    {
        $parts = preg_split('/\s+/u', $value) ?: [$value];

        return implode('[\s\x{202F}\x{00A0}]+', array_map(fn ($part) => preg_quote($part, '/'), $parts));
    }
}
