@extends('layouts.app')

@section('title', 'Créer une page de garde à partir d\'un exemple')

@section('content')
@php
    $zonesByType = [];
    $zoneByLine = [];
    foreach ($detection['zones'] ?? [] as $zone) {
        $zonesByType[$zone['type']] = $zone['value'] ?? '';
        $zoneByLine[(int) $zone['line_index']] = $zone;
    }

    $roleLabels = [
        'nom' => "Nom de l'auteur",
        'titre' => 'Titre du document',
        'encadrant' => 'Encadrant / Directeur',
        'date' => 'Année académique / Date',
    ];
@endphp

<div class="max-w-4xl">
    <div class="page-header">
        <div>
            <span class="eyebrow">Pages de garde</span>
            <h1>Créer une page de garde à partir d'un exemple</h1>
            <p>
                Fournissez une couverture existante : FORMADOC détecte automatiquement
                les zones de texte et les en-têtes, puis remplace les valeurs par vos
                informations tout en conservant la mise en forme.
            </p>
        </div>
        <a href="{{ route('cover-templates.index') }}" class="btn btn-ghost">
            Annuler
        </a>
    </div>

    @if ($detection === null)
        {{-- Étape 1 : fournir l'exemple --}}
        <div class="card">
            <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.4rem">
                <i data-lucide="scan-text" style="width:19px;height:19px;color:var(--color-primary)"></i>
                <h2 class="card-title">1 · Téléverser un exemple de couverture</h2>
            </div>
            <p style="color:var(--color-text-secondary);font-size:.85rem;margin-bottom:1.1rem">
                Un fichier Word (.docx) représentant la page de garde que vous souhaitez
                reproduire. La structure, les polices, tailles, couleurs et alignements
                seront conservés.
            </p>

            <form method="POST" action="{{ route('cover-templates.detect-example') }}" enctype="multipart/form-data">
                @csrf
                <label for="cover" class="dropzone" id="coverDropzone" style="cursor:pointer">
                    <span class="dz-icon" id="dzIcon"><i data-lucide="file-up" style="width:26px;height:26px"></i></span>
                    <span class="dz-title" id="dzTitle">Glissez-déposez votre couverture ici</span>
                    <span class="dz-sub" id="dzSub">ou cliquez pour parcourir vos fichiers</span>
                    <span class="dz-formats" id="dzFormats">.DOCX — 50 Mo max</span>
                    <input type="file"
                           class="sr-only @error('cover') is-invalid @enderror"
                           id="cover"
                           name="cover"
                           accept=".docx"
                           required>
                </label>
                @error('cover')
                    <span class="field-error">{{ $message }}</span>
                @enderror

                <div style="display:flex;justify-content:flex-end;margin-top:1.4rem">
                    <button type="submit" class="btn btn-primary">
                        <i data-lucide="scan-search" style="width:17px;height:17px"></i>
                        Détecter les zones
                    </button>
                </div>
            </form>

            @push('scripts')
            <script>
                // Indicateur visuel : affiche le nom du fichier sélectionné
                // dans la dropzone (feedback immédiat de l'upload).
                (function () {
                    const input = document.getElementById('cover');
                    const dropzone = document.getElementById('coverDropzone');
                    const title = document.getElementById('dzTitle');
                    const sub = document.getElementById('dzSub');
                    const icon = document.getElementById('dzIcon');
                    if (!input || !dropzone) return;

                    input.addEventListener('change', function () {
                        const file = input.files && input.files[0];
                        if (!file) {
                            dropzone.classList.remove('has-file');
                            title.textContent = 'Glissez-déposez votre couverture ici';
                            sub.textContent = 'ou cliquez pour parcourir vos fichiers';
                            return;
                        }
                        dropzone.classList.add('has-file');
                        title.textContent = 'Fichier choisi : ' + file.name;
                        sub.textContent = (file.size / 1024 / 1024).toFixed(2) + ' Mo — cliquez pour changer';
                        if (icon) {
                            icon.innerHTML = '<i data-lucide="file-check" style="width:26px;height:26px"></i>';
                            if (window.lucide) lucide.createIcons();
                        }
                    });

                    // Si le navigateur restaure la sélection (retour arrière)
                    if (input.files && input.files.length > 0) {
                        input.dispatchEvent(new Event('change'));
                    }
                })();
            </script>
            @endpush
        </div>
    @else
        {{-- Étape 2 : zones détectées + saisie des informations --}}
        <div x-data="fromExample({{ \Illuminate\Support\Js::from([
            'lines' => $detection['lines'],
            'zones' => $detection['zones'],
            'values' => [
                'nom' => $zonesByType['nom'] ?? '',
                'titre' => $zonesByType['titre'] ?? '',
                'encadrant' => $zonesByType['encadrant'] ?? '',
                'date' => $zonesByType['date'] ?? '',
            ],
        ]) }})">

            {{-- Récapitulatif des zones détectées --}}
            <div class="card" style="margin-bottom:1.3rem">
                <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.4rem">
                    <i data-lucide="list-checks" style="width:19px;height:19px;color:var(--color-primary)"></i>
                    <h2 class="card-title">2 · Zones détectées</h2>
                </div>
                <p style="color:var(--color-text-secondary);font-size:.85rem;margin-bottom:1rem">
                    <strong>{{ count($detection['zones'] ?? []) }}</strong> zone(s) reconnue(s) dans
                    « <em>{{ $exampleName }}</em> ». Les valeurs ci-dessous ont été pré-remplies
                    d'après l'exemple : remplacez-les par vos informations.
                </p>

                <div style="display:flex;flex-direction:column;gap:.45rem;max-height:280px;overflow-y:auto;border:1px solid var(--color-border);border-radius:var(--radius-sm);padding:.7rem .85rem;background:var(--color-surface-2)">
                    <template x-for="(line, i) in lines" :key="i">
                        <div style="display:flex;align-items:center;gap:.55rem;font-size:.82rem">
                            <span x-show="line.role" class="eyebrow" style="margin:0;flex-shrink:0"
                                  x-text="line.role ? roleLabel(line.role) : ''"></span>
                            <span x-show="!line.role" style="width:72px;flex-shrink:0"></span>
                            <span style="min-width:0" x-text="line.text"></span>
                        </div>
                    </template>
                </div>
            </div>

            {{-- Formulaire de saisie --}}
            <form method="POST" action="{{ route('cover-templates.store-from-example') }}" class="card">
                @csrf

                <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:1rem">
                    <i data-lucide="user-pen" style="width:19px;height:19px;color:var(--color-primary)"></i>
                    <h2 class="card-title" style="margin-bottom:0">Vos informations</h2>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.9rem">
                    <label class="form-group" style="grid-column:1 / -1">
                        <span>Nom du modèle</span>
                        <input type="text" name="name" value="{{ old('name', 'Page de garde — ' . $exampleName) }}"
                               required maxlength="120" class="form-control"
                               placeholder="Ex. Couverture de mémoire">
                    </label>

                    @foreach (['nom', 'titre', 'encadrant', 'date'] as $role)
                        <label class="form-group">
                            <span>
                                {{ $roleLabels[$role] }}
                                @if (array_key_exists($role, $zonesByType))
                                    <span class="eyebrow" style="margin-left:.4rem;font-size:.65rem">détecté</span>
                                @endif
                            </span>
                            <input type="text" name="{{ $role }}" x-model="values.{{ $role }}"
                                   maxlength="255" class="form-control"
                                   placeholder="{{ $roleLabels[$role] }}">
                        </label>
                    @endforeach
                </div>

                <label style="display:inline-flex;align-items:center;gap:.5rem;margin-top:1rem;font-size:.88rem;cursor:pointer">
                    <input type="checkbox" name="is_public" value="1" checked style="accent-color:var(--color-primary);width:16px;height:16px">
                    Modèle public (visible par tous)
                </label>

                {{-- Aperçu live : valeurs remplacées --}}
                <div style="margin-top:1.3rem;border-top:1px solid var(--color-border);padding-top:1.1rem">
                    <p style="font-weight:600;font-size:.85rem;margin-bottom:.6rem">Aperçu du résultat</p>
                    <div style="background:#fff;border:1px solid var(--color-border);border-radius:var(--radius-sm);padding:1.2rem 1.4rem">
                        <template x-for="(line, i) in lines" :key="'p-' + i">
                            <div style="display:flex;justify-content:center;margin:.3rem 0"
                                 :style="previewStyle(line)">
                                <span x-text="renderLine(line, i)"></span>
                            </div>
                        </template>
                    </div>
                    <p style="margin-top:.6rem;font-size:.72rem;color:var(--color-text-muted)">
                        Les zones remplacées deviennent des placeholders réutilisables
                        (@{{nom}}, @{{titre}}…), complétés à l'étape d'export.
                    </p>
                </div>

                <div style="display:flex;justify-content:flex-end;gap:.6rem;margin-top:1.4rem">
                    <a href="{{ route('cover-templates.index') }}" class="btn btn-secondary">Annuler</a>
                    <button type="submit" class="btn btn-primary">
                        <i data-lucide="save" style="width:17px;height:17px"></i>
                        Créer la page de garde
                    </button>
                </div>
            </form>
        </div>
    @endif
</div>

@push('scripts')
<script>
    function fromExample(data) {
        return {
            lines: data.lines || [],
            zones: data.zones || [],
            values: data.values || {},

            roleLabel(role) {
                const labels = {
                    nom: "Nom de l'auteur",
                    titre: 'Titre',
                    encadrant: 'Encadrant',
                    date: 'Date',
                };
                return labels[role] || role;
            },

            zoneForLine(i) {
                return (this.zones || []).find(z => parseInt(z.line_index) === i) || null;
            },

            renderLine(line, i) {
                const zone = this.zoneForLine(i);
                if (!zone) return line.text || '';
                const value = (this.values[zone.type] || '').trim();
                const label = (zone.label || '').trim();
                return label ? label + ' ' + value : value;
            },

            previewStyle(line) {
                const s = line.styles || {};
                const font = (s.font && s.font.basic) || {};
                const style = {
                    'font-weight': (s.font && s.font.style && s.font.style.bold) ? 'bold' : 'normal',
                    'font-style': (s.font && s.font.style && s.font.style.italic) ? 'italic' : 'normal',
                    'font-size': font.size ? Math.round(font.size * 1.1) + 'px' : '13px',
                };
                if (font.name) style['font-family'] = "'" + font.name + "', sans-serif";
                const align = (s.paragraph && s.paragraph.alignment) || 'center';
                const map = { left: 'flex-start', right: 'flex-end', both: 'center', center: 'center' };
                style['justify-content'] = map[align] || 'center';
                let css = '';
                for (const k in style) css += k + ':' + style[k] + ';';
                return css;
            },
        };
    }
</script>
@endpush
@endsection
