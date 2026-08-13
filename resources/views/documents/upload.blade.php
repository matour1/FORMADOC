@extends('layouts.app')

@section('title', 'Analyser un rapport')

@section('content')
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-8">

            <div class="card shadow-sm border-0">
                <div class="card-body p-4 p-md-5">
                    <h1 class="h3 mb-2">Analyser un rapport</h1>
                    <p class="text-muted mb-4">
                        Déposez votre rapport (stage, projet, mémoire) au format
                        <strong>.docx</strong>, <strong>.doc</strong> ou <strong>.txt</strong>.
                        Nous détecterons automatiquement sa structure : titres, hiérarchie et légendes.
                    </p>

                    @if (session('success'))
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            {{ session('success') }}
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fermer"></button>
                        </div>
                    @endif

                    @if ($errors->any())
                        <div class="alert alert-danger">
                            <ul class="mb-0">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <form action="{{ route('documents.upload') }}" method="POST" enctype="multipart/form-data">
                        @csrf

                        <div class="mb-3">
                            <label for="document" class="form-label">Votre rapport</label>
                            <input type="file"
                                   class="form-control @error('document') is-invalid @enderror"
                                   id="document"
                                   name="document"
                                   accept=".docx,.doc,.txt"
                                   required>
                            <div class="form-text">Taille maximale : 50 Mo.</div>
                            @error('document')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                            <button type="submit" class="btn btn-primary px-4">
                                Analyser le document
                            </button>
                        </div>
                    </form>
                </div>
            </div>

        </div>
    </div>
</div>
@endsection
