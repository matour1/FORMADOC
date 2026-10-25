@extends('layouts.app')

@section('title', 'Paramètres')

@section('content')
<div class="page-header" style="margin-bottom:1.25rem;">
    <div>
        <span class="eyebrow">Configuration</span>
        <h1>Paramètres du compte</h1>
        <p>Gérez vos informations personnelles, vos préférences d'affichage et la sécurité.</p>
    </div>
    <a href="{{ route('account.index') }}" class="btn btn-ghost btn-sm">
        <i data-lucide="arrow-left" style="width:14px;height:14px"></i> Retour au compte
    </a>
</div>

<div class="tabs" role="tablist" style="margin-bottom:1.5rem;">
    <button class="tab active" data-tab="profil" role="tab" aria-selected="true" id="tab-btn-profil">
        <i data-lucide="user" style="width:14px;height:14px;display:inline-block;vertical-align:-2px;margin-right:4px"></i> Profil
    </button>
    <button class="tab" data-tab="preferences" role="tab" aria-selected="false" id="tab-btn-preferences">
        <i data-lucide="sliders" style="width:14px;height:14px;display:inline-block;vertical-align:-2px;margin-right:4px"></i> Préférences
    </button>
    <button class="tab" data-tab="securite" role="tab" aria-selected="false" id="tab-btn-securite">
        <i data-lucide="shield-alert" style="width:14px;height:14px;display:inline-block;vertical-align:-2px;margin-right:4px"></i> Sécurité
    </button>
</div>

{{-- ===== Profil ===== --}}
<div id="tab-profil" class="tab-content" style="display:block;">
    <div class="card" style="margin-bottom:1.25rem;border-radius:var(--radius-lg);box-shadow:var(--shadow-1);">
        <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:1.25rem;padding-bottom:.85rem;border-bottom:1px solid var(--color-border);">
            <div style="width:36px;height:36px;border-radius:10px;background:var(--color-primary-tint);color:var(--color-primary);display:flex;align-items:center;justify-content:center;">
                <i data-lucide="user-check" style="width:18px;height:18px"></i>
            </div>
            <div>
                <h2 class="card-title" style="margin:0;font-size:1.05rem;">Informations personnelles</h2>
                <p style="margin:0;font-size:.78rem;color:var(--color-text-muted);">Vos coordonnées utilisées pour vos documents et factures.</p>
            </div>
        </div>

        <form method="POST" action="{{ route('account.settings.profile') }}">
            @csrf
            
            <div class="form-group" style="margin-bottom:1.2rem;">
                <label style="font-weight:600;font-size:.85rem;margin-bottom:.4rem;display:block;">Avatar utilisateur</label>
                <div style="display:flex;gap:1rem;align-items:center;background:var(--color-surface-2);padding:.85rem 1rem;border-radius:var(--radius);border:1px solid var(--color-border);">
                    <div style="position:relative;">
                        <div class="avatar" style="width:58px;height:58px;font-size:1.35rem;border-radius:50%;background:linear-gradient(135deg, var(--color-primary), var(--color-primary-light));color:var(--color-on-primary);display:flex;align-items:center;justify-content:center;font-weight:700;box-shadow:0 2px 8px rgba(0,0,0,.15);border:2px solid var(--color-surface);">
                            {{ strtoupper(substr($user->name, 0, 1)) }}
                        </div>
                        <span style="position:absolute;bottom:0;right:0;width:14px;height:14px;border-radius:50%;background:var(--color-secondary);border:2px solid var(--color-surface);" title="Compte actif"></span>
                    </div>
                    <div>
                        <div style="font-weight:600;font-size:.9rem;color:var(--color-text);">{{ $user->name }}</div>
                        <span class="form-hint" style="font-size:.78rem;color:var(--color-text-muted);display:block;margin-top:.15rem;">
                            Généré automatiquement à partir de votre identité.
                        </span>
                    </div>
                </div>
            </div>

            <div class="form-group" style="margin-bottom:1rem;">
                <label for="name" style="font-weight:600;font-size:.85rem;margin-bottom:.35rem;display:block;">Nom complet</label>
                <input class="form-control" id="name" name="name" value="{{ old('name', $user->name) }}" required style="border-radius:var(--radius-sm);" />
                @error('name')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="form-group" style="margin-bottom:1.25rem;">
                <label for="email" style="font-weight:600;font-size:.85rem;margin-bottom:.35rem;display:block;">Adresse email</label>
                <input class="form-control" id="email" name="email" type="email" value="{{ old('email', $user->email) }}" required style="border-radius:var(--radius-sm);" />
                @error('email')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>

            <button class="btn btn-primary" type="submit">
                <i data-lucide="save" style="width:15px;height:15px"></i> Enregistrer les modifications
            </button>
        </form>
    </div>
</div>

{{-- ===== Préférences ===== --}}
<div id="tab-preferences" class="tab-content" style="display:none;">
    <div class="card" style="margin-bottom:1.25rem;border-radius:var(--radius-lg);box-shadow:var(--shadow-1);">
        <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:1.25rem;padding-bottom:.85rem;border-bottom:1px solid var(--color-border);">
            <div style="width:36px;height:36px;border-radius:10px;background:var(--color-primary-tint);color:var(--color-primary);display:flex;align-items:center;justify-content:center;">
                <i data-lucide="sliders" style="width:18px;height:18px"></i>
            </div>
            <div>
                <h2 class="card-title" style="margin:0;font-size:1.05rem;">Préférences d'affichage & Notifications</h2>
                <p style="margin:0;font-size:.78rem;color:var(--color-text-muted);">Personnalisez votre espace de travail et la réception des alertes.</p>
            </div>
        </div>

        <form method="POST" action="{{ route('account.settings.preferences') }}">
            @csrf
            
            <div class="form-group" style="margin-bottom:1.25rem;">
                <label for="locale" style="font-weight:600;font-size:.85rem;margin-bottom:.35rem;display:block;">Langue de l'interface</label>
                <select class="form-control" id="locale" name="locale" style="border-radius:var(--radius-sm);max-width:320px;">
                    <option value="fr" @selected(($user->preferences['locale'] ?? 'fr') === 'fr')>🇫🇷 Français (recommandé)</option>
                    <option value="en" @selected(($user->preferences['locale'] ?? 'fr') === 'en')>🇬🇧 English</option>
                </select>
                <span class="form-hint" style="font-size:.78rem;color:var(--color-text-muted);display:block;margin-top:.35rem;">
                    Définit la langue par défaut de l'interface et des avis de conformité.
                </span>
            </div>

            <div style="border-top:1px solid var(--color-border);padding-top:1rem;margin-bottom:1.25rem;">
                <div class="form-group" style="display:flex;justify-content:space-between;align-items:center;gap:1rem;margin-bottom:1rem;">
                    <div>
                        <div style="font-weight:600;font-size:.9rem;color:var(--color-text);">Mode sombre</div>
                        <span class="form-hint" style="font-size:.78rem;color:var(--color-text-muted);">
                            Active le thème sombre pour reposer vos yeux lors de longues sessions de relecture.
                        </span>
                    </div>
                    <label class="switch" style="margin-bottom:0;flex-shrink:0;">
                        <input type="checkbox" name="theme_dark" value="1" @checked(($user->preferences['theme'] ?? 'light') === 'dark')>
                        <span class="track"></span>
                    </label>
                </div>

                <div class="form-group" style="display:flex;justify-content:space-between;align-items:center;gap:1rem;margin-bottom:0;">
                    <div>
                        <div style="font-weight:600;font-size:.9rem;color:var(--color-text);">Notifications par email</div>
                        <span class="form-hint" style="font-size:.78rem;color:var(--color-text-muted);">
                            Recevoir un récapitulatif par email dès qu'une mise en forme de rapport est terminée.
                        </span>
                    </div>
                    <label class="switch" style="margin-bottom:0;flex-shrink:0;">
                        <input type="checkbox" name="email_notifications" value="1" @checked($user->preferences['email_notifications'] ?? true)>
                        <span class="track"></span>
                    </label>
                </div>
            </div>

            <button class="btn btn-primary" type="submit" style="margin-top:.5rem">
                <i data-lucide="save" style="width:15px;height:15px"></i> Enregistrer les préférences
            </button>
        </form>
    </div>

    {{-- Aperçu Design System & Typographie --}}
    <div class="card" style="border-radius:var(--radius-lg);background:var(--color-surface-2);border:1px solid var(--color-border);padding:1rem 1.25rem;">
        <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.5rem;">
            <i data-lucide="sparkles" style="width:16px;height:16px;color:var(--color-accent)"></i>
            <strong style="font-size:.85rem;color:var(--color-text);">Norme typographique active : Academic Precision</strong>
        </div>
        <p style="font-size:.78rem;color:var(--color-text-secondary);margin:0;line-height:1.5;">
            Titres en Newsreader (noir profond #000000), corps de texte en Plus Jakarta Sans (12 pt interligne 1,5), données en IBM Plex Mono. Conforme aux standards académiques universitaires (SOPAL, CAMES, Masters & Doctorats).
        </p>
    </div>
</div>

{{-- ===== Sécurité & Zone de danger ===== --}}
<div id="tab-securite" class="tab-content" style="display:none;">
    <div class="card danger-zone" style="border-radius:var(--radius-lg);border:1px solid color-mix(in srgb, var(--color-correction) 30%, transparent);background:var(--color-surface);box-shadow:var(--shadow-1);">
        <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:1rem;padding-bottom:.85rem;border-bottom:1px solid color-mix(in srgb, var(--color-correction) 20%, transparent);">
            <div style="width:36px;height:36px;border-radius:10px;background:var(--color-correction-tint);color:var(--color-correction);display:flex;align-items:center;justify-content:center;">
                <i data-lucide="alert-triangle" style="width:18px;height:18px"></i>
            </div>
            <div>
                <h2 class="card-title" style="margin:0;font-size:1.05rem;color:var(--color-correction);">Zone de danger</h2>
                <p style="margin:0;font-size:.78rem;color:var(--color-text-muted);">Actions irréversibles relatives à vos données et à votre abonnement.</p>
            </div>
        </div>

        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1.25rem;">
            <div style="max-width:580px;">
                <div style="font-weight:600;color:var(--color-text);font-size:.92rem;">Supprimer définitivement mon compte</div>
                <p class="form-hint" style="margin-top:.35rem;line-height:1.5;color:var(--color-text-secondary);font-size:.82rem;">
                    Action irréversible : l'intégralité de vos documents originaux, versions mises en forme, historique de conversation avec l'assistant IA et crédits restants seront définitivement purgés.
                    @if ($subscription)
                        <br><strong style="color:var(--color-correction);">Votre abonnement actif ({{ $subscription->plan?->name }}) sera également résilié immédiatement.</strong>
                    @endif
                </p>
            </div>
            <button class="btn btn-danger btn-sm" id="showDeleteAccountModal" type="button" style="white-space:nowrap;">
                <i data-lucide="trash-2" style="width:14px;height:14px"></i> Supprimer le compte
            </button>
        </div>
    </div>
</div>

{{-- ===== Modale de suppression ===== --}}
<div class="modal-overlay" id="deleteAccountModal" role="dialog" aria-modal="true" aria-labelledby="deleteAccountTitle" style="display:none;">
    <div class="modal" style="max-width:480px;border-radius:var(--radius-lg);box-shadow:var(--shadow-3);">
        <div class="modal-header" style="border-bottom:1px solid var(--color-border);padding:1rem 1.25rem;">
            <h3 id="deleteAccountTitle" style="font-size:1.1rem;display:flex;align-items:center;gap:.5rem;color:var(--color-correction);">
                <i data-lucide="alert-octagon" style="width:18px;height:18px"></i> Supprimer le compte
            </h3>
            <button class="modal-close" id="closeDeleteAccountModal" aria-label="Fermer">&times;</button>
        </div>
        <div class="modal-body" style="padding:1.25rem;">
            <p style="margin-bottom:1rem;font-size:.92rem;line-height:1.5;">
                Cette action est <strong>définitive et irréversible</strong>. Toutes vos données
                (documents, conversations IA, crédits restants, abonnement) seront supprimées de nos serveurs.
            </p>
            <p style="font-size:.85rem;color:var(--color-text-muted);margin-bottom:1rem">
                Pour confirmer la suppression, veuillez saisir <strong class="mono" style="color:var(--color-correction);background:var(--color-correction-tint);padding:2px 6px;border-radius:4px;">SUPPRIMER</strong> ci-dessous :
            </p>
            <form method="POST" action="{{ route('account.destroy') }}" id="deleteAccountForm">
                @csrf
                @method('DELETE')
                <input type="text" class="form-control" id="deleteAccountInput" placeholder="SUPPRIMER" autocomplete="off" style="text-align:center;letter-spacing:1px;font-weight:700;" />
                <div style="display:flex;gap:.75rem;justify-content:flex-end;margin-top:1.25rem">
                    <button type="button" class="btn btn-ghost" id="keepAccount">Conserver mon compte</button>
                    <button type="submit" class="btn btn-danger" id="confirmDeleteAccount" disabled>
                        <i data-lucide="trash-2" style="width:14px;height:14px"></i> Confirmer la suppression
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    // Onglets Profil / Préférences / Sécurité
    const tabBtns = document.querySelectorAll('.tabs .tab[data-tab]');
    const tabPanels = document.querySelectorAll('.tab-content');
    tabBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            const target = btn.dataset.tab;
            tabBtns.forEach(b => { 
                const isActive = (b === btn);
                b.classList.toggle('active', isActive); 
                b.setAttribute('aria-selected', isActive ? 'true' : 'false'); 
            });
            tabPanels.forEach(p => { 
                p.style.display = (p.id === 'tab-' + target ? 'block' : 'none'); 
            });
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
