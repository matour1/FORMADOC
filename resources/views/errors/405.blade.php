@extends('layouts.app')

@section('title', 'Méthode non autorisée — 405')

@section('content')
    <div class="max-w-3xl" style="margin:0 auto;padding:2.5rem 0">
        @include('errors.partials.error-card', [
            'code' => '405',
            'title' => 'Méthode non autorisée',
            'message' => 'Cette action n\'est pas autorisée sur la ressource demandée. Rechargez la page ou revenez plus tard.',
            'icon' => 'warn',
            'iconSvg' => 'ban',
            'detail' => 'Méthode non autorisée · REF-405-9B1C7D',
            'help' => [
                'Rechargez la page pour réinitialiser l\'état.',
                'Utilisez les actions du menu plutôt qu\'une adresse directe.',
                'Contactez le support si le problème persiste.',
            ],
            'actions' => [
                ['label' => 'Recharger la page', 'url' => 'javascript:location.reload()', 'primary' => true],
                ['label' => 'Tableau de bord', 'url' => route('account.index')],
            ],
        ])
    </div>
@endsection
