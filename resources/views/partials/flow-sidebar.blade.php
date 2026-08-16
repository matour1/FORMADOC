@php
    // Étapes du parcours : Upload → Validation → Traitement → Export
    $flowSteps = [
        1 => ['label' => 'Upload',        'desc' => 'Téléversez votre rapport',        'icon' => 'upload_file',  'route' => 'documents.create'],
        2 => ['label' => 'Validation',    'desc' => 'Vérifiez la structure détectée',  'icon' => 'fact_check',   'route' => 'documents.show'],
        3 => ['label' => 'Traitement',    'desc' => 'Mise en forme automatique',        'icon' => 'auto_awesome', 'route' => 'documents.processing'],
        4 => ['label' => 'Export',        'desc' => 'Téléchargez votre DOCX',           'icon' => 'download',     'route' => 'documents.export'],
    ];
    $activeStep = $activeStep ?? 1;
    $document = $document ?? null;
@endphp

{{-- Barre latérale « Project Flow » (desktop) --}}
<aside class="hidden md:flex flex-col fixed left-0 top-16 bottom-0 w-64 bg-surface-container-low border-r border-outline-variant p-6 gap-1 overflow-y-auto">
    <p class="font-label-mono text-label-mono text-secondary uppercase mb-2">Project Flow</p>

    @foreach ($flowSteps as $step => $s)
        @php
            $isActive = ($step === $activeStep);
            $isCompleted = ($step < $activeStep);
            $hasDoc = $document !== null;
            $canNavigate = ($step === 1) || ($hasDoc && $step <= $activeStep);
            $href = $canNavigate ? route($s['route'], ($step === 1) ? [] : $document) : '#';
        @endphp

        @if ($isActive)
            {{-- Étape courante --}}
            <div class="flex items-start gap-4 p-4 bg-primary text-on-primary rounded-xl">
                <span class="material-symbols-outlined">{{ $s['icon'] }}</span>
                <div>
                    <p class="font-body-md text-body-md font-semibold">{{ $step }}. {{ $s['label'] }}</p>
                    <p class="font-caption text-caption opacity-90">{{ $s['desc'] }}</p>
                </div>
            </div>
        @elseif ($isCompleted)
            {{-- Étape terminée (cliquable) --}}
            <a href="{{ $href }}" class="flex items-start gap-4 p-4 rounded-xl hover:bg-surface-container transition-colors group">
                <span class="material-symbols-outlined text-primary">check_circle</span>
                <div>
                    <p class="font-body-md text-body-md font-semibold text-on-surface">{{ $step }}. {{ $s['label'] }}</p>
                    <p class="font-caption text-caption text-secondary">{{ $s['desc'] }}</p>
                </div>
            </a>
        @else
            {{-- Étape future (désactivée) --}}
            <div class="flex items-start gap-4 p-4 rounded-xl opacity-50 cursor-not-allowed">
                <span class="material-symbols-outlined">{{ $s['icon'] }}</span>
                <div>
                    <p class="font-body-md text-body-md font-semibold">{{ $step }}. {{ $s['label'] }}</p>
                    <p class="font-caption text-caption">{{ $s['desc'] }}</p>
                </div>
            </div>
        @endif
    @endforeach
</aside>

{{-- Stepper horizontal (mobile) --}}
<div class="md:hidden flex items-center justify-between w-full bg-surface-container-low border-b border-outline-variant px-margin-mobile py-3 gap-2 overflow-x-auto">
    @foreach ($flowSteps as $step => $s)
        @php
            $isActive = ($step === $activeStep);
            $isCompleted = ($step < $activeStep);
        @endphp
        <div class="flex items-center gap-2 shrink-0">
            <div class="w-8 h-8 rounded-full flex items-center justify-center text-caption font-semibold
                {{ $isActive ? 'bg-primary text-on-primary' : ($isCompleted ? 'bg-primary-fixed text-on-primary-fixed-variant' : 'bg-surface-container-high text-outline') }}">
                @if ($isCompleted)
                    <span class="material-symbols-outlined text-[16px]">check</span>
                @else
                    {{ $step }}
                @endif
            </div>
            <span class="font-caption text-caption {{ $isActive ? 'text-primary font-semibold' : ($isCompleted ? 'text-secondary' : 'text-outline') }}">
                {{ $s['label'] }}
            </span>
        </div>
        @if ($step < 4)
            <span class="material-symbols-outlined text-outline text-[16px] shrink-0">chevron_right</span>
        @endif
    @endforeach
</div>
