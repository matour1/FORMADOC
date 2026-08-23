@extends('layouts.app')

@section('title', 'Erreur interne — 500')

@section('content')
    <div class="max-w-3xl" style="margin:0 auto;padding:2.5rem 0">
        @include('errors.partials.error-card', [
            'code' => '500',
            'title' => 'Erreur interne',
            'message' => 'Un problème technique empêche l\'affichage de cette page. Réessayez dans un instant — vos documents ne sont pas affectés.',
            'icon' => 'danger',
            'iconSvg' => 'alert-triangle',
            'help' => [
                'Réessayez dans quelques instants.',
                'Vos documents ne sont pas affectés.',
                'Contactez le support en indiquant la référence affichée ci-dessus.',
            ],
            'actions' => [
                ['label' => 'Retour au tableau de bord', 'url' => route('account.index'), 'primary' => true],
                ['label' => 'Contacter le support', 'url' => route('feedback.form')],
            ],
        ])
    </div>
@endsection
