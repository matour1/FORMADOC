@extends('layouts.app')

@section('title', 'Vérifie ton email')

@section('content')
<div class="page-container">
    <div class="auth-layout">

        {{-- Panneau formulaire --}}
        <div class="auth-panel">
            <div class="mail-verify-icon">
                <i data-lucide="mail-check" style="width:34px;height:34px;color:var(--color-primary)"></i>
            </div>

            <h2>Vérifie ton adresse email</h2>
            <p>Un lien de confirmation t'a été envoyé à <strong>{{ auth()->user()->email }}</strong>.</p>

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

            <div class="banner banner-info" style="margin-bottom:1rem">
                <i data-lucide="info"></i>
                <div>Pas de panique : la confirmation n'est pas obligatoire pour utiliser FORMADOC.
                    Tu peux continuer et confirmer plus tard. Le lien expire dans 24 heures.</div>
            </div>

            <form method="POST" action="{{ route('verification.send') }}" style="display:inline-block;width:100%">
                @csrf
                <button type="submit" class="btn btn-primary btn-block">
                    <i data-lucide="refresh-cw" style="width:15px;height:15px"></i> Renvoyer le lien
                </button>
            </form>

            <div style="text-align:center;margin-top:1rem">
                <a href="{{ route('account.index') }}" style="color:var(--color-primary);font-weight:600">
                    Continuer sans confirmer →
                </a>
            </div>
        </div>

        {{-- Panneau décoratif --}}
        <div class="auth-aside" aria-hidden="true">
            <div class="auth-aside-top">
                <span class="proof-stamp">Sécurité</span>
                <h2 style="font-size:1.25rem;margin-top:.8rem;">Confirmer, c'est protéger son compte.</h2>
                <p style="color:var(--color-text-muted);font-size:.88rem;margin-top:.35rem;">
                    La confirmation d'adresse nous permet de te joindre en cas de reçu, de document prêt
                    ou d'alerte de paiement. Elle sécurise aussi la récupération de ton compte.
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
