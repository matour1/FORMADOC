@extends('layouts.app')

@section('title', 'Connexion')

@section('content')
<div class="page-container">
    <div class="auth-layout">

        {{-- Panneau formulaire --}}
        <div class="auth-panel">
            <div class="tabs" style="margin-bottom:1.1rem;">
                <a class="tab active" href="{{ route('login') }}" aria-current="page">Connexion</a>
                <a class="tab" href="{{ route('register') }}">Inscription</a>
            </div>

            <h2>Bon retour</h2>
            <p>Connectez-vous pour reprendre vos documents, vos crédits et vos conversations IA.</p>

            @if (session('success'))
                <div class="banner banner-success" style="margin-bottom:1rem">
                    <i data-lucide="check-circle"></i>
                    <div>{{ session('success') }}</div>
                </div>
            @endif
            @if (session('error'))
                <div class="banner banner-danger" style="margin-bottom:1rem">
                    <i data-lucide="alert-circle"></i>
                    <div>{{ session('error') }}</div>
                </div>
            @endif

            <form method="POST" action="{{ route('login.attempt') }}">
                @csrf

                <div class="form-group">
                    <label for="email">Adresse e-mail</label>
                    <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email"
                           class="form-control" placeholder="vous@exemple.com">
                    @error('email')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="form-group">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:.5rem;">
                        <label for="password" style="margin:0;">Mot de passe</label>
                        <a href="{{ route('password.request') }}" style="font-size:.8rem;color:var(--color-primary);font-weight:600">Mot de passe oublié ?</a>
                    </div>
                    <input id="password" type="password" name="password" required autocomplete="current-password"
                           class="form-control" placeholder="••••••••">
                    @error('password')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <label class="switch" style="margin-bottom:1rem">
                    <input type="checkbox" name="remember" value="1">
                    <span class="track"></span>
                    <span style="font-size:.85rem">Se souvenir de moi</span>
                </label>

                <button type="submit" class="btn btn-primary btn-block">
                    <i data-lucide="log-in" style="width:15px;height:15px"></i> Se connecter
                </button>
            </form>

            <div class="auth-alt">
                Pas encore de compte ?
                <a href="{{ route('register') }}" style="color:var(--color-primary);font-weight:600">Créer un compte</a>
            </div>
        </div>

        {{-- Panneau décoratif --}}
        <div class="auth-aside" aria-hidden="true">
            <div class="auth-aside-top">
                <span class="proof-stamp">Parcours sécurisé</span>
                <h2 style="font-size:1.25rem;margin-top:.8rem;">Vos rapports, votre chat IA et vos crédits au même endroit.</h2>
                <p style="color:var(--color-text-muted);font-size:.88rem;margin-top:.35rem;">
                    FORMADOC met en forme vos rapports académiques (DOCX, DOC, TXT) avec des modèles professionnels,
                    sans jamais perdre votre contenu.
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
