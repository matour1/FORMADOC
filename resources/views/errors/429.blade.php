@extends('layouts.app')

@section('title', 'Trop de requêtes — 429')

@section('content')
    <div class="max-w-3xl" style="margin:0 auto;padding:2.5rem 0">
        @include('errors.partials.error-card', [
            'code' => '429',
            'title' => 'Trop de requêtes',
            'message' => 'Vous avez dépassé la limite de requêtes. Réessayez dans <strong id="rateLimitTimer">60s</strong>.',
            'icon' => 'warn',
            'iconSvg' => 'gauge',
            'detail' => 'Limite : 60 requêtes / minute · REF-429-8C2E5A',
            'help' => [
                'Attendez la fin du compte à rebours avant de réessayer.',
                'Réduisez le nombre de requêtes simultanées.',
                'Les documents en cours de traitement ne sont pas affectés.',
            ],
            'actions' => [
                ['label' => 'Réessayer', 'url' => 'javascript:location.reload()', 'primary' => true, 'id' => 'rateLimitRetry'],
                ['label' => 'Tableau de bord', 'url' => route('account.index')],
            ],
        ])
    </div>
@endsection

@push('scripts')
    <script>
        (function () {
            const timer = document.getElementById('rateLimitTimer');
            const btn = document.getElementById('rateLimitRetry');
            if (!timer) return;
            let seconds = 60;
            const interval = setInterval(() => {
                seconds--;
                if (seconds <= 0) {
                    clearInterval(interval);
                    timer.textContent = '0s';
                    if (btn) { btn.disabled = false; btn.classList.remove('disabled'); }
                } else {
                    timer.textContent = seconds + 's';
                    if (btn) { btn.disabled = true; btn.classList.add('disabled'); }
                }
            }, 1000);
        })();
    </script>
@endpush
