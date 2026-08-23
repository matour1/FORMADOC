@extends('layouts.app')

@section('title', 'Session expirée — 419')

@section('content')
    <div class="max-w-3xl" style="margin:0 auto;padding:2.5rem 0">
        @include('errors.partials.error-card', [
            'code' => '419',
            'title' => 'Session expirée',
            'message' => 'Votre session a expiré. Rechargez la page et réessayez — les documents déjà enregistrés sont conservés.',
            'icon' => 'warn',
            'iconSvg' => 'hourglass',
            'detail' => 'Jeton de sécurité expiré · REF-419-6E2A9C',
            'help' => [
                'Rechargez la page pour renouveler votre session.',
                'Reconnectez-vous si le message persiste.',
                'Vos documents enregistrés ne sont pas affectés.',
            ],
            'actions' => [
                ['label' => 'Recharger la page', 'url' => 'javascript:location.reload()', 'primary' => true],
                ['label' => 'Se connecter', 'url' => route('login')],
            ],
        ])
    </div>
@endsection
