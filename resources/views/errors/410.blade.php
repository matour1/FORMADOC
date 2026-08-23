@extends('layouts.app')

@section('title', 'Ressource supprimée — 410')

@section('content')
    <div class="max-w-3xl" style="margin:0 auto;padding:2.5rem 0">
        @include('errors.partials.error-card', [
            'code' => '410',
            'title' => 'Ressource supprimée',
            'message' => 'Ce document a été supprimé ou n\'est plus disponible. Retrouvez vos autres documents dans votre bibliothèque.',
            'icon' => 'info',
            'iconSvg' => 'archive',
            'detail' => 'Document supprimé · REF-410-1C4F7A',
            'help' => [
                'Retrouvez vos documents actifs dans « Mes documents ».',
                'Créez un nouveau document si le contenu a été archivé.',
                'Contactez le support pour récupérer un document supprimé récemment.',
            ],
            'actions' => [
                ['label' => 'Mes documents', 'url' => route('documents.create'), 'primary' => true],
                ['label' => 'Aller à l\'accueil', 'url' => url('/')],
            ],
        ])
    </div>
@endsection
