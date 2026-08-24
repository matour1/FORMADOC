@extends('layouts.app')

@section('title', $mode === 'create' ? 'Nouveau modèle de page de garde' : 'Modifier ' . $template->name)

@section('content')
@php
    $payload = [
        'name' => old('name', $template->name),
        'description' => old('description', $template->description),
        'is_public' => (bool) old('is_public', $template->is_public ?? true),
        'page_style' => old('page_style', $template->page_style ?? []),
        'elements' => old('elements', $template->elements ?? []),
    ];
@endphp

<div x-data="coverBuilder({{ Illuminate\Support\Js::from($payload) }})" x-cloak class="max-w-7xl">
    {{-- Header --}}
    <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.8rem;margin-bottom:1.6rem">
        <div class="page-header" style="margin-bottom:0">
            <div>
                <span class="eyebrow">Pages de garde</span>
                <h1>
                    {{ $mode === 'create' ? 'Nouveau modèle de page de garde' : 'Modifier le modèle' }}
                </h1>
                <p>
                    Glissez/déposez les blocs, prévisualisez, puis enregistrez.
                </p>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:.5rem">
            <a href="{{ route('cover-templates.index') }}" class="btn btn-ghost">
                Annuler
            </a>
            <button type="button" @click="openPreview()" class="btn btn-secondary">
                <i data-lucide="eye" style="width:16px;height:16px"></i>
                Aperçu
            </button>
            <button type="button" @click="save()" class="btn btn-primary">
                <i data-lucide="save" style="width:16px;height:16px"></i>
                Enregistrer
            </button>
        </div>
    </div>

    {{-- Créer à partir d'un modèle existant (mode création uniquement) --}}
    @if ($mode === 'create')
        <div class="card" style="margin-bottom:1.3rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
            <div style="display:flex;align-items:center;gap:.55rem;min-width:0">
                <i data-lucide="copy" style="width:18px;height:18px;color:var(--color-primary);flex-shrink:0"></i>
                <div style="min-width:0">
                    <p style="font-weight:600;font-size:.9rem">Partir d'un modèle existant</p>
                    <p style="color:var(--color-text-muted);font-size:.78rem">Choisissez un modèle pour pré-remplir le builder (facultatif).</p>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:.5rem;flex:1;min-width:220px">
                <select class="form-control" style="max-width:340px"
                        onchange="if (this.value) window.location.href='{{ route('cover-templates.create') }}?based_on=' + this.value;">
                    <option value="">— Partir de zéro —</option>
                    @foreach ($existingTemplates as $ct)
                        <option value="{{ $ct->id }}" @selected(($basedOnId ?? null) == $ct->id)>
                            {{ $ct->name }}
                        </option>
                    @endforeach
                </select>
                @if (isset($basedOnId) && $basedOnId)
                    <a href="{{ route('cover-templates.create') }}" class="btn btn-ghost btn-sm" style="white-space:nowrap">
                        <i data-lucide="x" style="width:14px;height:14px"></i> Réinitialiser
                    </a>
                @endif
            </div>
        </div>
    @endif

    <form method="POST"
          :action="actionUrl"
          x-ref="form"
          @submit.prevent="submitForm()">
        @csrf
        <input type="hidden" name="_method" :value="method">
        <input type="hidden" name="elements" :value="JSON.stringify(elements)">
        <input type="hidden" name="page_style" :value="JSON.stringify(pageStyle)">
        <input type="hidden" name="is_public" :value="isPublic ? 1 : 0">
        @if (isset($basedOnId) && $basedOnId)
            <input type="hidden" name="based_on_id" value="{{ $basedOnId }}">
        @endif

        <div style="display:grid;grid-template-columns:2fr 1fr;gap:1.4rem;align-items:start">
            {{-- Colonne éditeur --}}
            <div style="display:flex;flex-direction:column;gap:1.3rem;min-width:0">
                {{-- Métadonnées --}}
                <section class="card">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                        <label class="form-group" style="margin-bottom:0">
                            <span>Nom du modèle</span>
                            <input type="text" name="name" x-model="name" required class="form-control">
                        </label>
                        <label class="form-group" style="margin-bottom:0">
                            <span>Description</span>
                            <input type="text" name="description" x-model="description" class="form-control">
                        </label>
                    </div>
                    <label style="display:inline-flex;align-items:center;gap:.5rem;margin-top:1rem;font-size:.88rem;cursor:pointer">
                        <input type="checkbox" x-model="isPublic" style="accent-color:var(--color-primary);width:16px;height:16px">
                        Modèle public (visible par tous)
                    </label>
                </section>

                {{-- Style de page --}}
                <section class="card">
                    <h2 class="card-title" style="margin-bottom:1rem">Style de page</h2>
                    <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:.8rem">
                        <label class="form-group" style="margin-bottom:0">
                            <span style="font-size:.72rem">Format</span>
                            <select x-model="pageStyle.size" class="form-control" style="font-size:.82rem">
                                <option>A4</option><option>A3</option><option>Letter</option>
                            </select>
                        </label>
                        <label class="form-group" style="margin-bottom:0">
                            <span style="font-size:.72rem">Orientation</span>
                            <select x-model="pageStyle.orientation" class="form-control" style="font-size:.82rem">
                                <option value="portrait">Portrait</option>
                                <option value="landscape">Paysage</option>
                            </select>
                        </label>
                        @foreach (['Top' => 'marginTopMm', 'Bottom' => 'marginBottomMm', 'Left' => 'marginLeftMm', 'Right' => 'marginRightMm'] as $label => $key)
                            <label class="form-group" style="margin-bottom:0">
                                <span style="font-size:.72rem">Marge {{ $label }} (mm)</span>
                                <input type="number" min="0" max="200" x-model.number="pageStyle.{{ $key }}" class="form-control" style="font-size:.82rem">
                            </label>
                        @endforeach
                    </div>
                </section>

                {{-- Lignes / cellules / blocs --}}
                <section style="display:flex;flex-direction:column;gap:.8rem">
                    <template x-for="(row, ri) in elements" :key="ri">
                        <div class="card">
                            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.8rem">
                                <h3 style="font-size:.9rem;font-weight:600">
                                    Rangée <span x-text="ri + 1"></span>
                                </h3>
                                <div style="display:flex;align-items:center;gap:.2rem">
                                    <button type="button" @click="moveRow(ri, -1)" class="icon-btn" title="Monter" style="font-size:.8rem">▲</button>
                                    <button type="button" @click="moveRow(ri, 1)" class="icon-btn" title="Descendre" style="font-size:.8rem">▼</button>
                                    <button type="button" @click="removeRow(ri)" class="icon-btn" title="Supprimer la rangée">
                                        <i data-lucide="trash-2" style="width:15px;height:15px;color:var(--color-correction)"></i>
                                    </button>
                                </div>
                            </div>

                            <div style="display:grid;gap:.8rem" :style="`grid-template-columns: repeat(12, minmax(0,1fr));`">
                                <template x-for="(cell, ci) in row.cells" :key="ci">
                                    <div style="border:1px dashed var(--color-border-strong);border-radius:var(--radius-sm);background:var(--color-surface-2);padding:.8rem"
                                         :style="`grid-column: span ${cell.gridSpan || 1} / span ${cell.gridSpan || 1};`">
                                        <div style="display:flex;align-items:center;justify-content:space-between;font-size:.75rem;color:var(--color-text-muted);margin-bottom:.5rem">
                                            <span>Cellule (gridSpan <span x-text="cell.gridSpan || 1"></span>)</span>
                                            <div style="display:flex;align-items:center;gap:.2rem">
                                                <button type="button" @click="cell.gridSpan = Math.max(1, (cell.gridSpan||1) - 1)" class="icon-btn" title="Réduire">−</button>
                                                <button type="button" @click="cell.gridSpan = Math.min(12, (cell.gridSpan||1) + 1)" class="icon-btn" title="Étendre">+</button>
                                                <button type="button" @click="row.cells.splice(ci,1)" class="icon-btn" title="Retirer la cellule">
                                                    <i data-lucide="x" style="width:14px;height:14px;color:var(--color-correction)"></i>
                                                </button>
                                            </div>
                                        </div>

                                        <template x-for="(block, bi) in cell.blocks" :key="bi">
                                            <div style="margin-bottom:.5rem;border:1px solid var(--color-border);border-radius:var(--radius-sm);background:var(--color-surface);padding:.55rem">
                                                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.5rem">
                                                    <select x-model="block.kind" class="form-control" style="width:auto;font-size:.75rem;padding:.25rem .5rem">
                                                        <option value="text">Texte</option>
                                                        <option value="spacer">Espace</option>
                                                        <option value="divider">Trait</option>
                                                        <option value="image">Image</option>
                                                        <option value="logo">Logo</option>
                                                    </select>
                                                    <button type="button" @click="cell.blocks.splice(bi,1)" class="icon-btn" title="Retirer le bloc">
                                                        <i data-lucide="x" style="width:14px;height:14px;color:var(--color-correction)"></i>
                                                    </button>
                                                </div>

                                                <template x-if="block.kind === 'text'">
                                                    <div>
                                                        <textarea x-model="block.text" rows="2"
                                                                  placeholder="Texte ou @{{placeholder}}"
                                                                  class="form-control" style="font-size:.82rem"></textarea>
                                                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;margin-top:.5rem;font-size:.78rem">
                                                            <select x-model="block.font.align" class="form-control" style="font-size:.75rem;padding:.25rem .5rem">
                                                                <option value="left">Gauche</option>
                                                                <option value="center">Centre</option>
                                                                <option value="right">Droite</option>
                                                            </select>
                                                            <input type="number" min="6" max="96" x-model.number="block.font.size" class="form-control" style="font-size:.75rem;padding:.25rem .5rem" placeholder="Taille">
                                                            <label style="display:inline-flex;align-items:center;gap:.3rem;cursor:pointer"><input type="checkbox" x-model="block.font.bold" style="accent-color:var(--color-primary)"> Gras</label>
                                                            <label style="display:inline-flex;align-items:center;gap:.3rem;cursor:pointer"><input type="checkbox" x-model="block.font.italic" style="accent-color:var(--color-primary)"> Italique</label>
                                                            <input type="color" x-model="block.font.color" style="width:100%;height:2rem;border:1px solid var(--color-border);border-radius:6px;padding:0">
                                                        </div>
                                                    </div>
                                                </template>

                                                <template x-if="block.kind === 'image' || block.kind === 'logo'">
                                                    <div style="font-size:.78rem;display:flex;flex-direction:column;gap:.4rem">
                                                        <input type="text" x-model="block.src" placeholder="Chemin ou URL" class="form-control" style="font-size:.78rem">
                                                        <input type="number" min="20" max="2000" x-model.number="block.heightPx" placeholder="Hauteur (px)" class="form-control" style="font-size:.78rem">
                                                    </div>
                                                </template>

                                                <template x-if="block.kind === 'spacer'">
                                                    <div style="font-size:.78rem">
                                                        <input type="number" min="10" max="2000" x-model.number="block.heightPx" placeholder="Hauteur (px)" class="form-control" style="font-size:.78rem">
                                                    </div>
                                                </template>
                                            </div>
                                        </template>

                                        <button type="button" @click="cell.blocks.push(newBlock('text'))"
                                                class="btn btn-ghost btn-sm" style="margin-top:.4rem">
                                            <i data-lucide="plus" style="width:14px;height:14px"></i>
                                            Ajouter un bloc
                                        </button>
                                    </div>
                                </template>
                            </div>

                            <button type="button" @click="row.cells.push(newCell())"
                                    class="btn btn-ghost btn-sm" style="margin-top:.8rem;border-style:dashed">
                                <i data-lucide="plus" style="width:14px;height:14px"></i>
                                Cellule
                            </button>
                        </div>
                    </template>

                    <button type="button" @click="elements.push(newRow())"
                            class="btn btn-ghost btn-lg" style="border-style:dashed;justify-content:center">
                        <i data-lucide="plus" style="width:16px;height:16px"></i>
                        Ajouter une rangée
                    </button>
                </section>
            </div>

            {{-- Colonne aperçu live --}}
            <aside style="min-width:0">
                <div class="card" style="position:sticky;top:1.4rem">
                    <h2 class="card-title" style="margin-bottom:1rem">Aperçu live</h2>
                    <div style="background:var(--color-surface-2);padding:1rem;border-radius:var(--radius-sm)">
                        <div style="margin:0 auto;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.15)"
                             :style="previewStyle()">
                            <template x-for="(row, ri) in elements" :key="`p-${ri}`">
                                <div style="display:flex;width:100%"
                                     :style="`min-height: 36px;`">
                                    <template x-for="(cell, ci) in row.cells" :key="`p-${ri}-${ci}`">
                                        <div style="flex:1;border:1px dashed #cbd5e1;padding:.25rem .5rem;text-align:center"
                                             :style="`flex-basis: ${((cell.gridSpan||1)/12)*100}%;`">
                                            <template x-for="(block, bi) in cell.blocks" :key="`p-${ri}-${ci}-${bi}`">
                                                <div>
                                                    <template x-if="block.kind === 'text'">
                                                        <div :style="blockStyle(block)">
                                                            <span x-text="resolve(block.text)"></span>
                                                        </div>
                                                    </template>
                                                    <template x-if="block.kind === 'spacer'">
                                                        <div :style="`height: ${(block.heightPx||60)/3}px;`"></div>
                                                    </template>
                                                    <template x-if="block.kind === 'divider'">
                                                        <hr style="margin:.5rem 0;border-top:1px solid #cbd5e1">
                                                    </template>
                                                    <template x-if="block.kind === 'image' || block.kind === 'logo'">
                                                        <div style="font-size:10px;color:#94a3b8">[image]</div>
                                                    </template>
                                                </div>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>
                    <p style="margin-top:.8rem;font-size:.72rem;color:var(--color-text-muted)">Aperçu proportionnel (1px ≈ 3px écran).</p>
                </div>
            </aside>
        </div>
    </form>

    {{-- Modal aperçu serveur --}}
    <div x-show="previewOpen" style="position:fixed;inset:0;z-index:50;display:flex;align-items:center;justify-content:center;background:rgba(15,23,42,.5);padding:1.5rem;animation:fadeSlide .2s ease"
         @keydown.escape.window="previewOpen = false">
        <div class="card" style="width:100%;max-width:48rem;max-height:90vh;overflow-y:auto">
            <div style="display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--color-border);padding-bottom:.9rem;margin-bottom:1rem">
                <h3 style="font-weight:600;font-size:.98rem">Aperçu DOCX (côté serveur)</h3>
                <button @click="previewOpen = false" class="icon-btn" aria-label="Fermer">
                    <i data-lucide="x" style="width:17px;height:17px"></i>
                </button>
            </div>
            <div>
                <p style="margin-bottom:.8rem;font-size:.78rem;color:var(--color-text-muted)">
                    Saisissez des valeurs pour les placeholders, puis générez un DOCX d'aperçu.
                </p>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.6rem">
                    <template x-for="key in placeholderKeys()" :key="key">
                        <label style="display:block;font-size:.75rem">
                            <span style="color:var(--color-text-muted)" x-text="key"></span>
                            <input type="text" x-model="previewValues[key]" class="form-control" style="font-size:.82rem;margin-top:.2rem">
                        </label>
                    </template>
                </div>
                <div style="display:flex;justify-content:flex-end;gap:.5rem;margin-top:1rem">
                    <button @click="previewOpen = false" class="btn btn-ghost btn-sm">Fermer</button>
                    <button @click="runPreview()" :disabled="previewLoading" class="btn btn-primary btn-sm">
                        <i data-lucide="loader-2" x-show="previewLoading" class="animate-spin" style="width:14px;height:14px"></i>
                        Générer DOCX
                    </button>
                </div>
                <template x-if="previewUrl">
                    <a :href="previewUrl" download="apercu.docx"
                       style="display:inline-flex;align-items:center;gap:.3rem;margin-top:.8rem;font-size:.8rem;color:var(--color-primary);text-decoration:underline">
                        <i data-lucide="download" style="width:14px;height:14px"></i>
                        Télécharger l'aperçu
                    </a>
                </template>
            </div>
        </div>
    </div>
</div>

<script>
function coverBuilder(initial) {
    return {
        mode: @json($mode),
        actionUrl: @json($mode === 'create' ? route('cover-templates.store') : route('cover-templates.update', $template)),
        method: @json($mode === 'create' ? 'POST' : 'PUT'),

        name: initial.name || 'Nouveau modèle',
        description: initial.description || '',
        isPublic: initial.is_public !== false,
        pageStyle: Object.assign({
            size: 'A4', orientation: 'portrait',
            marginTopMm: 25, marginBottomMm: 25, marginLeftMm: 25, marginRightMm: 25,
        }, typeof initial.page_style === 'string' ? (JSON.parse(initial.page_style) || {}) : (initial.page_style || {})),
        elements: Array.isArray(initial.elements) && initial.elements.length
            ? initial.elements
            : (typeof initial.elements === 'string'
                ? (JSON.parse(initial.elements) || this.defaultElements())
                : this.defaultElements()),

        init() {
            this.normalizeAll();
        },

        normalizeAll() {
            (this.elements || []).forEach(row => {
                (row.cells || []).forEach(cell => {
                    cell.gridSpan = Math.max(1, Math.min(12, cell.gridSpan || 1));
                    (cell.blocks || []).forEach(b => {
                        b.kind = b.kind || 'text';
                        if (!b.font) b.font = { align: 'center', size: 12, color: '#000000' };
                        b.font.align = b.font.align || 'center';
                        b.font.size = b.font.size || 12;
                        b.font.color = b.font.color || '#000000';
                        if (b.kind === 'spacer' && !b.heightPx) b.heightPx = 60;
                        if ((b.kind === 'image' || b.kind === 'logo') && !b.heightPx) b.heightPx = 120;
                        if (b.kind === 'image' && !b.src) b.src = '';
                        if (b.kind === 'text' && b.text === undefined) b.text = '';
                    });
                });
            });
        },

        previewOpen: false,
        previewLoading: false,
        previewUrl: null,
        previewValues: {},
        // L'aperçu serveur nécessite un modèle persisté (route à id).
        serverPreviewEndpoint: @json($mode === 'edit' ? route('cover-templates.preview', $template) : null),

        newRow() { return { type: 'row', cells: [this.newCell()] }; },
        newCell(span = 1) {
            return { gridSpan: span, blocks: [this.newBlock('text')] };
        },
        newBlock(kind) {
            if (kind === 'text') return { kind, text: 'Texte', font: { align: 'center', size: 12, color: '#000000' } };
            if (kind === 'spacer') return { kind, heightPx: 60 };
            if (kind === 'divider') return { kind };
            if (kind === 'image' || kind === 'logo') return { kind, src: '', heightPx: 120 };
            return { kind: 'text', text: '' };
        },
        defaultElements() {
            return [
                { type: 'row', cells: [{ gridSpan: 12, blocks: [this.newBlock('text')] }] }
            ];
        },

        moveRow(ri, dir) {
            const target = ri + dir;
            if (target < 0 || target >= this.elements.length) return;
            const [item] = this.elements.splice(ri, 1);
            this.elements.splice(target, 0, item);
        },
        removeRow(ri) { this.elements.splice(ri, 1); },

        resolve(text) {
            if (!text) return '';
            return text.replace(/\{\{\s*([\w\.]+)\s*\}\}/g, (_, k) => this.previewValues[k] ?? '');
        },

        blockStyle(block) {
            const f = block.font || {};
            return [
                `text-align:${f.align || 'center'}`,
                `font-size:${(f.size || 12)}px`,
                `color:${f.color || '#000'}`,
                `font-weight:${f.bold ? '700' : '400'}`,
                `font-style:${f.italic ? 'italic' : 'normal'}`,
            ].join(';');
        },

        previewStyle() {
            const a4 = this.pageStyle.size === 'A3' ? 297 : (this.pageStyle.size === 'Letter' ? 280 : 210);
            const w = this.pageStyle.orientation === 'landscape' ? a4 * 1.414 : a4;
            const h = this.pageStyle.orientation === 'landscape' ? a4 : a4 * 1.414;
            const scale = 0.6;
            return `width:${w * scale}px;height:${h * scale}px;`;
        },

        placeholderKeys() {
            const set = new Set();
            const walk = (blocks) => (blocks || []).forEach(b => {
                if (b.kind === 'text' && b.text) {
                    (b.text.match(/\{\{\s*([\w\.]+)\s*\}\}/g) || []).forEach(m => {
                        set.add(m.replace(/[{}\s]/g, ''));
                    });
                }
            });
            this.elements.forEach(r => (r.cells || []).forEach(c => walk(c.blocks)));
            return [...set];
        },

        openPreview() {
            this.previewValues = Object.fromEntries(this.placeholderKeys().map(k => [k, '']));
            this.previewUrl = null;
            this.previewOpen = true;
        },
        async runPreview() {
            if (!this.serverPreviewEndpoint) {
                alert('Enregistrez d\'abord le modèle pour pouvoir générer un aperçu DOCX.');
                return;
            }
            this.previewLoading = true;
            try {
                const res = await fetch(this.serverPreviewEndpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                    body: JSON.stringify({ values: this.previewValues }),
                });
                const data = await res.json();
                this.previewUrl = data.url;
            } catch (e) {
                alert('Erreur d\'aperçu : ' + e.message);
            } finally {
                this.previewLoading = false;
            }
        },

        async save() {
            // Anti-doublon rapide avant submit
            try {
                const res = await fetch('{{ route('cover-templates.check') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({
                        name: this.name,
                        elements: this.elements,
                        page_style: this.pageStyle,
                    }),
                });
                const data = await res.json();
                if (data.duplicate && !confirm('Un modèle identique existe. Créer quand même ?')) {
                    return;
                }
            } catch (_) { /* réseau down : on tente quand même */ }

            this.$refs.form.submit();
        },
        submitForm() { this.$refs.form.submit(); },
    };
}
</script>
@endsection
