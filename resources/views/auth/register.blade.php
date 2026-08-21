@extends('layouts.app')

@section('title', 'Inscription')

@section('content')
<div class="page-container" style="max-width:460px;margin:0 auto">
    <div class="card">
        <span class="eyebrow">Rejoindre FORMADOC</span>
        <h1 style="font-family:var(--font-display);font-size:1.6rem;margin:.2rem 0 .3rem">Créer un compte</h1>
        <p style="font-size:.88rem;color:var(--color-text-muted);margin-bottom:1.5rem">
            Inscrivez-vous pour utiliser le chat IA et acheter des crédits.
            Plan Gratuit : 5 documents déterministes / mois.
        </p>

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

        <p style="margin-top:1.5rem;text-align:center;font-size:.85rem;color:var(--color-text-muted)">
            Déjà inscrit ?
            <a href="{{ route('login') }}" style="color:var(--color-primary);font-weight:600">Se connecter</a>
        </p>
    </div>
</div>
@endsection
