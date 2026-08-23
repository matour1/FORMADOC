@extends('layouts.app')

@section('title', 'Accès refusé — 403')

@section('content')
    <div class="max-w-3xl" style="margin:0 auto;padding:2.5rem 0">
        @include('errors.partials.error-card', [
            'code' => '403',
            'title' => 'Accès refusé',
            'message' => 'Vous n\'avez pas la permission de consulter cette ressource. Si vous pensez qu\'il s\'agit d\'une erreur, contactez le support.',
            'icon' => 'danger',
            'iconSvg' => 'shield-alert',
            'detail' => 'Permissions insuffisantes · REF-403-7C4D9E',
            'help' => [
                'Vérifiez que vous utilisez le bon compte.',
                'Demandez l\'accès à un administrateur si la ressource vous est nécessaire.',
                'Revenez au tableau de bord pour continuer votre travail.',
            ],
            'actions' => [
                ['label' => 'Retour au tableau de bord', 'url' => route('account.index'), 'primary' => true],
                ['label' => 'Aller à l\'accueil', 'url' => url('/')],
            ],
        ])
    </div>
@endsection
