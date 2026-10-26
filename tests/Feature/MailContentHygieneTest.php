<?php

use App\Models\Feedback;
use App\Models\User;
use App\Notifications\AccountInvitation;
use App\Notifications\DemoExpiring;
use App\Notifications\DemoResumeLink;
use App\Notifications\FeedbackReceived;
use App\Notifications\LinkedDossierInvitation;
use App\Notifications\WelcomeSetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Le texte des courriels passe par le même nettoyage que tout contenu rédigé (demande de Jérémie
 * du 2026-10-15) : ni caractère invisible, filigrane typique d'un texte généré, ni tiret
 * cadratin, ni points de suspension typographiques, ni émoji d'avertissement. On vérifie le
 * courriel RENDU, sujet compris, et non les sources : les commentaires du code ne partent pas.
 */
const FORBIDDEN_IN_MAIL = '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}\x{00AD}\x{2014}\x{2212}\x{2026}\x{26A0}\x{FE0F}]/u';

function renderedMail(MailMessage $mail): string
{
    // Le thème CSS est injecté en ligne par le rendu : on ne garde que le texte visible.
    $html = (string) $mail->render();
    $text = html_entity_decode(strip_tags(preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return $mail->subject."\n".$text;
}

/** @return array<string, Closure(): MailMessage> */
function allMails(): array
{
    $user = User::factory()->create(['name' => 'Claire Martin']);
    // Construit en mémoire : seul le rendu du courriel compte ici.
    $feedback = (new Feedback)->forceFill([
        'user_id' => $user->id,
        'sentiment' => 'negative',
        'message' => 'Il manque la 2031 bis.',
        'author_name' => 'Claire',
        'author_email' => 'claire@example.test',
        'audience' => Feedback::AUDIENCE_DEMO,
        'can_publish' => false,
        'variant' => 'modal',
        'trigger' => Feedback::TRIGGER_SESSION,
    ]);

    return [
        'invitation au compte' => fn () => (new AccountInvitation('https://beta.example.test/invitation/x', now()->addDays(14), 'Jérémie'))->toMail($user),
        'fin de démonstration' => fn () => (new DemoExpiring('https://beta.example.test/demo/reprendre'))->toMail($user),
        'lien de reprise de démonstration' => fn () => (new DemoResumeLink('https://beta.example.test/demo/lien'))->toMail($user),
        'retour d\'avis' => fn () => (new FeedbackReceived($feedback))->toMail($user),
        'invitation à un dossier lié' => fn () => (new LinkedDossierInvitation('https://beta.example.test/dossiers/invitation/x', now()->addDays(14), 'Jérémie', 'Indivision Martin-Durand', '123456789'))->toMail($user),
        'bienvenue' => fn () => (new WelcomeSetPassword('https://beta.example.test/bienvenue'))->toMail($user),
    ];
}

it('sends every mail free of invisible characters and AI typography', function () {
    foreach (allMails() as $name => $build) {
        $text = renderedMail($build());

        preg_match_all(FORBIDDEN_IN_MAIL, $text, $found);
        $codes = array_map(fn ($c) => sprintf('U+%04X', mb_ord($c)), array_unique($found[0]));

        expect($codes)->toBe([], "Courriel « {$name} » : caractères interdits ".implode(', ', $codes));
    }
});

it('catches a forbidden character when one slips into a mail', function () {
    $mail = (new MailMessage)->subject('OpenLMNP — essai')->line('Texte');

    expect(preg_match(FORBIDDEN_IN_MAIL, renderedMail($mail)))->toBe(1);
});
