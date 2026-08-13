@extends('layouts.app')

@section('content')
<div class="container mt-5 mb-5">
    <div class="row justify-content-center">
        <div class="col-md-7">
            <div class="card shadow-sm">
                <div class="card-body p-5">
                    <h2 class="card-title mb-4">Votre avis nous intéresse</h2>
                    
                    @if ($errors->any())
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <strong>Erreurs :</strong>
                            <ul class="mb-0 mt-2">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    @endif

                    @if (session('success'))
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            {{ session('success') }}
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    @endif

                    <p class="text-muted mb-4">Votre feedback nous aide à améliorer FORMADOC. Merci de prendre quelques minutes pour partager votre expérience !</p>

                    <form method="POST" action="{{ route('feedback.store') }}" class="needs-validation">
                        @csrf
                        
                        <!-- Email -->
                        <div class="mb-4">
                            <label for="email" class="form-label fw-bold">Votre email</label>
                            <input 
                                type="email" 
                                class="form-control @error('email') is-invalid @enderror" 
                                id="email" 
                                name="email" 
                                value="{{ old('email') }}"
                                required
                                placeholder="vous@example.com"
                            >
                            @error('email')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>

                        <!-- Note (étoiles) -->
                        <div class="mb-4">
                            <label class="form-label fw-bold">Note</label>
                            <div class="star-rating d-flex gap-2">
                                @for ($i = 1; $i <= 5; $i++)
                                    <input 
                                        type="radio" 
                                        name="note" 
                                        value="{{ $i }}" 
                                        id="star{{ $i }}"
                                        @if(old('note') == $i) checked @endif
                                        required
                                        class="form-check-input"
                                        style="width: 30px; height: 30px; cursor: pointer;"
                                    >
                                    <label for="star{{ $i }}" class="form-check-label fs-3" style="cursor: pointer; color: #ffc107;">
                                        ★
                                    </label>
                                @endfor
                            </div>
                            @error('note')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>

                        <!-- Avis -->
                        <div class="mb-4">
                            <label for="avis" class="form-label fw-bold">Votre avis</label>
                            <textarea 
                                class="form-control @error('avis') is-invalid @enderror" 
                                id="avis" 
                                name="avis" 
                                rows="5"
                                required
                                placeholder="Décrivez votre expérience avec FORMADOC..."
                            >{{ old('avis') }}</textarea>
                            <small class="text-muted d-block mt-2">Minimum 10 caractères</small>
                            @error('avis')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>

                        <!-- Problèmes rencontrés -->
                        <div class="mb-4">
                            <label for="problemes_rencontres" class="form-label fw-bold">Problèmes rencontrés (optionnel)</label>
                            <textarea 
                                class="form-control @error('problemes_rencontres') is-invalid @enderror" 
                                id="problemes_rencontres" 
                                name="problemes_rencontres" 
                                rows="3"
                                placeholder="Avez-vous rencontré des erreurs, bugs ou difficultés ?"
                            >{{ old('problemes_rencontres') }}</textarea>
                            @error('problemes_rencontres')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>

                        <!-- Bouton submit -->
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary btn-lg">
                                Envoyer mon avis
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .star-rating {
        display: flex;
        gap: 10px;
        align-items: center;
    }
    
    .star-rating input[type="radio"] {
        display: none;
    }
    
    .star-rating label {
        font-size: 2.5rem;
        cursor: pointer;
        color: #ccc;
        transition: color 0.2s;
    }
    
    .star-rating input[type="radio"]:checked ~ label,
    .star-rating input[type="radio"]:checked ~ label {
        color: #ffc107;
    }
    
    /* Hover effect */
    .star-rating:has(input:hover) label {
        color: #ffc107;
    }
</style>
@endsection
