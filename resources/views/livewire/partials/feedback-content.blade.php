{{--
    Contenu de l'invitation à donner son avis — commun aux trois mises en forme du test.

    Seul l'habillage (modale centrée / bandeau bas / carte flottante) diffère d'une
    variante à l'autre : le texte, l'ordre des champs et les gestes proposés sont
    strictement identiques, faute de quoi le test comparerait deux choses à la fois.
--}}

@if ($step === 'ask')
    <div class="fb-head">
        <div>
            <p class="fb-title">Que pensez-vous d'OpenLMNP ?</p>
            <p class="fb-subtitle">Deux clics, et vous nous aidez beaucoup.</p>
        </div>
        <button type="button" class="fb-close" aria-label="Fermer" wire:click="dismiss">
            <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" width="16" height="16" aria-hidden="true">
                <path d="M5 5l10 10M15 5L5 15" stroke-linecap="round" />
            </svg>
        </button>
    </div>

    <div class="fb-choices">
        <button type="button" class="fb-choice" wire:click="choose('positive')">👍 J'aime bien</button>
        <button type="button" class="fb-choice" wire:click="choose('negative')">👎 Pas convaincu</button>
    </div>

    <div class="fb-later-row">
        <button type="button" class="fb-later" wire:click="dismiss">Plus tard</button>
    </div>
@endif

@if ($step === 'positive')
    <div class="fb-head">
        <div>
            @if ($this->isDemo)
                {{-- En démonstration, la personne n'est pas encore utilisatrice : lui demander
                     de soutenir le projet avant même qu'elle l'ait adopté serait à l'envers. --}}
                <p class="fb-title">Merci ! Une dernière chose</p>
                <p class="fb-subtitle">Cette démonstration s'efface dans {{ $this->demoDelay }}.</p>
            @else
                <p class="fb-title">Merci ! Voici comment nous aider</p>
                <p class="fb-subtitle">OpenLMNP est développé sur du temps libre. Trois gestes qui changent tout :</p>
            @endif
        </div>
        <button type="button" class="fb-close" aria-label="Fermer" wire:click="dismiss">
            <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" width="16" height="16" aria-hidden="true">
                <path d="M5 5l10 10M15 5L5 15" stroke-linecap="round" />
            </svg>
        </button>
    </div>

    <div class="fb-body">
        @if ($this->showsKeepSandbox)
            {{--
                Le seul geste utile à 4 minutes : à ce stade le parcours d'expiration n'a
                encore rien affiché (ses paliers se comptent en HEURES restantes), et c'est
                donc ici que se joue tout le contact avec un prospect de session courte.

                Rien n'est réimplémenté : le bouton passe la main à `DemoExpiryPrompt`, qui
                porte déjà la validation, le consentement, la prolongation et l'envoi du
                lien de reprise.
            --}}
            <button type="button" class="fb-cta-pro" wire:click="keepSandbox">
                <span class="fb-cta-pro-text">
                    <span class="fb-cta-pro-title">Gardez votre travail 7 jours</span>
                    <span class="fb-cta-pro-sub">Laissez votre adresse : on vous renvoie le lien pour revenir, depuis n'importe quel appareil</span>
                </span>
                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" width="16" height="16" aria-hidden="true">
                    <path d="M7 4l6 6-6 6" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </button>
        @endif

        @if ($this->showsProCta)
            {{--
                Nouvel onglet : le bac à sable reste vivant derrière. Quelqu'un qui va lire les
                tarifs et revient retrouve ses saisies — ce qui compte d'autant plus tant que la
                reprise de données à la conversion n'existe pas.

                L'événement part du NAVIGATEUR, pas de PHP : une action Livewire courrait contre
                le traitement GTM. `$dispatch` émet sur l'élément, l'événement remonte à `window`
                où `partials/gtm-head` l'écoute déjà.

                ⚠️ Aucun tarif ici : un prix en dur dans un dépôt public est du contenu
                commercial, et une valeur qui se périme. Il vit sur le site.
            --}}
            <a class="fb-cta-pro" href="{{ config('feedback.links.pro') }}"
               target="_blank" rel="noopener noreferrer"
               x-on:click="$dispatch('analytics', { event: 'feedback_cta_pro', feedback_variant: @js($variant) })">
                <span class="fb-cta-pro-text">
                    <span class="fb-cta-pro-title">Gardez tout ça avec Cloud Pro</span>
                    <span class="fb-cta-pro-sub">Vos biens, vos justificatifs et vos exercices conservés, sauvegardés et accessibles partout</span>
                </span>
                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" width="16" height="16" aria-hidden="true">
                    <path d="M7 4l6 6-6 6" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </a>

            <p class="fb-separator">Ou, si vous préférez l'héberger vous-même</p>
        @endif

        {{--
            ⚠️ Jamais en démonstration. Un visiteur qui découvre le produit depuis quatre
            minutes n'est pas un contributeur : lui proposer une étoile, un parrainage et les
            Discussions, c'est ouvrir trois portes qui ne le concernent pas et diluer la seule
            qui compte. Ces gestes gardent tout leur sens pour un compte réel, souvent
            auto-hébergé — d'où la condition, et non la suppression.
        --}}
        @unless ($this->isDemo)
        <div class="fb-links">
            <a class="fb-link" href="{{ config('feedback.links.star') }}" target="_blank" rel="noopener noreferrer">
                <span class="fb-link-icon">
                    <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16" aria-hidden="true">
                        <path d="M10 2l2.4 4.9 5.4.8-3.9 3.8.9 5.4-4.8-2.5-4.8 2.5.9-5.4L2.2 7.7l5.4-.8L10 2z" />
                    </svg>
                </span>
                <span class="fb-link-text">
                    <span class="fb-link-title">Mettre une étoile sur GitHub</span>
                    <span class="fb-link-sub">Ça aide d'autres loueurs meublés à découvrir le projet</span>
                </span>
            </a>

            <a class="fb-link" href="{{ config('feedback.links.sponsor') }}" target="_blank" rel="noopener noreferrer">
                <span class="fb-link-icon fb-link-icon-pink">
                    <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16" aria-hidden="true">
                        <path d="M10 17s-6.3-4-6.3-8A3.4 3.4 0 0110 7a3.4 3.4 0 016.3 2c0 4-6.3 8-6.3 8z" />
                    </svg>
                </span>
                <span class="fb-link-text">
                    <span class="fb-link-title">Devenir sponsor</span>
                    <span class="fb-link-sub">Quelques euros par mois financent le temps de développement</span>
                </span>
            </a>

            <a class="fb-link" href="{{ config('feedback.links.discussions') }}" target="_blank" rel="noopener noreferrer">
                <span class="fb-link-icon fb-link-icon-info">
                    <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16" aria-hidden="true">
                        <path d="M3 5.5A2.5 2.5 0 015.5 3h9A2.5 2.5 0 0117 5.5v6a2.5 2.5 0 01-2.5 2.5H8l-4 3v-3H5.5A2.5 2.5 0 013 11.5v-6z" />
                    </svg>
                </span>
                <span class="fb-link-text">
                    <span class="fb-link-title">En parler dans les Discussions</span>
                    <span class="fb-link-sub">Racontez votre usage à la communauté</span>
                </span>
            </a>
        </div>
        @endunless

        <div class="fb-form">
            @if ($this->isDemo)
                {{--
                    La question la plus actionnable de tout l'entonnoir : 42 personnes démarrent
                    la démonstration, 6 atteignent le paiement. Ce sont ces trente-six-là qui
                    répondent ici.

                    ⚠️ Chaque case est enregistrée DÈS LE CLIC (`wire:model.live`), sans attendre
                    le bouton d'envoi : quelqu'un qui coche puis referme a quand même répondu.
                    C'est une COLLECTE, pas un réglage d'affichage — la politique de
                    confidentialité le dit, et on ne stocke que des identifiants non nominatifs.
                --}}
                <p class="fb-separator">Qu'est-ce qui vous manque pour franchir le pas ?</p>

                <div class="fb-blockers">
                    @foreach (\App\Models\Feedback::BLOCKERS as $key => $label)
                        <label class="fb-blocker">
                            <input type="checkbox" class="fb-checkbox"
                                   value="{{ $key }}" wire:model.live="blockers">
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>

                <textarea class="fb-textarea" rows="2" wire:model="message"
                          placeholder="Dites-nous en plus, si vous voulez…"></textarea>
            @else
                <p class="fb-separator">Ou dites-le-nous en une phrase</p>

                <textarea class="fb-textarea" rows="3" wire:model="message"
                          placeholder="Ce qui vous a plu, en une phrase…"></textarea>
            @endif
            @error('message') <p class="fb-error">{{ $message }}</p> @enderror

            @if ($this->isDemo)
                {{--
                    Ni « Prénom » ni consentement à publication en démonstration : les deux
                    n'existaient que pour signer un témoignage, et `isPublishableAsTestimonial()`
                    refuse par construction tout retour venu de la démonstration — on demandait
                    un accord qu'on avait décidé de ne jamais honorer.
                --}}
                <label class="fb-field">
                    <span class="fb-label">E-mail (facultatif, si vous voulez une réponse)</span>
                    <input type="email" class="fb-input" wire:model="authorEmail" autocomplete="email">
                </label>
                @error('authorEmail') <p class="fb-error">{{ $message }}</p> @enderror
            @else
                <div class="fb-fields">
                    <label class="fb-field">
                        <span class="fb-label">Prénom</span>
                        <input type="text" class="fb-input" wire:model="authorName" autocomplete="given-name">
                    </label>
                    <label class="fb-field">
                        <span class="fb-label">E-mail (facultatif)</span>
                        <input type="email" class="fb-input" wire:model="authorEmail" autocomplete="email">
                    </label>
                </div>
                @error('authorName') <p class="fb-error">{{ $message }}</p> @enderror
                @error('authorEmail') <p class="fb-error">{{ $message }}</p> @enderror

                <label class="fb-consent">
                    <input type="checkbox" class="fb-checkbox" wire:model="canPublish">
                    <span>J'autorise OpenLMNP à publier ce message avec mon prénom.</span>
                </label>
            @endif

            <button type="button" class="fb-submit" wire:click="submit">Envoyer</button>
        </div>
    </div>
@endif

@if ($step === 'negative')
    <div class="fb-head">
        <div>
            <p class="fb-title">Désolé. Dites-nous ce qui ne va pas</p>
            <p class="fb-subtitle">Un retour franc vaut mieux qu'un départ silencieux. On lit tout.</p>
        </div>
        <button type="button" class="fb-close" aria-label="Fermer" wire:click="dismiss">
            <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" width="16" height="16" aria-hidden="true">
                <path d="M5 5l10 10M15 5L5 15" stroke-linecap="round" />
            </svg>
        </button>
    </div>

    <div class="fb-form">
        <textarea class="fb-textarea" rows="3" wire:model="message"
                  placeholder="Ce qui vous a manqué, ce qui vous a bloqué…"></textarea>
        @error('message') <p class="fb-error">{{ $message }}</p> @enderror

        <label class="fb-field">
            <span class="fb-label">E-mail (facultatif, si vous voulez une réponse)</span>
            <input type="email" class="fb-input" wire:model="authorEmail" autocomplete="email">
        </label>
        @error('authorEmail') <p class="fb-error">{{ $message }}</p> @enderror

        <button type="button" class="fb-submit" wire:click="submit">Envoyer à l'équipe</button>

        <a class="fb-secondary" href="{{ config('feedback.links.issues') }}" target="_blank" rel="noopener noreferrer">
            <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" width="14" height="14" aria-hidden="true">
                <path d="M7 4H4v12h12v-3M12 3h5v5M17 3l-7 7" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            Ouvrir une issue GitHub
        </a>
    </div>
@endif

@if ($step === 'done')
    <div class="fb-done">
        <span class="fb-done-icon">
            <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" width="20" height="20" aria-hidden="true">
                <circle cx="10" cy="10" r="7.5" />
                <path d="M6.5 10.2l2.4 2.3 4.6-4.6" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </span>
        <p class="fb-title">C'est envoyé. Merci !</p>

        @if ($this->forwards)
            <p class="fb-subtitle">Votre retour part directement à l'auteur du logiciel.</p>
        @else
            {{--
                Instance auto-hébergée : aucune adresse de transmission n'est configurée,
                donc rien n'est sorti de la machine. Le dire plutôt que le laisser croire —
                et proposer l'envoi manuel, que l'utilisateur maîtrise de bout en bout.
            --}}
            <p class="fb-subtitle">Votre retour est enregistré sur cette instance. Rien n'a été envoyé à l'extérieur.</p>
            <a class="fb-secondary" href="{{ $this->mailtoLink }}">
                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" width="14" height="14" aria-hidden="true">
                    <path d="M3 6l7 4.5L17 6M3 5h14v10H3z" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                L'envoyer aussi à l'auteur
            </a>
        @endif

        <button type="button" class="fb-submit" wire:click="dismiss">Fermer</button>
    </div>
@endif
