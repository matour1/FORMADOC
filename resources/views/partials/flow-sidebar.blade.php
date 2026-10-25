@php
    // Étapes du parcours : Upload → En-tête, Pied & Validation → Mise en forme & Vérification → Export conforme
    $flowSteps = [
        1 => ['label' => 'Upload & Analyse',          'desc' => 'Téléversez et analysez votre rapport',              'icon' => 'file-up',      'route' => 'documents.create'],
        2 => ['label' => 'En-tête, Pied & Structure', 'desc' => 'Configurez en-tête/pied et vérifiez la conformité', 'icon' => 'check-square', 'route' => 'documents.show'],
        3 => ['label' => 'Mise en forme',             'desc' => 'Application de la norme académique',                'icon' => 'wand-2',       'route' => 'documents.processing'],
        4 => ['label' => 'Vérification & Export',     'desc' => 'Contrôle de conformité et téléchargement DOCX',     'icon' => 'download',     'route' => 'documents.export'],
    ];
    $activeStep = $activeStep ?? 1;
    $document = $document ?? null;
@endphp

{{-- Stepper du parcours (desktop + mobile) --}}
<div class="flow-steps" aria-label="Parcours de traitement">
    @foreach ($flowSteps as $step => $s)
        @php
            $isActive = ($step === $activeStep);
            $isCompleted = ($step < $activeStep);
            $hasDoc = $document !== null;
            $canNavigate = ($step === 1) || ($hasDoc && $step <= $activeStep);
            $href = $canNavigate ? route($s['route'], ($step === 1) ? [] : $document) : '#';
            $cls = $isActive ? 'active' : ($isCompleted ? 'done' : 'disabled');
        @endphp

        @if ($canNavigate && !$isActive)
            <a class="flow-step {{ $cls }}" href="{{ $href }}" title="{{ $s['desc'] }}">
                <span class="flow-step-num">{{ $isCompleted ? '✓' : $step }}</span>
                <span>{{ $step }}. {{ $s['label'] }}</span>
            </a>
        @else
            <span class="flow-step {{ $cls }}" title="{{ $s['desc'] }}">
                <span class="flow-step-num">{{ $isCompleted ? '✓' : $step }}</span>
                <span>{{ $step }}. {{ $s['label'] }}</span>
            </span>
        @endif

        @if ($step < 4)
            <span class="flow-step-sep" aria-hidden="true"></span>
        @endif
    @endforeach
</div>
