@extends('layouts.app')

@section('title', 'Délai d\'attente dépassé — 408')

@section('content')
    <div class="max-w-3xl" style="margin:0 auto;padding:2.5rem 0">
        @include('errors.partials.error-card', [
            'code' => '408',
            'title' => 'Délai d\'attente dépassé',
            'message' => 'Le serveur n\'a pas répondu à temps. Vérifiez votre connexion puis réessayez.',
            'icon' => 'warn',
            'iconSvg' => 'clock',
            'detail' => 'Timeout 60s · REF-408-3D6E9B',
            'help' => [
                'Vérifiez votre connexion Internet.',
                'Réessayez dans quelques instants.',
                'Pour un très gros document, patientez avant d\'actualiser.',
            ],
            'actions' => [
                ['label' => 'Réessayer', 'url' => 'javascript:location.reload()', 'primary' => true],
                ['label' => 'Tableau de bord', 'url' => route('account.index')],
            ],
        ])
    </div>
@endsection
