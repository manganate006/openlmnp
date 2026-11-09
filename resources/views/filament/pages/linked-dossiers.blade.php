<x-filament-panels::page>
    {{--
        Écran 4, variante C (cartes détaillées, actions sensibles à l'écart) — choisie
        le 2026-10-15. Le quota devient une jauge, les membres sont nommés avec leur
        rôle, « Archiver » passe par un menu puis une vraie modale Filament
        (`archiverAction()`, plus de `wire:confirm`).
    --}}
    <style>
        [x-cloak] { display: none !important; }
        .ld-meter { margin-bottom: 18px; }
        .ld-meter-bar { display: flex; gap: 4px; height: 6px; margin-bottom: 6px; }
        .ld-meter-bar span { flex: 1; border-radius: 999px; background: var(--olmnp-surface-alt); }
        .ld-meter-bar span.is-used { background: var(--olmnp-success-solid); }
        .ld-meter-bar.is-full span.is-used { background: var(--olmnp-warning-solid); }
        .ld-meter-text { font-size: 13px; color: var(--olmnp-fg-muted); }
        .ld-meter-text b { color: var(--olmnp-fg); }
        .ld-muted { color: var(--olmnp-fg-subtle); font-size: 12px; }

        .ld-card { border: 1px solid var(--olmnp-border); border-radius: 12px; padding: 16px; margin-bottom: 16px; background: var(--olmnp-surface); }
        .ld-card-header { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 10px; flex-wrap: wrap; }
        .ld-title-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .ld-card-title { font-size: 14px; font-weight: 700; color: var(--olmnp-fg-strong); }
        .ld-card-siren { font-size: 12px; color: var(--olmnp-fg-muted); font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }

        .ld-badge { display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; }
        .ld-badge-success { background: var(--olmnp-success-bg-strong); color: var(--olmnp-success-fg); }
        .ld-badge-gray { background: var(--olmnp-surface-alt); color: var(--olmnp-fg-muted); }

        .ld-facts { display: flex; gap: 16px; flex-wrap: wrap; font-size: 12px; color: var(--olmnp-fg-muted); margin-bottom: 10px; }
        .ld-facts b { display: block; color: var(--olmnp-fg); font-weight: 600; }

        .ld-members { list-style: none; margin: 0 0 4px; padding: 0; font-size: 13px; }
        .ld-members li { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 6px 0; border-top: 1px solid var(--olmnp-surface-alt); }
        .ld-who { display: flex; align-items: center; gap: 8px; min-width: 0; }
        .ld-who-text { display: flex; flex-direction: column; min-width: 0; }
        .ld-who-name { color: var(--olmnp-fg-strong); font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .ld-who-mail { color: var(--olmnp-fg-muted); font-size: 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .ld-avatar { flex: none; width: 26px; height: 26px; border-radius: 999px; display: flex; align-items: center; justify-content: center;
            font-size: 11px; font-weight: 700; background: var(--olmnp-surface-alt); color: var(--olmnp-fg); }
        .ld-avatar.is-you { background: var(--olmnp-success-bg-strong); color: var(--olmnp-success-fg); }

        .ld-actions { margin-top: 10px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .ld-btn { padding: 4px 11px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; border: 1px solid transparent; }
        .ld-btn:focus-visible { outline: 2px solid var(--olmnp-success-solid); outline-offset: 2px; }
        .ld-btn-open { background: var(--olmnp-success-solid); color: var(--olmnp-on-solid); }
        .ld-btn-ghost { background: transparent; color: var(--olmnp-fg-muted); border-color: var(--olmnp-border); }
        .ld-btn-menu { background: transparent; color: var(--olmnp-fg-muted); border-color: var(--olmnp-border); padding: 4px 8px; line-height: 1; }

        .ld-menu { position: relative; display: inline-block; }
        .ld-menu-pop { position: absolute; z-index: 20; top: calc(100% + 4px); right: 0; min-width: 220px;
            background: var(--olmnp-surface); border: 1px solid var(--olmnp-border); border-radius: 10px;
            box-shadow: 0 8px 24px rgba(0,0,0,.14); padding: 4px; }
        .ld-menu-item { display: flex; align-items: center; gap: 8px; width: 100%; text-align: left; padding: 7px 10px;
            border-radius: 7px; border: none; background: transparent; font-size: 13px; color: var(--olmnp-fg); cursor: pointer; }
        .ld-menu-item:hover, .ld-menu-item:focus-visible { background: var(--olmnp-surface-alt); }
        .ld-menu-item.is-danger { color: var(--olmnp-danger-fg); }
        .ld-menu-item:focus-visible { outline: 2px solid var(--olmnp-success-solid); outline-offset: -2px; }

        .ld-sr { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden;
            clip: rect(0,0,0,0); white-space: nowrap; border: 0; }

        .ld-invite { margin-top: 12px; display: flex; gap: 8px; align-items: center; }
        .ld-invite input { flex: 1; font-size: 13px; padding: 4px 8px; border-radius: 6px; border: 1px solid var(--olmnp-border); background: var(--olmnp-surface); color: var(--olmnp-fg); }
        .ld-invite input:focus-visible { outline: 2px solid var(--olmnp-success-solid); outline-offset: 1px; }
        .ld-invitations { list-style: none; margin: 8px 0 0; padding: 0; font-size: 12px; }
        .ld-invitations li { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 4px 0; border-top: 1px solid var(--olmnp-surface-alt); color: var(--olmnp-fg-muted); }

        .ld-slot { margin-top: 4px; padding: 12px; border: 1px dashed var(--olmnp-border-strong); border-radius: 10px;
            display: flex; align-items: center; gap: 10px; font-size: 12px; color: var(--olmnp-fg-muted); }

        .ld-vide { padding: 24px; text-align: center; color: var(--olmnp-fg-muted); font-size: 13px; }
    </style>

    @php($gauge = $this->quotaGauge())
    <div class="ld-meter">
        @if($gauge)
            <div class="ld-meter-bar {{ $gauge['used'] >= $gauge['quota'] ? 'is-full' : '' }}" aria-hidden="true">
                @for($i = 0; $i < $gauge['quota']; $i++)
                    <span class="{{ $i < $gauge['used'] ? 'is-used' : '' }}"></span>
                @endfor
            </div>
        @endif
        <div class="ld-meter-text">{{ $this->quotaLabel() }}
            <span class="ld-muted">Les dossiers rejoints sur invitation ne comptent pas.</span>
        </div>
    </div>

    @forelse($this->dossiers() as $dossier)
        @php($peutAdministrer = $this->peutAdministrer($dossier, auth()->user()))
        <div class="ld-card">
            <div class="ld-card-header">
                <div class="ld-title-row">
                    <span class="ld-card-title">{{ $dossier->user?->name }}</span>
                    @if($dossier->isArchived())
                        <span class="ld-badge ld-badge-gray">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z"/></svg>
                            Archivé
                        </span>
                    @elseif($dossier->isClosed())
                        <span class="ld-badge ld-badge-gray">Fermé&nbsp;: aucun membre actif</span>
                    @else
                        <span class="ld-badge ld-badge-success">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                            Actif
                        </span>
                    @endif
                </div>
                <span class="ld-card-siren">SIREN {{ $dossier->siren }}</span>
            </div>

            <div class="ld-facts">
                <div>Créé le<br><b>{{ $dossier->created_at?->translatedFormat('d/m/Y') }}</b></div>
                @if($dossier->sirene_checked_at)
                    <div>Sirene<br><b>{{ $dossier->sirene_snapshot['legal_form'] ?? '2110' }} · vérifié</b></div>
                @endif
            </div>

            <ul class="ld-members">
                @foreach($dossier->members as $member)
                    @php($estVous = $member->user_id === auth()->id())
                    <li>
                        <span class="ld-who">
                            <span class="ld-avatar {{ $estVous ? 'is-you' : '' }}" aria-hidden="true">{{ \App\Filament\Pages\LinkedDossiers::initials($member->user?->name ?? $member->user?->email) }}</span>
                            <span class="ld-who-text">
                                <span class="ld-who-name">{{ $estVous ? 'Vous' : ($member->user?->name ?: $member->user?->email) }}</span>
                                @if(! $estVous)
                                    <span class="ld-who-mail">{{ $member->user?->email }}</span>
                                @endif
                            </span>
                        </span>
                        <span class="ld-title-row">
                            @if($member->revoked_at)
                                <span class="ld-badge ld-badge-gray">Révoqué</span>
                            @else
                                <span class="ld-badge ld-badge-gray">{{ $member->role->label() }}</span>
                                @if($dossier->actingOwner()?->id === $member->user_id && $member->role !== \App\Enums\DossierMemberRole::Owner)
                                    <span class="ld-badge ld-badge-gray">Responsable actuel</span>
                                @endif
                            @endif
                            @if($peutAdministrer
                                && $member->role !== \App\Enums\DossierMemberRole::Owner
                                && ! $member->revoked_at)
                                <button type="button" class="ld-btn ld-btn-ghost"
                                        wire:click="revoquerMembre({{ $dossier->id }}, {{ $member->user_id }})"
                                        wire:confirm="Cette personne perdra l'accès à ce dossier.">Révoquer</button>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>

            @if($peutAdministrer && $dossier->invitations->isNotEmpty())
                <ul class="ld-invitations">
                    @foreach($dossier->invitations as $invitation)
                        <li>
                            <span>
                                {{ $invitation->email }}
                                @if($invitation->isAccepted())
                                    <span class="ld-badge ld-badge-success">Acceptée</span>
                                @elseif($invitation->isDeclined())
                                    {{-- Refusée PAR L'INVITÉ : distincte d'une révocation par vous
                                        (défaut gênant n°6 du test de bout en bout, 2026-10-15). --}}
                                    <span class="ld-badge ld-badge-gray">Refusée</span>
                                @elseif($invitation->isRevoked())
                                    <span class="ld-badge ld-badge-gray">Révoquée</span>
                                @elseif($invitation->isExpired())
                                    <span class="ld-badge ld-badge-gray">Expirée</span>
                                @else
                                    <span class="ld-badge ld-badge-gray">En attente</span>
                                @endif
                            </span>
                            @if($invitation->isPending())
                                <span>
                                    <button type="button" class="ld-btn ld-btn-ghost"
                                            wire:click="relancerInvitation({{ $invitation->id }})">Relancer</button>
                                    <button type="button" class="ld-btn ld-btn-ghost"
                                            wire:click="revoquerInvitation({{ $invitation->id }})"
                                            wire:confirm="Le lien envoyé ne fonctionnera plus.">Révoquer</button>
                                </span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            <div class="ld-actions">
                @unless($dossier->isArchived())
                    <form method="POST" action="{{ route('filament.admin.dossiers.open', ['dossier' => $dossier->public_id]) }}">
                        @csrf
                        @include('filament.partials.dossier-context-field')
                        <button type="submit" class="ld-btn ld-btn-open">Ouvrir le dossier</button>
                    </form>

                    @if($peutAdministrer)
                        <span class="ld-menu" x-data="{ open: false }" x-on:click.outside="open = false">
                            <button type="button" class="ld-btn ld-btn-menu" x-on:click="open = !open"
                                    :aria-expanded="open.toString()" aria-haspopup="menu">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.75a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5ZM12 12.75a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5ZM12 18.75a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5Z"/></svg>
                                <span class="ld-sr">Plus d'actions sur ce dossier</span>
                            </button>
                            <div class="ld-menu-pop" role="menu" x-show="open" x-cloak>
                                <button type="button" class="ld-menu-item is-danger" role="menuitem"
                                        x-on:click="open = false"
                                        wire:click="mountAction('archiver', {{ \Illuminate\Support\Js::from(['id' => $dossier->id]) }})">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z"/></svg>
                                    Archiver le dossier…
                                </button>
                            </div>
                        </span>
                    @endif

                    <button type="button" class="ld-btn ld-btn-ghost"
                            wire:click="quitter({{ $dossier->id }})"
                            wire:confirm="Vous perdrez l'accès à ce dossier. Continuer ?">Quitter</button>
                @endunless
            </div>

            @if($peutAdministrer && ! $dossier->isArchived())
                <div class="ld-invite">
                    <label for="ld-invite-{{ $dossier->id }}" class="ld-sr">E-mail du second indivisaire</label>
                    <input type="text" id="ld-invite-{{ $dossier->id }}" wire:model="inviteEmails.{{ $dossier->id }}"
                           placeholder="E-mail du second indivisaire">
                    <button type="button" class="ld-btn ld-btn-open"
                            wire:click="inviter({{ $dossier->id }})">Inviter</button>
                </div>
            @endif
        </div>
    @empty
        <div class="ld-vide">
            Aucun dossier lié pour le moment. Créez-en un avec « Créer un dossier lié » si vous
            partagez un bien en indivision.
        </div>
    @endforelse

    @if($gauge && $gauge['used'] < $gauge['quota'])
        <div class="ld-slot">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
            <div>
                <span style="font-weight:600;color:var(--olmnp-fg)">{{ $gauge['quota'] - $gauge['used'] }} emplacement(s) disponible(s)</span><br>
                Créez un dossier pour une autre indivision, avec son propre SIREN.
            </div>
        </div>
    @endif
</x-filament-panels::page>
