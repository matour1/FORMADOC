@extends('layouts.app')

@section('title', 'Passages à confirmer')

@section('content')
    @include('partials.flow-sidebar', ['activeStep' => 2, 'document' => $document])

    @php
        $enAttente = $pending ?? [];
        $total = count($enAttente);
    @endphp

    <div class="max-w-4xl">

        <div class="page-header">
            <div>
                <span class="eyebrow">Étape 2 / 4 — Confirmation</span>
                <h1>Passages à confirmer</h1>
                <p style="word-break:break-word">{{ $document->filename }}</p>
            </div>
        </div>

        {{-- Bandeau d'état : ce que l'utilisateur va faire, et pourquoi. --}}
        @if ($total > 0)
            <div class="banner">
                <i data-lucide="circle-help"></i>
                <div>
                    <strong>{{ $total }} passage{{ $total > 1 ? 's' : '' }} à confirmer</strong>
                    <div style="margin-top:.35rem;font-size:.88rem;color:var(--color-text-secondary)">
                        La détection automatique a classé le reste du document avec assurance.
                        Ces passages-là sont incertains : confirmez leur nature pour que la
                        mise en forme et la numérotation soient justes. Une réponse ne concerne
                        <strong>que le passage affiché</strong>.
                    </div>
                </div>
            </div>
        @elseif ($structureAvailable)
            <div class="banner banner-success">
                <i data-lucide="badge-check"></i>
                <div>
                    <strong>Aucun passage en attente</strong>
                    <div style="margin-top:.35rem;font-size:.88rem;color:var(--color-text-secondary)">
                        La structure détectée ne comporte plus d'incertitude.
                    </div>
                </div>
            </div>
        @else
            <div class="card">
                <p style="color:var(--color-text-secondary);font-size:.9rem;margin:0">
                    Ce document n'a pas de structure récente : relancez son analyse pour
                    pouvoir confirmer les passages.
                </p>
            </div>
        @endif

        @if (session('success'))
            <div class="banner banner-success" style="margin-top:1rem">
                <i data-lucide="check"></i>
                <div>{{ session('success') }}</div>
            </div>
        @endif

        @if (session('warning'))
            <div class="banner" style="margin-top:1rem">
                <i data-lucide="alert-triangle"></i>
                <div>{{ session('warning') }}</div>
            </div>
        @endif

        @error('clarifications')
            <div class="banner" style="margin-top:1rem;border-color:var(--color-danger)">
                <i data-lucide="alert-circle"></i>
                <div>{{ $message }}</div>
            </div>
        @enderror

        {{-- Formulaire : une réponse par passage, envoyée en une fois. --}}
        @if ($total > 0)
            <form method="POST" action="{{ route('documents.clarifications.store', $document) }}" style="margin-top:1.25rem">
                @csrf

                @foreach ($enAttente as $index => $clarification)
                    @php
                        $options = is_array($clarification->options) ? $clarification->options : [];
                        $confiance = (float) $clarification->confidence;
                        // Libellé lisible de la confiance : un pourcentage parle
                        // plus qu'un décimal pour un utilisateur non technique.
                        $niveau = $confiance >= 0.75 ? 'moyenne' : 'faible';
                    @endphp

                    <section class="card" style="margin-bottom:1rem">
                        <div style="display:flex;align-items:flex-start;gap:.6rem;margin-bottom:.75rem">
                            <span class="eyebrow" style="flex-shrink:0">Passage {{ $index + 1 }}</span>
                            <span style="font-size:.75rem;color:var(--color-text-muted);margin-left:auto">
                                confiance {{ $niveau }} ({{ number_format($confiance * 100, 0) }} %)
                            </span>
                        </div>

                        {{-- Extrait : c'est le seul repère dont l'utilisateur dispose.
                             Doit rester sélectionnable pour qu'il puisse le copier. --}}
                        <blockquote style="margin:0 0 .9rem;padding:.7rem .9rem;background:var(--color-surface-2);border-left:3px solid var(--color-primary);border-radius:4px;font-size:.88rem;white-space:pre-wrap;word-break:break-word">
                            {{ $clarification->excerpt }}
                        </blockquote>

                        @if ($clarification->reason)
                            <p style="font-size:.78rem;color:var(--color-text-muted);margin:0 0 .9rem">
                                {{ $clarification->reason }}
                            </p>
                        @endif

                        <fieldset style="border:0;padding:0;margin:0">
                            <legend style="font-size:.85rem;font-weight:600;margin-bottom:.5rem">
                                {{ $clarification->question }}
                            </legend>

                            <div style="display:flex;flex-wrap:wrap;gap:.5rem">
                                @foreach ($options as $option)
                                    @php $id = 'c'.$clarification->id.'_'.md5($option); @endphp
                                    <input type="radio"
                                           name="answers[{{ $clarification->block_id }}]"
                                           id="{{ $id }}"
                                           value="{{ $option }}"
                                           style="position:absolute;opacity:0;width:0;height:0"
                                           {{ old('answers.'.$clarification->block_id) === $option ? 'checked' : '' }}>
                                    <label for="{{ $id }}"
                                           style="cursor:pointer;padding:.45rem .85rem;border:1px solid var(--color-border);border-radius:6px;font-size:.85rem;background:var(--color-surface)">
                                        {{ $option }}
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                    </section>
                @endforeach

                <div style="display:flex;flex-wrap:wrap;gap:.6rem;align-items:center;margin-top:1.25rem">
                    <button type="submit" class="btn btn-primary">
                        <i data-lucide="check" style="width:16px;height:16px"></i>
                        Enregistrer les confirmations
                    </button>
                    <a href="{{ route('documents.show', $document) }}" class="btn btn-ghost">
                        Retour au document
                    </a>
                    <span style="font-size:.78rem;color:var(--color-text-muted);margin-left:auto">
                        Les passages laissés sans réponse resteront à confirmer.
                    </span>
                </div>
            </form>
        @endif

        {{-- Réponses déjà données : les masquer donnerait l'impression d'un choix
             irréversible, alors que l'utilisateur peut vouloir vérifier. --}}
        @if (($answered ?? collect())->isNotEmpty())
            <section class="card" style="margin-top:1.5rem">
                <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.6rem">
                    <i data-lucide="history" style="width:18px;height:18px;color:var(--color-text-muted)"></i>
                    <h2 class="card-title" style="margin:0">Déjà confirmés</h2>
                </div>

                <ul style="margin:0;padding-left:1.1rem;font-size:.85rem;color:var(--color-text-secondary)">
                    @foreach ($answered as $reponse)
                        <li style="margin-bottom:.4rem">
                            « {{ \Illuminate\Support\Str::limit($reponse->excerpt, 70) }} »
                            &nbsp;→&nbsp;<strong>{{ $reponse->answer_raw }}</strong>
                            <span style="font-size:.75rem;color:var(--color-text-muted)">
                                ({{ $reponse->answered_at?->format('d/m/Y H:i') }})
                            </span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <div style="display:flex;flex-wrap:wrap;gap:.6rem;margin-top:1.4rem">
            <a href="{{ route('documents.show', $document) }}" class="btn btn-ghost">
                <i data-lucide="arrow-left" style="width:16px;height:16px"></i>
                Retour au document
            </a>
        </div>
    </div>

    @push('scripts')
    <script>
        // Retour visuel de la sélection : les boutons radio sont masqués
        // (accessibilité clavier conservée), donc l'état doit être visible.
        document.querySelectorAll('input[type="radio"]').forEach(function (radio) {
            const sync = function () {
                document.querySelectorAll('input[name="' + radio.name + '"]').forEach(function (r) {
                    const label = document.querySelector('label[for="' + r.id + '"]');
                    if (!label) return;
                    label.style.borderColor = r.checked ? 'var(--color-primary)' : 'var(--color-border)';
                    label.style.fontWeight = r.checked ? '600' : '400';
                });
            };
            radio.addEventListener('change', sync);
            if (radio.checked) sync();
        });
    </script>
    @endpush
@endsection
