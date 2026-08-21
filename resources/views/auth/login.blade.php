@extends('layouts.app')

@section('title', 'Connexion')

@section('content')
<div class="page-container" style="max-width:460px;margin:0 auto">
    <div class="card">
        <span class="eyebrow">Bienvenue</span>
        <h1 style="font-family:var(--font-display);font-size:1.6rem;margin:.2rem 0 .3rem">Connexion</h1>
        <p style="font-size:.88rem;color:var(--color-text-muted);margin-bottom:1.5rem">
            Accédez à votre compte, vos crédits et votre chat IA.
        </p>

        @if (session('success'))
            <div class="banner banner-success">
                <i data-lucide="check-circle"></i>
                <div>{{ session('success') }}</div>
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
                <label for="password">Mot de passe</label>
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

        <p style="margin-top:1.5rem;text-align:center;font-size:.85rem;color:var(--color-text-muted)">
            Pas encore de compte ?
            <a href="{{ route('register') }}" style="color:var(--color-primary);font-weight:600">Créer un compte</a>
        </p>
    </div>
</div>
@endsection
