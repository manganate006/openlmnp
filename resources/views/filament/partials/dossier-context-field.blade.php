{{--
    Vieil onglet (App\Support\DossierContext, App\Http\Middleware\VerifyDossierContext) : champ
    caché OBLIGATOIRE dans tout formulaire POST classique qui vise une route du panel (bascule
    vers un dossier, retour au principal). Sans lui, le middleware répond 419 à chaque envoi,
    y compris dans un seul onglet : c'est ce qui cassait « Ouvrir le dossier » de la page
    Dossiers liés (D1 du test bêta du 2026-10-15).

    Un onglet resté ouvert depuis AVANT une bascule faite dans un autre onglet soumet ici un
    identifiant périmé : 419, « cette page a expiré », exactement comme pour Livewire.

    Un seul partiel pour tous les formulaires, vérifié par
    tests/Feature/Dossiers/DossierContextFormsTest.php : tout `<form method="POST">` d'une vue
    qui vise `route('filament.admin.…')` doit l'inclure.
--}}
<input type="hidden" name="{{ \App\Http\Middleware\VerifyDossierContext::INPUT }}" value="{{ \App\Support\DossierContext::contextId() }}">
