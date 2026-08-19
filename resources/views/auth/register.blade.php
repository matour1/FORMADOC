@extends('layouts.app')

@section('title', 'Inscription')

@section('content')
<div class="max-w-md mx-auto px-margin-mobile md:px-0 py-12">
    <div class="bg-surface-container-lowest border border-outline-variant rounded-xl p-8 shadow-sm">
        <h1 class="font-h2 text-h2 text-on-surface mb-1">Créer un compte</h1>
        <p class="font-caption text-caption text-secondary mb-8">
            Inscrivez-vous pour utiliser le chat IA et acheter des crédits.
        </p>

        @if ($errors->any())
            <div class="mb-6 px-4 py-3 bg-error-container border border-error rounded-lg text-on-error-container font-caption text-caption">
                <ul class="list-disc pl-4 space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('register.attempt') }}" class="space-y-5">
            @csrf

            <div>
                <label for="name" class="font-label-mono text-label-mono text-on-surface-variant uppercase mb-2 block">
                    Nom complet
                </label>
                <input id="name" type="text" name="name" value="{{ old('name') }}" required autofocus autocomplete="name"
                       class="w-full px-4 py-2.5 bg-surface-container-low border border-outline-variant rounded-lg text-on-surface focus:outline-none focus:ring-2 focus:ring-primary"
                       placeholder="Votre nom">
                @error('name')
                    <p class="mt-1 font-caption text-caption text-error">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="email" class="font-label-mono text-label-mono text-on-surface-variant uppercase mb-2 block">
                    Adresse e-mail
                </label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="email"
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
                <input id="password" type="password" name="password" required autocomplete="new-password"
                       class="w-full px-4 py-2.5 bg-surface-container-low border border-outline-variant rounded-lg text-on-surface focus:outline-none focus:ring-2 focus:ring-primary"
                       placeholder="8 caractères minimum">
                @error('password')
                    <p class="mt-1 font-caption text-caption text-error">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password_confirmation" class="font-label-mono text-label-mono text-on-surface-variant uppercase mb-2 block">
                    Confirmer le mot de passe
                </label>
                <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password"
                       class="w-full px-4 py-2.5 bg-surface-container-low border border-outline-variant rounded-lg text-on-surface focus:outline-none focus:ring-2 focus:ring-primary"
                       placeholder="••••••••">
            </div>

            <button type="submit"
                    class="w-full py-3 bg-primary hover:bg-primary-container text-on-primary font-label-mono text-label-mono uppercase tracking-wider rounded-lg transition-colors">
                Créer mon compte
            </button>
        </form>

        <p class="mt-6 text-center font-caption text-caption text-secondary">
            Déjà inscrit ?
            <a href="{{ route('login') }}" class="text-primary font-semibold hover:underline">Se connecter</a>
        </p>
    </div>
</div>
@endsection
