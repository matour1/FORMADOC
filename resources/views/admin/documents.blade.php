@extends('layouts.admin')

@section('title', 'Documents')

@section('content')
    <div class="page-head">
        <span class="eyebrow">Exploitation</span>
        <h1>Tous les documents</h1>
        <p>
            Tous utilisateurs confondus. Vue de lecture seule : aucun écran d'administration ne
            modifie les données d'un utilisateur.
        </p>
    </div>

    <form method="GET" style="display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap;margin-bottom:1.25rem">
        <div>
            <label for="status" style="display:block;font-size:.78rem;font-weight:600;margin-bottom:.25rem">Statut</label>
            <select id="status" name="status" class="form-control" style="min-width:180px">
                <option value="">Tous</option>
                @foreach (['pending', 'processing', 'detected', 'validated', 'generated', 'ready', 'failed'] as $statutPossible)
                    <option value="{{ $statutPossible }}" @selected($statut === $statutPossible)>
                        {{ $statutPossible }}
                    </option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn btn-secondary btn-sm">Filtrer</button>
        @if ($statut)
            <a href="{{ route('admin.documents') }}" class="btn btn-ghost btn-sm">Tout</a>
        @endif
    </form>

    <section class="card">
        @if ($documents->isEmpty())
            <p style="font-size:.88rem;color:var(--color-text-muted);margin:0">Aucun document.</p>
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Fichier</th>
                            <th>Propriétaire</th>
                            <th>Statut</th>
                            <th>Taille</th>
                            <th>Analyse</th>
                            <th>Ajouté</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($documents as $document)
                            @php
                                $proprietaireId = (int) ($document->metadata['user_id'] ?? 0);
                                $structurel = $document->structure;
                            @endphp
                            <tr>
                                <td data-label="#" style="font-size:.78rem;color:var(--color-text-muted)">{{ $document->id }}</td>
                                <td data-label="Fichier" style="font-size:.82rem;word-break:break-word">
                                    {{ \Illuminate\Support\Str::limit($document->filename, 44) }}
                                </td>
                                <td data-label="Propriétaire" style="font-size:.78rem">
                                    {{ $proprietaires[$proprietaireId] ?? ($proprietaireId > 0 ? '#'.$proprietaireId : 'anonyme') }}
                                </td>
                                <td data-label="Statut" style="font-size:.78rem">{{ $document->status }}</td>
                                <td data-label="Taille" style="font-size:.78rem;white-space:nowrap">
                                    {{ number_format(((int) ($document->metadata['size'] ?? 0)) / 1024, 0, ',', ' ') }} Ko
                                </td>
                                <td data-label="Analyse" style="font-size:.78rem">
                                    @if ($structurel?->structuralDocument() !== null)
                                        <span style="color:var(--color-success)">native</span>
                                    @elseif ($structurel !== null)
                                        <span style="color:var(--color-text-muted)">historique</span>
                                    @else
                                        <span style="color:var(--color-text-muted)">—</span>
                                    @endif
                                </td>
                                <td data-label="Ajouté" style="font-size:.78rem;color:var(--color-text-muted);white-space:nowrap">
                                    {{ $document->created_at?->format('d/m/Y') }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div style="margin-top:1rem">
                {{ $documents->links() }}
            </div>
        @endif
    </section>
@endsection
