@extends('layouts.app')

@section('title', 'Connexion requise — 401')

@section('content')
    <div class="max-w-3xl" style="margin:0 auto;padding:2.5rem 0">
        @include('errors.partials.error-card', [
            'code' => '401',
            'title' => 'Connexion requise',
            'message' => 'Votre session a expiré ou vous devez vous connecter pour accéder à cette page.',
            'icon' => 'info',
            'iconSvg' => 'lock',
            'help' => [
                'Connectez-vous avec votre adresse email et votre mot de passe.',
                'Si le problème persiste, réinitialisez votre mot de passe.',
                'Vérifiez votre adresse email si vous venez de créer un compte.',
            ],
            'actions' => [
                ['label' => 'Se connecter', 'url' => route('login'), 'primary' => true],
                ['label' => 'Aller à l\'accueil', 'url' => url('/')],
            ],
        ])
    </div>
@endsection
