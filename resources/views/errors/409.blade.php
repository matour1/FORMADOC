@extends('layouts.app')

@section('title', 'Conflit de version — 409')

@section('content')
    <div class="max-w-3xl" style="margin:0 auto;padding:2.5rem 0">
        @include('errors.partials.error-card', [
            'code' => '409',
            'title' => 'Conflit de version',
            'message' => 'Une version plus récente de ce document existe déjà. Ouvrez la version à jour pour continuer.',
            'icon' => 'warn',
            'iconSvg' => 'refresh-cw',
            'detail' => 'Version 3 vs 4 · REF-409-5A8B2E',
            'help' => [
                'Ouvrez la version la plus récente depuis « Mes documents ».',
                'Fusionnez vos modifications sur la version à jour.',
                'Contactez le support en cas de doute sur la version à conserver.',
            ],
            'actions' => [
                ['label' => 'Voir mes documents', 'url' => route('documents.create'), 'primary' => true],
                ['label' => 'Tableau de bord', 'url' => route('account.index')],
            ],
        ])
    </div>
@endsection
