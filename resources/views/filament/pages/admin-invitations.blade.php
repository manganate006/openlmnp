<x-filament-panels::page>
    <style>
        .inv-alerte { background: var(--olmnp-warning-bg); border: 1px solid var(--olmnp-warning-border); color: var(--olmnp-warning-fg); border-radius: 12px; padding: 14px 16px; margin-bottom: 20px; font-size: 13px; }
        .inv-lien { background: var(--olmnp-success-bg); border: 1px solid var(--olmnp-success-border); color: var(--olmnp-success-fg); border-radius: 12px; padding: 14px 16px; margin-bottom: 20px; }
        .inv-lien-titre { font-weight: 700; font-size: 14px; margin-bottom: 6px; }
        .inv-lien-url { display: block; width: 100%; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; padding: 8px 10px; border-radius: 8px; border: 1px solid var(--olmnp-success-border); background: var(--olmnp-surface); color: var(--olmnp-fg); margin: 8px 0; }
        .inv-lien-note { font-size: 12px; }
        .inv-table-wrap { overflow: hidden; border-radius: 12px; border: 1px solid var(--olmnp-border); }
        .inv-table { width: 100%; font-size: 13px; border-collapse: collapse; }
        .inv-table th { text-align: left; padding: 8px 12px; background: var(--olmnp-surface-muted); font-weight: 600; color: var(--olmnp-fg); white-space: nowrap; }
        .inv-table td { padding: 8px 12px; border-top: 1px solid var(--olmnp-surface-alt); vertical-align: middle; }
        .inv-badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; }
        .inv-badge-warning { background: var(--olmnp-warning-bg-strong); color: var(--olmnp-warning-fg); }
        .inv-badge-success { background: var(--olmnp-success-bg-strong); color: var(--olmnp-success-fg); }
        .inv-badge-danger { background: var(--olmnp-danger-bg-strong); color: var(--olmnp-danger-fg); }
        .inv-badge-gray { background: var(--olmnp-surface-alt); color: var(--olmnp-fg-muted); }
        .inv-btn { padding: 3px 10px; border-radius: 6px; font-size: 12px; font-weight: 500; cursor: pointer; border: none; }
        .inv-btn-relancer { background: var(--olmnp-info-bg-strong); color: var(--olmnp-info-fg); margin-right: 6px; }
        .inv-btn-revoquer { background: var(--olmnp-danger-bg-strong); color: var(--olmnp-danger-fg); }
        .inv-muted { color: var(--olmnp-fg-muted); font-size: 12px; }
        .inv-vide { padding: 24px; text-align: center; color: var(--olmnp-fg-muted); font-size: 13px; }
    </style>

    @if($this->avertissementMode())
        <div class="inv-alerte">
            <strong>Le mode d'inscription rend ces invitations inopérantes.</strong><br>
            {{ $this->avertissementMode() }}
        </div>
    @endif

    {{--
        ⚠️ Le lien n'existe qu'ici et qu'une fois : seule son empreinte SHA-256 est en base.
        L'afficher même quand le courriel est parti est délibéré — c'est le chemin qui sauve
        quand le SMTP est muet, et il n'y en a pas d'autre.
    --}}
    @if($dernierLien)
        <div class="inv-lien">
            <div class="inv-lien-titre">Lien d'invitation pour {{ $dernierEmail }}</div>
            <input class="inv-lien-url" type="text" readonly value="{{ $dernierLien }}"
                   onclick="this.select()" aria-label="Lien d'invitation">
            <div class="inv-lien-note">
                Il ne sera plus jamais affiché. Copiez-le si vous devez le transmettre vous-même.
                <button type="button" class="inv-btn inv-btn-relancer" wire:click="oublierLien">Masquer</button>
            </div>
        </div>
    @endif

    <div class="inv-table-wrap">
        <table class="inv-table">
            <thead>
                <tr>
                    <th>Adresse</th>
                    <th>État</th>
                    <th>Expire le</th>
                    <th>Envois</th>
                    <th>Invitée par</th>
                    <th>Compte</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($this->invitations() as $invitation)
                    <tr>
                        <td>
                            {{ $invitation->email }}
                            @if($invitation->note)
                                <div class="inv-muted">{{ $invitation->note }}</div>
                            @endif
                        </td>
                        <td>
                            <span class="inv-badge inv-badge-{{ $invitation->status()->color() }}">
                                {{ $invitation->status()->label() }}
                            </span>
                        </td>
                        <td>{{ $invitation->expires_at?->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</td>
                        <td>{{ $invitation->sent_count }}</td>
                        <td class="inv-muted">{{ $invitation->invitedBy?->email ?? '—' }}</td>
                        <td class="inv-muted">{{ $invitation->user?->email ?? '—' }}</td>
                        <td>
                            @if($invitation->isPending())
                                <button type="button" class="inv-btn inv-btn-relancer"
                                        wire:click="relancer({{ $invitation->id }})">Relancer</button>
                                <button type="button" class="inv-btn inv-btn-revoquer"
                                        wire:click="revoquer({{ $invitation->id }})"
                                        wire:confirm="Le lien cessera de fonctionner immédiatement.&#10;&#10;⚠️ Révoquer ne suspend AUCUN compte déjà créé : c'est une autre opération.">Révoquer</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="inv-vide">
                            Aucune invitation. Utilisez « Inviter quelqu'un », ou la commande
                            <code>php artisan openlmnp:invite</code> depuis le serveur.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
