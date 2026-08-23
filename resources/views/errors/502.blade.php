@extends('layouts.app')

@section('title', 'Passerelle incorrecte — 502')

@section('content')
    <div class="max-w-3xl" style="margin:0 auto;padding:2.5rem 0">
        @include('errors.partials.error-card', [
            'code' => '502',
            'title' => 'Passerelle incorrecte',
            'message' => 'Un serveur intermédiaire a reçu une réponse invalide. Réessayez dans quelques minutes.',
            'icon' => 'danger',
            'iconSvg' => 'server',
            'detail' => 'Upstream timeout · REF-502-2A5C8E',
            'help' => [
                'Réessayez dans quelques minutes.',
                'Vérifiez votre connexion Internet.',
                'Contactez le support si le problème persiste.',
            ],
            'actions' => [
                ['label' => 'Réessayer', 'url' => 'javascript:location.reload()', 'primary' => true],
                ['label' => 'Tableau de bord', 'url' => route('account.index')],
            ],
        ])
    </div>
@endsection
