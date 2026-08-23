@extends('layouts.app')

@section('title', 'Service indisponible — 503')

@section('content')
    <div class="max-w-3xl" style="margin:0 auto;padding:2.5rem 0">
        @include('errors.partials.error-card', [
            'code' => '503',
            'title' => 'Service indisponible',
            'message' => 'Le service est temporairement surchargé. Réessayez dans quelques instants — vos documents sont en sécurité.',
            'icon' => 'warn',
            'iconSvg' => 'cloud-off',
            'detail' => 'Retry-After : 120 · REF-503-7B9E2D',
            'help' => [
                'Réessayez dans quelques instants.',
                'Vos documents sont en sécurité pendant l\'indisponibilité.',
                'Si le problème persiste, contactez le support.',
            ],
            'actions' => [
                ['label' => 'Réessayer', 'url' => 'javascript:location.reload()', 'primary' => true],
                ['label' => 'Tableau de bord', 'url' => route('account.index')],
            ],
        ])
    </div>
@endsection
