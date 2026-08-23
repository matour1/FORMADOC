@extends('layouts.app')

@section('title', 'Page introuvable — 404')

@section('content')
    <div class="max-w-3xl" style="margin:0 auto;padding:2.5rem 0">
        @include('errors.partials.error-card', [
            'code' => '404',
            'title' => 'Page introuvable',
            'message' => 'La page que vous cherchez n\'existe pas ou a été déplacée. Vérifiez l\'adresse ou utilisez la recherche.',
            'icon' => 'info',
            'iconSvg' => 'search-x',
            'help' => [
                'Vérifiez l\'adresse saisie dans la barre du navigateur.',
                'Utilisez la recherche ou le menu pour retrouver la page.',
                'Contactez le support si le problème persiste.',
            ],
            'actions' => [
                ['label' => 'Retour au tableau de bord', 'url' => route('account.index'), 'primary' => true],
                ['label' => 'Aller à l\'accueil', 'url' => url('/')],
            ],
        ])
    </div>
@endsection
