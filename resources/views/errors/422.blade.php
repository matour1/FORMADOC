@extends('layouts.app')

@section('title', 'Données non valides — 422')

@section('content')
    <div class="max-w-3xl" style="margin:0 auto;padding:2.5rem 0">
        @include('errors.partials.error-card', [
            'code' => '422',
            'title' => 'Données non valides',
            'message' => 'Le fichier envoyé ne peut pas être traité : format non pris en charge ou document corrompu. Vérifiez le fichier puis réessayez.',
            'icon' => 'danger',
            'iconSvg' => 'file-warning',
            'detail' => 'Format non pris en charge · REF-422-6B9D3F',
            'help' => [
                'Vérifiez que le fichier est au format .docx ou .pdf.',
                'Assurez-vous que le fichier n\'est pas corrompu (ouvrez-le pour vérifier).',
                'Réduisez la taille du fichier si nécessaire (limite 50 Mo).',
            ],
            'actions' => [
                ['label' => 'Téléverser à nouveau', 'url' => route('documents.create'), 'primary' => true],
                ['label' => 'Mes documents', 'url' => route('documents.create')],
            ],
        ])
    </div>
@endsection
