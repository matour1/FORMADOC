@extends('layouts.app')

@section('title', 'Nouveau mot de passe')

@section('content')
<div class="page-container">
    <div class="auth-layout">

        {{-- Panneau formulaire --}}
        <div class="auth-panel">
            <div class="tabs" style="margin-bottom:1.1rem;">
                <a class="tab active" href="{{ route('password.reset', $token) }}" aria-current="page">Nouveau mot de passe</a>
            </div>

            <h2>Choisissez un nouveau mot de passe</h2>
            <p>Au moins 8 caractères. Évitez de réutiliser un mot de passe déjà utilisé ailleurs.</p>

            @if (session('status'))
                <div class="banner banner-success" style="margin-bottom:1rem">
                    <i data-lucide="check-circle"></i>
                    <div>{{ session('status') }}</div>
                </div>
            @endif

            <form method="POST" action="{{ route('password.update') }}">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <input type="hidden" name="email" value="{{ $email }}">

                <div class="form-group">
                    <label for="email">Adresse e-mail</label>
                    <input id="email" type="email" name="email" value="{{ $email }}" readonly autocomplete="email"
                           class="form-control" placeholder="vous@exemple.com">
                    @error('email')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="form-group">
                    <label for="password">Nouveau mot de passe</label>
                    <input id="password" type="password" name="password" required autofocus autocomplete="new-password"
                           class="form-control" placeholder="••••••••">
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
                    <i data-lucide="key-round" style="width:15px;height:15px"></i> Réinitialiser le mot de passe
                </button>
            </form>

            <div class="auth-alt">
                <a href="{{ route('login') }}" style="color:var(--color-primary);font-weight:600">Retour à la connexion</a>
            </div>
        </div>

        {{-- Panneau décoratif --}}
        <div class="auth-aside" aria-hidden="true">
            <div class="auth-aside-top">
                <span class="proof-stamp">Sécurité</span>
                <h2 style="font-size:1.25rem;margin-top:.8rem;">Votre mot de passe est la clé de votre espace.</h2>
                <p style="color:var(--color-text-muted);font-size:.88rem;margin-top:.35rem;">
                    Choisissez un mot de passe solide et unique pour protéger vos documents et vos crédits.
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
