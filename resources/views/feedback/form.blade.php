@extends('layouts.app')

@section('title', 'Votre avis nous intéresse')

@section('content')
    <div class="max-w-2xl mx-auto">

        {{-- En-tête --}}
        <div class="page-header" style="text-align:center;align-items:center;justify-content:center">
            <div>
                <span class="eyebrow">Feedback</span>
                <h1>Votre avis nous intéresse</h1>
                <p>
                    Votre feedback nous aide à améliorer FORMADOC. Merci de prendre
                    quelques minutes pour partager votre expérience !
                </p>
            </div>
        </div>

        <div class="card" style="padding:1.8rem 2rem">
            <form method="POST" action="{{ route('feedback.store') }}">
                @csrf

                {{-- Honeypot anti-spam (P2-3) : champ invisible, les robots le remplissent --}}
                <div class="honeypot" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden" aria-hidden="true">
                    <label for="website">Ne pas remplir ce champ</label>
                    <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
                </div>

                {{-- Note (étoiles) --}}
                <div class="form-group">
                    <label style="display:block">Note</label>
                    <div class="star-rating" style="display:flex;align-items:center;gap:.4rem;flex-wrap:wrap" id="star-rating">
                        @for ($i = 1; $i <= 5; $i++)
                            <input type="radio" name="note" value="{{ $i }}" id="star{{ $i }}"
                                   class="sr-only star-input" @checked(old('note') == $i) required>
                            <label for="star{{ $i }}" class="star-label" style="cursor:pointer" data-value="{{ $i }}">
                                <i data-lucide="star" class="star-icon" style="width:40px;height:40px"></i>
                            </label>
                        @endfor
                        {{-- La legende passe a la ligne sur ecran etroit.
                             Mesure avant correction : sur 360 px, les cinq etoiles
                             (200 px) plus leurs espacements et cette legende (79 px)
                             depassaient le conteneur de 10 px — assez pour creer une
                             barre de defilement horizontale sur toute la page.
                             `flex-wrap: wrap` laisse la legende descendre sous les
                             etoiles plutot que d'elargir la page.

                             NB : reduire la taille des etoiles sur mobile a ete
                             essaye puis retire. Les etoiles portent
                             `style="width:40px"` en attribut, et un style en ligne
                             prime sur toute media query : la regle ne s'appliquait
                             jamais (mesure : 40 px a toutes les largeurs). Du code
                             mort qui laissait croire a un comportement inexistant. --}}
                        <span style="font-size:.8rem;color:var(--color-text-muted);margin-left:.6rem;flex-basis:100%" id="star-caption">Sélectionnez une note</span>
                    </div>
                    @error('note')
                        <span class="field-error">{{ $message }}</span>
                    @enderror
                </div>

                {{-- Email --}}
                <div class="form-group">
                    <label for="email" style="display:block">Votre email</label>
                    <input type="email" class="form-control @error('email') is-invalid @enderror"
                           id="email" name="email" value="{{ old('email') }}" required placeholder="vous@example.com">
                    @error('email')
                        <span class="field-error">{{ $message }}</span>
                    @enderror
                </div>

                {{-- Recommandation --}}
                <div class="form-group">
                    <label style="display:flex;align-items:flex-start;gap:.6rem;cursor:pointer;font-weight:400;font-size:.9rem">
                        <input type="checkbox" name="recommander" value="1" style="margin-top:.2rem;accent-color:var(--color-primary)" @checked(old('recommander'))>
                        Je recommanderais FORMADOC à un camarade
                    </label>
                </div>

                {{-- Avis --}}
                <div class="form-group">
                    <label for="avis" style="display:block">Votre avis</label>
                    <textarea class="form-control @error('avis') is-invalid @enderror"
                              id="avis" name="avis" rows="5" required
                              placeholder="Décrivez votre expérience avec FORMADOC...">{{ old('avis') }}</textarea>
                    <p style="font-size:.75rem;color:var(--color-text-muted);margin-top:.3rem">Minimum 10 caractères</p>
                    @error('avis')
                        <span class="field-error">{{ $message }}</span>
                    @enderror
                </div>

                {{-- Problèmes rencontrés --}}
                <div class="form-group">
                    <label for="problemes_rencontres" style="display:block">Problèmes rencontrés (optionnel)</label>
                    <textarea class="form-control @error('problemes_rencontres') is-invalid @enderror"
                              id="problemes_rencontres" name="problemes_rencontres" rows="3"
                              placeholder="Avez-vous rencontré des erreurs, bugs ou difficultés ?">{{ old('problemes_rencontres') }}</textarea>
                    @error('problemes_rencontres')
                        <span class="field-error">{{ $message }}</span>
                    @enderror
                </div>

                <div style="display:flex;justify-content:flex-end">
                    <button type="submit" class="btn btn-primary">
                        <i data-lucide="send" style="width:16px;height:16px"></i>
                        Envoyer mon avis
                    </button>
                </div>
            </form>
        </div>

    </div>

    @push('scripts')
    <style>
        .star-label .star-icon {
            color: #c3c6d7;
            fill: none;
            transition: color 0.15s ease, fill 0.15s ease, transform 0.15s ease;
        }
        .star-label.active .star-icon {
            color: var(--color-primary);
            fill: var(--color-primary);
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
