{{--
    Gabarit de l'invitation à un dossier lié (écran 7, variante B). Copie délibérée
    du gabarit markdown par défaut de Laravel (`vendor/laravel/framework/.../
    notifications/resources/views/email.blade.php`), avec un SEUL ajout : l'encadré
    (`<x-mail::panel>`) qui nomme le dossier, son SIREN et l'expéditeur - ce que la
    maquette appelle « le courriel nomme l'expéditeur, le dossier et le SIREN ».

    Reparcourt `$introLines`/`$outroLines`/`$actionText`/`$actionUrl`/`$greeting`/
    `$salutation` À L'IDENTIQUE du gabarit par défaut : ce sont les mêmes propriétés
    que `MailMessage::line()`/`->action()` peuplent dans App\Notifications\
    LinkedDossierInvitation::toMail(), donc les tests qui les lisent directement sur
    l'objet `$mail` restent valables sans adaptation.
--}}
<x-mail::message>
# {{ $greeting }}

<x-mail::panel>
**{{ $dossierName }}**
SIREN {{ $siren }}
Invitation envoyée par **{{ $invitedByName }}**
</x-mail::panel>

@foreach ($introLines as $line)
{{ $line }}

@endforeach

@isset($actionText)
<?php
    $color = match ($level ?? null) {
        'success', 'error' => $level,
        default => 'success',
    };
?>
<x-mail::button :url="$actionUrl" :color="$color">
{{ $actionText }}
</x-mail::button>
@endisset

@foreach ($outroLines as $line)
{{ $line }}

@endforeach

@if (! empty($salutation))
{{ $salutation }}
@else
@lang('Regards,')<br>
{{ config('app.name') }}
@endif

@isset($actionText)
<x-slot:subcopy>
@lang(
    "If you're having trouble clicking the \":actionText\" button, copy and paste the URL below\n".
    'into your web browser:',
    [
        'actionText' => $actionText,
    ]
) <span class="break-all">[{{ $displayableActionUrl ?? $actionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
@endisset
</x-mail::message>
