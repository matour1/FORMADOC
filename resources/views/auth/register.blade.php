@extends('layouts.app')

@section('title', 'Inscription')

@section('content')
<div class="page-container">
    <div class="auth-layout">

        {{-- Panneau formulaire --}}
        <div class="auth-panel">
            <div class="tabs" role="tablist" style="margin-bottom:1.1rem;">
                <a class="tab" href="{{ route('login') }}" role="tab" aria-selected="false">Connexion</a>
                <a class="tab active" href="{{ route('register') }}" role="tab" aria-selected="true">Inscription</a>
            </div>

            <h2>Créer un compte</h2>
            <p>Cinq documents déterministes sont inclus chaque mois dans l'offre gratuite.</p>

            @if (session('error'))
                <div class="banner banner-danger" style="margin-bottom:1rem">
                    <i data-lucide="alert-circle"></i>
                    <div>{{ session('error') }}</div>
                </div>
            @endif

            <form method="POST" action="{{ route('register.attempt') }}">
                @csrf

                <div class="form-group">
                    <label for="name">Nom complet</label>
                    <input id="name" type="text" name="name" value="{{ old('name') }}" required autofocus autocomplete="name"
                           class="form-control" placeholder="Votre nom">
                    @error('name')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="form-group">
                    <label for="email">Adresse e-mail</label>
                    <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="email"
                           class="form-control" placeholder="vous@exemple.com">
                    @error('email')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="form-group">
                    <label for="password">Mot de passe</label>
                    <input id="password" type="password" name="password" required autocomplete="new-password"
                           class="form-control" placeholder="8 caractères minimum">
                    @error('password')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="form-group">
                    <label for="password_confirmation">Confirmer le mot de passe</label>
                    <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password"
                           class="form-control" placeholder="••••••••">
                </div>

                <button type="submit" class="btn btn-primary btn-block">
                    <i data-lucide="user-plus" style="width:15px;height:15px"></i> Créer mon compte
                </button>
            </form>

            <div class="auth-alt">
                Déjà inscrit ?
                <a href="{{ route('login') }}" style="color:var(--color-primary);font-weight:600">Se connecter</a>
            </div>
        </div>

        {{-- Panneau décoratif --}}
        <div class="auth-aside" aria-hidden="true">
            <div class="auth-aside-top">
                <span class="proof-stamp">Compte sécurisé</span>
                <h2 style="font-size:1.25rem;margin-top:.8rem;">Créez votre espace en une minute.</h2>
                <p style="color:var(--color-text-muted);font-size:.88rem;margin-top:.35rem;">
                    Plan Gratuit : 5 documents déterministes / mois et pages de garde incluses.
                    Chat IA au coût réel (crédits). Passez à Standard, Premium ou Pro pour
                    débloquer les traitements IA inclus et plus de crédits.
                </p>
            </div>
            <div class="auth-aside-stage">
                <div class="doc-sheet"><div class="doc-line title"></div><div class="doc-line w90"></div><div class="doc-line w75"></div><div class="doc-line w55"></div></div>
                <div class="doc-sheet after"><div class="doc-line title"></div><div class="doc-line accent"></div><div class="doc-line w90"></div><div class="doc-line w75"></div></div>
            </div>
        </div>

    </div>
</div>
@endsection
