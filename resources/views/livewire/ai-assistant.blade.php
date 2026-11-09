<div class="ctx-ai" x-data="{ downFor: null, share: false, preview: null }">
    <style>
        .ctx-ai { display: flex; flex-direction: column; height: 100%; }
        .ctx-ai-thread { flex: 1; overflow-y: auto; display: flex; flex-direction: column; gap: 12px; }
        .ctx-ai-msg { font-size: 13px; line-height: 1.55; border-radius: 10px; padding: 10px 12px; max-width: 100%; }
        .ctx-ai-msg.user { background: var(--olmnp-success-bg); color: var(--olmnp-success-fg); align-self: flex-end; border: 1px solid var(--olmnp-success-bg-strong); }
        .ctx-ai-msg.bot { background: var(--olmnp-surface-muted); color: var(--olmnp-fg); border: 1px solid var(--olmnp-surface-alt); }
        .ctx-ai-msg p { margin: 0 0 8px; }
        .ctx-ai-msg p:last-child { margin-bottom: 0; }
        /* `list-style` est explicite : la préflight de Tailwind retire les puces. */
        .ctx-ai-msg ul, .ctx-ai-msg ol { margin: 0 0 8px; padding-left: 20px; }
        .ctx-ai-msg ul { list-style: disc outside; }
        .ctx-ai-msg ol { list-style: decimal outside; }
        .ctx-ai-msg li { margin-bottom: 4px; }
        .ctx-ai-msg a { color: var(--olmnp-success-accent); text-decoration: underline; }
        .ctx-ai-msg code { background: var(--olmnp-surface-alt); border-radius: 4px; padding: 1px 4px; font-size: 12px; }
        .ctx-ai-intro { font-size: 13px; color: var(--olmnp-fg); line-height: 1.55; }
        .ctx-ai-suggestions { display: flex; flex-direction: column; gap: 6px; margin-top: 12px; }
        .ctx-ai-suggestion { text-align: left; font-size: 12.5px; color: var(--olmnp-success-fg); background: var(--olmnp-success-bg); border: 1px solid var(--olmnp-success-bg-strong); border-radius: 8px; padding: 8px 10px; cursor: pointer; transition: background 0.15s; }
        .ctx-ai-suggestion:hover { background: var(--olmnp-success-bg-strong); }
        .ctx-ai-tools { font-size: 11.5px; color: var(--olmnp-fg-subtle); margin-top: 6px; }
        .ctx-ai-vote { display: flex; align-items: center; gap: 6px; margin-top: 8px; }
        .ctx-ai-vote button { background: none; border: none; cursor: pointer; font-size: 13px; color: var(--olmnp-fg-subtle); padding: 2px 4px; border-radius: 6px; }
        .ctx-ai-vote button:hover { background: var(--olmnp-surface-alt); }
        .ctx-ai-vote .voted { filter: none; opacity: 1; }
        .ctx-ai-reasons { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 6px; }
        .ctx-ai-reasons button { font-size: 11.5px; border: 1px solid var(--olmnp-border); border-radius: 999px; padding: 2px 8px; background: var(--olmnp-surface); color: var(--olmnp-fg); cursor: pointer; }
        .ctx-ai-reasons button:hover { border-color: var(--olmnp-success-accent); color: var(--olmnp-success-accent); }
        .ctx-ai-share { flex-basis: 100%; display: flex; gap: 6px; align-items: flex-start; font-size: 11.5px; color: var(--olmnp-fg-muted); margin-top: 4px; }
        .ctx-ai-reasons button.ctx-ai-share-preview { border: 0; padding: 0; background: none; text-decoration: underline; color: var(--olmnp-fg-muted); }
        .ctx-ai-share-text { flex-basis: 100%; max-height: 180px; overflow: auto; white-space: pre-wrap; font-size: 11px; background: var(--olmnp-surface); border: 1px solid var(--olmnp-border); border-radius: 6px; padding: 6px; margin: 0; }
        .ctx-ai-thanks { font-size: 11.5px; color: var(--olmnp-success-accent); margin-top: 6px; }
        .ctx-ai-notice { font-size: 12.5px; line-height: 1.5; background: var(--olmnp-warning-bg); border: 1px solid var(--olmnp-warning-border); color: var(--olmnp-warning-fg); border-radius: 8px; padding: 10px 12px; }
        .ctx-ai-notice a, .ctx-ai-notice button { color: var(--olmnp-warning-fg); text-decoration: underline; }
        .ctx-ai-pending { font-size: 12.5px; color: var(--olmnp-fg-subtle); font-style: italic; }
        /* La zone de flux existe toujours dans le DOM (Livewire y écrit pendant la
           requête) ; elle ne s'affiche que lorsqu'elle contient quelque chose. */
        .ctx-ai-typing:empty { display: none; }
        .ctx-ai-form { flex-shrink: 0; margin-top: 12px; }
        .ctx-ai-form textarea { width: 100%; resize: none; font-size: 13px; font-family: inherit; line-height: 1.5; border: 1px solid var(--olmnp-border); border-radius: 8px; padding: 8px 10px; color: var(--olmnp-fg-strong); }
        .ctx-ai-form textarea:focus { outline: none; border-color: var(--olmnp-success-accent); box-shadow: 0 0 0 2px var(--olmnp-success-bg-strong); }
        .ctx-ai-form textarea:disabled { background: var(--olmnp-surface-muted); color: var(--olmnp-fg-subtle); }
        .ctx-ai-actions { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-top: 6px; }
        .ctx-ai-send { background: var(--olmnp-success-solid); color: var(--olmnp-on-solid); border: none; border-radius: 8px; padding: 7px 14px; font-size: 13px; cursor: pointer; }
        .ctx-ai-send:hover:not(:disabled) { background: var(--olmnp-success-solid-hover); }
        .ctx-ai-send:disabled { background: var(--olmnp-border-strong); cursor: not-allowed; }
        .ctx-ai-reset { background: none; border: none; font-size: 12px; color: var(--olmnp-fg-subtle); cursor: pointer; text-decoration: underline; padding: 0; }
        .ctx-ai-reset:hover { color: var(--olmnp-fg); }
        .ctx-ai-disclaimer { flex-shrink: 0; font-size: 11.5px; color: var(--olmnp-fg-subtle); line-height: 1.45; margin-top: 10px; border-top: 1px solid var(--olmnp-surface-alt); padding-top: 8px; }
        .ctx-ai-disclaimer a { color: var(--olmnp-success-accent); text-decoration: none; }
        .ctx-ai-disclaimer a:hover { text-decoration: underline; }
    </style>

    <div class="ctx-ai-thread">
        @if ($messages === [])
            <div class="ctx-ai-intro">
                <p>Bonjour, je suis l'assistant OpenLMNP. Je réponds aux questions sur l'utilisation du logiciel.</p>
                @if ($suggestions !== [] && ! $keyNotice)
                    <div class="ctx-ai-suggestions">
                        @foreach ($suggestions as $suggestion)
                            <button type="button" class="ctx-ai-suggestion"
                                    wire:click="suggest(@js($suggestion))" wire:loading.attr="disabled">
                                {{ $suggestion }}
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        @foreach ($messages as $index => $message)
            <div class="ctx-ai-msg {{ $message['role'] === 'user' ? 'user' : 'bot' }}">
                {!! $message['html'] !!}

                @if ($message['role'] === 'assistant')
                    @if (! empty($message['tools']))
                        <div class="ctx-ai-tools">Consulté : {{ implode(', ', $message['tools']) }}</div>
                    @endif

                    @if (($message['feedback'] ?? null) === null)
                        <div class="ctx-ai-vote">
                            <button type="button" wire:click="feedback({{ $index }}, true)"
                                    aria-label="Réponse utile" title="Réponse utile">👍</button>
                            <button type="button" x-on:click="downFor = (downFor === {{ $index }} ? null : {{ $index }})"
                                    aria-label="Réponse peu utile" title="Réponse peu utile">👎</button>
                        </div>

                        <div class="ctx-ai-reasons" x-show="downFor === {{ $index }}" x-cloak>
                            @foreach (['inexact' => 'Inexact', 'hors-sujet' => 'Hors sujet', 'imprecis' => 'Trop vague'] as $reason => $label)
                                <button type="button" x-on:click="$wire.feedback({{ $index }}, false, '{{ $reason }}', share); downFor = null; share = false; preview = null">{{ $label }}</button>
                            @endforeach
                            <label class="ctx-ai-share">
                                <input type="checkbox" x-model="share">
                                Joindre cette conversation pour nous aider à améliorer l'assistant
                                (montants, noms et adresses masqués, conservée 90 jours)
                            </label>
                            <button type="button" class="ctx-ai-share-preview"
                                    x-on:click="preview = preview === null ? await $wire.sharePreview({{ $index }}) : null">Voir ce qui sera envoyé</button>
                            <pre class="ctx-ai-share-text" x-show="preview !== null" x-text="preview" x-cloak></pre>
                        </div>
                    @else
                        <div class="ctx-ai-thanks">{{ ($message['shared'] ?? false) ? 'Merci, la conversation masquée nous a été transmise.' : 'Merci pour votre retour.' }}</div>
                    @endif
                @endif
            </div>
        @endforeach

        {{-- Cible du flux : présente dans le DOM AVANT la requête, sinon Livewire n'a
             rien où écrire les fragments. Vidée au rendu suivant par le morphing. --}}
        <div class="ctx-ai-msg bot ctx-ai-typing" wire:stream="assistant-reply"></div>

        <div class="ctx-ai-pending" wire:loading wire:target="send, suggest, retry">L'assistant rédige…</div>

        @if ($keyNotice)
            <div class="ctx-ai-notice">
                {{ $keyNotice }}
                @if ($profileUrl)
                    <a href="{{ $profileUrl }}" wire:navigate>Ouvrir Mon profil</a>
                @endif
            </div>
        @endif

        @if ($notice)
            <div class="ctx-ai-notice">
                {{ $notice }}
                @if ($noticeReason === 'quota')
                    L'onglet Aide reste disponible.
                @elseif ($creditsUrl)
                    <a href="{{ $creditsUrl }}" target="_blank" rel="noopener">Ajouter du crédit</a>
                @elseif ($canRetry)
                    <button type="button" class="ctx-ai-reset" wire:click="retry">Réessayer</button>
                @endif
            </div>
        @endif
    </div>

    <form class="ctx-ai-form" wire:submit="send">
        <textarea wire:model="input" rows="2" maxlength="{{ \App\Livewire\AiAssistant::MAX_INPUT }}"
                  placeholder="Posez votre question sur l'utilisation d'OpenLMNP…"
                  @disabled($busy || (bool) $keyNotice)
                  x-on:keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); $wire.send() }"></textarea>

        <div class="ctx-ai-actions">
            <span>
                @if ($messages !== [])
                    <button type="button" class="ctx-ai-reset" wire:click="newConversation">Nouvelle conversation</button>
                @endif
            </span>
            <button type="submit" class="ctx-ai-send" @disabled($busy || (bool) $keyNotice)
                    wire:loading.attr="disabled" wire:target="send, suggest, retry">
                <span wire:loading.remove wire:target="send, suggest, retry">Envoyer</span>
                <span wire:loading wire:target="send, suggest, retry">…</span>
            </button>
        </div>
    </form>

    <p class="ctx-ai-disclaimer">
        L'assistant peut se tromper. Il ne donne pas de conseil fiscal.
        <a href="{{ \App\Filament\Pages\HelpPage::getUrl() }}" wire:navigate>Guide complet</a>
    </p>
</div>
