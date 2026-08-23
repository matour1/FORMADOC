@extends('layouts.app')

@section('title', 'Bienvenue sur FORMADOC')

@section('content')
    <div class="page-header">
        <div>
            <span class="eyebrow">Premiers pas</span>
            <h1>Onboarding FORMADOC</h1>
            <p>Un parcours court pour amener l'utilisateur jusqu'à son premier document traité.</p>
        </div>
        <a href="{{ route('dashboard') }}" class="btn btn-secondary btn-sm">Passer</a>
    </div>

    <div class="onboarding-flow">
        <div style="display:flex;flex-direction:column;gap:.85rem;">
            <button class="onboarding-card active" data-onboarding-step="profile">
                <span class="step-num">1</span>
                <span><strong>Compléter le profil</strong><br><span style="color:var(--color-text-muted);font-size:.84rem;">Nom, langue, thème et notifications.</span></span>
            </button>
            <button class="onboarding-card" data-onboarding-step="document">
                <span class="step-num">2</span>
                <span><strong>Téléverser un document</strong><br><span style="color:var(--color-text-muted);font-size:.84rem;">DOCX/DOC/TXT, type de document et gabarit.</span></span>
            </button>
            <button class="onboarding-card" data-onboarding-step="credits">
                <span class="step-num">3</span>
                <span><strong>Choisir le mode IA</strong><br><span style="color:var(--color-text-muted);font-size:.84rem;">Coût estimé, crédits et repli déterministe.</span></span>
            </button>
        </div>

        <div class="onboarding-preview">
            <div class="onboarding-panel" id="onboarding-profile">
                <span class="proof-stamp">Étape 1</span>
                <h2 style="font-size:1.65rem;margin:.9rem 0 .5rem;">Personnaliser l'espace</h2>
                <ul class="check-list">
                    <li>Préremplir le nom et l'email du compte.</li>
                    <li>Choisir la langue par défaut : français ou anglais.</li>
                    <li>Activer les notifications de traitement terminé.</li>
                </ul>
                <a href="{{ route('account.settings') }}" class="btn btn-primary" style="margin-top:1.3rem;">Configurer</a>
            </div>

            <div class="onboarding-panel" id="onboarding-document" style="display:none;">
                <span class="proof-stamp">Étape 2</span>
                <h2 style="font-size:1.65rem;margin:.9rem 0 .5rem;">Préparer le premier fichier</h2>
                <ul class="check-list">
                    <li>Accepter uniquement DOCX/DOC/TXT jusqu'à 50 Mo.</li>
                    <li>Choisir rapport, mémoire, CV ou document professionnel.</li>
                    <li>Sélectionner un gabarit public ou personnel.</li>
                </ul>
                <a href="{{ route('documents.create') }}" class="btn btn-primary" style="margin-top:1.3rem;">Nouveau document</a>
            </div>

            <div class="onboarding-panel" id="onboarding-credits" style="display:none;">
                <span class="proof-stamp">Étape 3</span>
                <h2 style="font-size:1.65rem;margin:.9rem 0 .5rem;">Comprendre l'assistance IA</h2>
                <ul class="check-list">
                    <li>Afficher le coût estimé avant chaque action IA.</li>
                    <li>Débiter les crédits uniquement après succès.</li>
                    <li>Basculer automatiquement en mode déterministe si l'IA échoue.</li>
                </ul>
                <a href="{{ route('account.index') }}" class="btn btn-primary" style="margin-top:1.3rem;">Voir les crédits</a>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    document.querySelectorAll('[data-onboarding-step]').forEach(btn => {
        btn.addEventListener('click', () => {
            const step = btn.dataset.onboardingStep;
            document.querySelectorAll('.onboarding-card').forEach(c => c.classList.remove('active'));
            btn.classList.add('active');
            document.querySelectorAll('.onboarding-panel').forEach(p => p.style.display = 'none');
            const panel = document.getElementById('onboarding-' + step);
            if (panel) panel.style.display = 'block';
        });
    });
</script>
@endpush
