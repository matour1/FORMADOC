@extends('layouts.app')

@section('title', 'Requête invalide — 400')

@section('content')
    <div class="max-w-3xl" style="margin:0 auto;padding:2.5rem 0">
        @include('errors.partials.error-card', [
            'code' => '400',
            'title' => 'Requête invalide',
            'message' => 'La requête envoyée au serveur est mal formée. Vérifiez le lien ou les données transmises, puis réessayez.',
            'icon' => 'warn',
            'iconSvg' => 'alert-circle',
            'detail' => 'Requête mal formée · REF-400-8F3A2C',
            'help' => [
                'Vérifiez que l\'adresse ou les données envoyées sont correctement écrites.',
                'Rechargez la page depuis le menu ou la recherche.',
                'Contactez le support si le problème persiste.',
            ],
            'actions' => [
                ['label' => 'Retour au tableau de bord', 'url' => route('account.index'), 'primary' => true],
                ['label' => 'Aller à l\'accueil', 'url' => url('/')],
            ],
        ])
    </div>
@endsection
