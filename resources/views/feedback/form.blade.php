@extends('layouts.app')

@section('title', 'Votre avis nous intéresse')

@section('content')
    <div class="max-w-container-max mx-auto px-margin-mobile md:px-margin-desktop py-gutter md:py-margin-desktop">
        <div class="max-w-2xl mx-auto">

            {{-- En-tête --}}
            <div class="text-center mb-8 md:mb-12">
                <p class="font-label-mono text-label-mono text-primary uppercase mb-2">Feedback</p>
                <h1 class="font-h1-mobile text-h1-mobile md:font-h1 md:text-h1 text-on-surface mb-4">
                    Votre avis nous intéresse
                </h1>
                <p class="font-body-md text-body-md text-on-surface-variant">
                    Votre feedback nous aide à améliorer FORMADOC. Merci de prendre
                    quelques minutes pour partager votre expérience !
                </p>
            </div>

            {{-- Alertes --}}
            @if ($errors->any())
                <div class="flex items-start gap-3 bg-error-container border border-error rounded-xl p-4 mb-6">
                    <span class="material-symbols-outlined text-error">error</span>
                    <ul class="text-body-md text-on-error-container">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if (session('success'))
                <div class="flex items-start gap-3 bg-surface-container-lowest border border-outline-variant rounded-xl p-4 mb-6">
                    <span class="material-symbols-outlined text-primary">check_circle</span>
                    <p class="text-body-md">{{ session('success') }}</p>
                </div>
            @endif

            <div class="bg-surface-container-lowest border border-outline-variant rounded-xl p-6 md:p-8">
                <form method="POST" action="{{ route('feedback.store') }}">
                    @csrf

                    {{-- Note (étoiles) --}}
                    <div class="mb-6">
                        <label class="font-label-mono text-label-mono text-secondary uppercase mb-2 block">Note</label>
                        <div class="star-rating flex items-center gap-2" id="star-rating">
                            @for ($i = 1; $i <= 5; $i++)
                                <input type="radio" name="note" value="{{ $i }}" id="star{{ $i }}"
                                       class="sr-only star-input" @checked(old('note') == $i) required>
                                <label for="star{{ $i }}" class="star-label cursor-pointer" data-value="{{ $i }}">
                                    <span class="material-symbols-outlined star-icon">star</span>
                                </label>
                            @endfor
                            <span class="font-caption text-caption text-on-surface-variant ml-3" id="star-caption">Sélectionnez une note</span>
                        </div>
                        @error('note')
                            <p class="font-caption text-caption text-error mt-2">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Email --}}
                    <div class="mb-6">
                        <label for="email" class="font-label-mono text-label-mono text-secondary uppercase mb-2 block">Votre email</label>
                        <input type="email"
                               class="w-full bg-surface-container-low border border-outline-variant rounded-lg px-3 py-3 text-body-md focus:outline-none focus:ring-2 focus:ring-primary @error('email') border-error @enderror"
                               id="email" name="email" value="{{ old('email') }}" required placeholder="vous@example.com">
                        @error('email')
                            <p class="font-caption text-caption text-error mt-2">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Recommandation --}}
                    <div class="mb-6">
                        <label class="flex items-start gap-3 cursor-pointer">
                            <input type="checkbox" name="recommander" value="1"
                                   class="mt-0.5 rounded border-outline-variant text-primary focus:ring-primary" @checked(old('recommander'))>
                            <span class="text-body-md text-on-surface">
                                Je recommanderais FORMADOC à un camarade
                            </span>
                        </label>
                    </div>

                    {{-- Avis --}}
                    <div class="mb-6">
                        <label for="avis" class="font-label-mono text-label-mono text-secondary uppercase mb-2 block">Votre avis</label>
                        <textarea class="w-full bg-surface-container-low border border-outline-variant rounded-lg px-3 py-3 text-body-md focus:outline-none focus:ring-2 focus:ring-primary @error('avis') border-error @enderror"
                                  id="avis" name="avis" rows="5" required
                                  placeholder="Décrivez votre expérience avec FORMADOC...">{{ old('avis') }}</textarea>
                        <p class="font-caption text-caption text-on-surface-variant mt-2">Minimum 10 caractères</p>
                        @error('avis')
                            <p class="font-caption text-caption text-error mt-2">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Problèmes rencontrés --}}
                    <div class="mb-6">
                        <label for="problemes_rencontres" class="font-label-mono text-label-mono text-secondary uppercase mb-2 block">Problèmes rencontrés (optionnel)</label>
                        <textarea class="w-full bg-surface-container-low border border-outline-variant rounded-lg px-3 py-3 text-body-md focus:outline-none focus:ring-2 focus:ring-primary @error('problemes_rencontres') border-error @enderror"
                                  id="problemes_rencontres" name="problemes_rencontres" rows="3"
                                  placeholder="Avez-vous rencontré des erreurs, bugs ou difficultés ?">{{ old('problemes_rencontres') }}</textarea>
                        @error('problemes_rencontres')
                            <p class="font-caption text-caption text-error mt-2">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex justify-end">
                        <button type="submit"
                                class="inline-flex items-center gap-2 bg-primary hover:bg-primary-fixed-variant text-on-primary px-6 py-3 rounded-xl font-body-md font-semibold transition-colors">
                            <span class="material-symbols-outlined">send</span>
                            Envoyer mon avis
                        </button>
                    </div>
                </form>
            </div>

        </div>
    </div>

    @push('scripts')
    <style>
        .star-label .star-icon {
            font-size: 40px;
            color: #c3c6d7;
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 48;
            transition: color 0.15s ease, font-variation-settings 0.15s ease, transform 0.15s ease;
        }
        .star-label.active .star-icon {
            color: #004ac6;
            font-variation-settings: 'FILL' 1, 'wght' 400, 'GRAD' 0, 'opsz' 48;
        }
        .star-label:hover .star-icon {
            transform: scale(1.1);
        }
    </style>
    <script>
        (function () {
            const container = document.getElementById('star-rating');
            if (!container) return;
            const labels = Array.from(container.querySelectorAll('.star-label'));
            const inputs = Array.from(container.querySelectorAll('.star-input'));
            const caption = document.getElementById('star-caption');
            const captions = {1: 'Très insatisfait', 2: 'Insatisfait', 3: 'Moyen', 4: 'Satisfait', 5: 'Très satisfait'};

            function paint(value) {
                labels.forEach(function (label) {
                    const v = parseInt(label.dataset.value, 10);
                    label.classList.toggle('active', v <= value);
                });
            }

            function selectedValue() {
                const checked = inputs.find(function (i) { return i.checked; });
                return checked ? parseInt(checked.value, 10) : null;
            }

            labels.forEach(function (label) {
                label.addEventListener('mouseenter', function () {
                    paint(parseInt(label.dataset.value, 10));
                });
                label.addEventListener('mouseleave', function () {
                    paint(selectedValue() || 0);
                });
                label.addEventListener('click', function () {
                    const v = parseInt(label.dataset.value, 10);
                    paint(v);
                    if (caption) caption.textContent = captions[v] || '';
                });
            });

            // État initial (re-validation)
            paint(selectedValue() || 0);
            if (caption && selectedValue()) {
                caption.textContent = captions[selectedValue()] || '';
            }
        })();
    </script>
    @endpush
@endsection
