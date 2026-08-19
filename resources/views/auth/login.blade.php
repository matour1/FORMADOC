@extends('layouts.app')

@section('title', 'Connexion')

@section('content')
<div class="max-w-md mx-auto px-margin-mobile md:px-0 py-12">
    <div class="bg-surface-container-lowest border border-outline-variant rounded-xl p-8 shadow-sm">
        <h1 class="font-h2 text-h2 text-on-surface mb-1">Connexion</h1>
        <p class="font-caption text-caption text-secondary mb-8">
            Accédez à votre compte, vos crédits et votre chat IA.
        </p>

        @if (session('success'))
            <div class="mb-6 px-4 py-3 bg-primary-fixed-dim border border-primary rounded-lg text-on-primary-fixed text-caption">
                {{ session('success') }}
            </div>
        @endif

        <form method="POST" action="{{ route('login.attempt') }}" class="space-y-5">
            @csrf

            <div>
                <label for="email" class="font-label-mono text-label-mono text-on-surface-variant uppercase mb-2 block">
                    Adresse e-mail
                </label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email"
                       class="w-full px-4 py-2.5 bg-surface-container-low border border-outline-variant rounded-lg text-on-surface focus:outline-none focus:ring-2 focus:ring-primary"
                       placeholder="vous@exemple.com">
                @error('email')
                    <p class="mt-1 font-caption text-caption text-error">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password" class="font-label-mono text-label-mono text-on-surface-variant uppercase mb-2 block">
                    Mot de passe
                </label>
                <input id="password" type="password" name="password" required autocomplete="current-password"
                       class="w-full px-4 py-2.5 bg-surface-container-low border border-outline-variant rounded-lg text-on-surface focus:outline-none focus:ring-2 focus:ring-primary"
                       placeholder="••••••••">
                @error('password')
                    <p class="mt-1 font-caption text-caption text-error">{{ $message }}</p>
                @enderror
            </div>

            <label class="flex items-center gap-2 font-caption text-caption text-secondary cursor-pointer">
                <input type="checkbox" name="remember" value="1"
                       class="rounded border-outline-variant text-primary focus:ring-primary">
                Se souvenir de moi
            </label>

            <button type="submit"
                    class="w-full py-3 bg-primary hover:bg-primary-container text-on-primary font-label-mono text-label-mono uppercase tracking-wider rounded-lg transition-colors">
                Se connecter
            </button>
        </form>

        <p class="mt-6 text-center font-caption text-caption text-secondary">
            Pas encore de compte ?
            <a href="{{ route('register') }}" class="text-primary font-semibold hover:underline">Créer un compte</a>
        </p>
    </div>
</div>
@endsection
