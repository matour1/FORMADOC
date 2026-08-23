@extends('layouts.app')

@section('title', 'Paramètres')

@section('content')
<div class="page-header">
    <div>
        <span class="eyebrow">Configuration</span>
        <h1>Paramètres</h1>
        <p>Préférences et informations du compte.</p>
    </div>
    <a href="{{ route('account.index') }}" class="btn btn-ghost btn-sm">
        <i data-lucide="arrow-left" style="width:14px;height:14px"></i> Retour au compte
    </a>
</div>

<div class="tabs" role="tablist">
    <button class="tab active" data-tab="profil" role="tab" aria-selected="true" id="tab-btn-profil">Profil</button>
    <button class="tab" data-tab="preferences" role="tab" aria-selected="false" id="tab-btn-preferences">Préférences</button>
</div>

{{-- ===== Profil ===== --}}
<div id="tab-profil" class="tab-content" style="display:block;">
    <div class="card" style="margin-bottom:1.25rem;">
        <h2 class="card-title" style="margin-bottom:1rem">Informations personnelles</h2>

        <form method="POST" action="{{ route('account.settings.profile') }}">
            @csrf
            <div class="form-group">
                <label for="name">Nom complet</label>
                <input class="form-control" id="name" name="name" value="{{ old('name', $user->name) }}" required />
                @error('name')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>
            <div class="form-group">
                <label for="email">Adresse email</label>
                <input class="form-control" id="email" name="email" type="email" value="{{ old('email', $user->email) }}" required />
                @error('email')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>
            <div class="form-group">
                <label>Avatar</label>
                <div style="display:flex;gap:.75rem;align-items:center;">
                    <div class="avatar" style="width:56px;height:56px;font-size:1.3rem;">{{ strtoupper(substr($user->name, 0, 1)) }}</div>
                    <span class="form-hint" style="font-size:.78rem;color:var(--color-text-muted)">
                        Généré automatiquement à partir de votre nom.
                    </span>
                </div>
            </div>
            <button class="btn btn-primary" type="submit">
                <i data-lucide="save" style="width:15px;height:15px"></i> Enregistrer
            </button>
        </form>
    </div>

    {{-- Zone de danger --}}
    <div class="card danger-zone">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;">
            <div>
                <div style="font-weight:600;color:var(--color-correction);">Supprimer mon compte</div>
                <p class="form-hint" style="margin-top:.25rem;">
                    Action définitive : vos documents, modèles personnels et historique de discussion seront supprimés après confirmation.
                    @if ($subscription)
                        Votre abonnement {{ $subscription->plan?->name }} sera également résilié.
                    @endif
                </p>
            </div>
            <button class="btn btn-danger btn-sm" id="showDeleteAccountModal" type="button">Supprimer le compte</button>
        </div>
    </div>
</div>

{{-- ===== Préférences ===== --}}
<div id="tab-preferences" class="tab-content" style="display:none;">
    <div class="card">
        <h2 class="card-title" style="margin-bottom:1rem">Préférences</h2>

        <form method="POST" action="{{ route('account.settings.preferences') }}">
            @csrf
            <div class="form-group">
                <label for="locale">Langue</label>
                <select class="form-control" id="locale" name="locale">
                    <option value="fr" @selected(($user->preferences['locale'] ?? 'fr') === 'fr')>Français</option>
                    <option value="en" @selected(($user->preferences['locale'] ?? 'fr') === 'en')>Anglais</option>
                </select>
            </div>
            <div class="form-group" style="display:flex;justify-content:space-between;align-items:center;">
                <span style="font-weight:500;">Mode sombre</span>
                <label class="switch" style="margin-bottom:0">
                    <input type="checkbox" name="theme_dark" value="1" @checked(($user->preferences['theme'] ?? 'light') === 'dark')>
                    <span class="track"></span>
                </label>
            </div>
            <div class="form-group" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0;">
                <span style="font-weight:500;">Notifications par email</span>
                <label class="switch" style="margin-bottom:0">
                    <input type="checkbox" name="email_notifications" value="1" @checked($user->preferences['email_notifications'] ?? true)>
                    <span class="track"></span>
                </label>
            </div>
            <button class="btn btn-primary" type="submit" style="margin-top:1rem">
                <i data-lucide="save" style="width:15px;height:15px"></i> Enregistrer les préférences
            </button>
        </form>
    </div>
</div>

{{-- ===== Modale de suppression ===== --}}
<div class="modal-overlay" id="deleteAccountModal" role="dialog" aria-modal="true" aria-labelledby="deleteAccountTitle" style="display:none;">
    <div class="modal">
        <div class="modal-header">
            <h3 id="deleteAccountTitle">Supprimer le compte</h3>
            <button class="modal-close" id="closeDeleteAccountModal" aria-label="Fermer">&times;</button>
        </div>
        <div class="modal-body">
            <p style="margin-bottom:1rem">
                Cette action est <strong>définitive et irréversible</strong>. Toutes vos données
                (documents, conversations IA, crédits restants, abonnement) seront supprimées.
            </p>
            <p style="font-size:.85rem;color:var(--color-text-muted);margin-bottom:1rem">
                Pour confirmer, tapez <strong class="mono">SUPPRIMER</strong> ci-dessous :
            </p>
            <form method="POST" action="{{ route('account.destroy') }}" id="deleteAccountForm">
                @csrf
                @method('DELETE')
                <input type="text" class="form-control" id="deleteAccountInput" placeholder="SUPPRIMER" autocomplete="off" />
                <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:1.2rem">
                    <button type="button" class="btn btn-ghost" id="keepAccount">Annuler</button>
                    <button type="submit" class="btn btn-danger" id="confirmDeleteAccount" disabled>Supprimer définitivement</button>
                </div>
            </form>
        </div>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    // Onglets Profil / Préférences
    const tabBtns = document.querySelectorAll('.tabs .tab[data-tab]');
    const tabPanels = document.querySelectorAll('.tab-content');
    tabBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            const target = btn.dataset.tab;
            tabBtns.forEach(b => { b.classList.toggle('active', b === btn); b.setAttribute('aria-selected', b === btn ? 'true' : 'false'); });
            tabPanels.forEach(p => { p.style.display = p.id === 'tab-' + target ? 'block' : 'none'; });
        });
    });

    // Modale de suppression
    const modal = document.getElementById('deleteAccountModal');
    const showBtn = document.getElementById('showDeleteAccountModal');
    const closeBtn = document.getElementById('closeDeleteAccountModal');
    const keepBtn = document.getElementById('keepAccount');
    const input = document.getElementById('deleteAccountInput');
    const confirmBtn = document.getElementById('confirmDeleteAccount');
    const form = document.getElementById('deleteAccountForm');

    if (modal && showBtn) {
        const open = () => { modal.style.display = 'flex'; input.focus(); };
        const close = () => { modal.style.display = 'none'; input.value = ''; confirmBtn.disabled = true; };
        showBtn.addEventListener('click', open);
        closeBtn?.addEventListener('click', close);
        keepBtn?.addEventListener('click', close);
        modal.addEventListener('click', (e) => { if (e.target === modal) close(); });
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
    }
    if (input && confirmBtn) {
        input.addEventListener('input', () => { confirmBtn.disabled = input.value.trim() !== 'SUPPRIMER'; });
    }
    if (form) {
        form.addEventListener('submit', (e) => {
            if (input.value.trim() !== 'SUPPRIMER') { e.preventDefault(); input.focus(); }
        });
    }
});
</script>
@endpush
@endsection
