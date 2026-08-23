@extends('layouts.app')

@section('title', 'Délai de la passerelle dépassé — 504')

@section('content')
    <div class="max-w-3xl" style="margin:0 auto;padding:2.5rem 0">
        @include('errors.partials.error-card', [
            'code' => '504',
            'title' => 'Délai de la passerelle dépassé',
            'message' => 'Le serveur a mis trop de temps à répondre. Un très gros document peut prendre plus de temps — réessayez dans un instant.',
            'icon' => 'danger',
            'iconSvg' => 'timer',
            'detail' => 'Gateway timeout · REF-504-9E2B5D',
            'help' => [
                'Réessayez — un document volumineux peut prendre plus de temps.',
                'Vérifiez votre connexion Internet.',
                'Contactez le support si le problème persiste.',
            ],
            'actions' => [
                ['label' => 'Réessayer', 'url' => 'javascript:location.reload()', 'primary' => true],
                ['label' => 'Mes documents', 'url' => route('documents.create')],
            ],
        ])
    </div>
@endsection
