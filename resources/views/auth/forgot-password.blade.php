@extends('layouts.app')

@section('title', 'Mot de passe oublié')

@section('content')
<div class="page-container">
    <div class="auth-layout">

        {{-- Panneau formulaire --}}
        <div class="auth-panel">
            <div class="tabs" role="tablist" style="margin-bottom:1.1rem;">
                <a class="tab active" href="{{ route('password.request') }}" role="tab" aria-selected="true">Mot de passe oublié</a>
            </div>

            <h2>Réinitialiser votre mot de passe</h2>
            <p>Saisissez votre adresse e-mail : nous vous enverrons un lien sécurisé pour choisir un nouveau mot de passe.</p>

            @if (session('status'))
                <div class="banner banner-success" style="margin-bottom:1rem">
                    <i data-lucide="check-circle"></i>
                    <div>{{ session('status') }}</div>
                </div>
            @endif
            @if (session('error'))
                <div class="banner banner-danger" style="margin-bottom:1rem">
                    <i data-lucide="alert-circle"></i>
                    <div>{{ session('error') }}</div>
                </div>
            @endif

            <form method="POST" action="{{ route('password.email') }}">
                @csrf

                <div class="form-group">
                    <label for="email">Adresse e-mail</label>
                    <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email"
                           class="form-control" placeholder="vous@exemple.com">
                    @error('email')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <button type="submit" class="btn btn-primary btn-block">
                    <i data-lucide="send" style="width:15px;height:15px"></i> Envoyer le lien
                </button>
            </form>

            <div class="auth-alt">
                Vous vous souvenez de votre mot de passe ?
                <a href="{{ route('login') }}" style="color:var(--color-primary);font-weight:600">Se connecter</a>
            </div>
        </div>

        {{-- Panneau décoratif --}}
        <div class="auth-aside" aria-hidden="true">
            <div class="auth-aside-top">
                <span class="proof-stamp">Sécurité</span>
                <h2 style="font-size:1.25rem;margin-top:.8rem;">Un lien temporaire, valable 60 minutes.</h2>
                <p style="color:var(--color-text-muted);font-size:.88rem;margin-top:.35rem;">
                    Votre compte, vos documents et vos crédits restent protégés pendant toute la réinitialisation.
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
