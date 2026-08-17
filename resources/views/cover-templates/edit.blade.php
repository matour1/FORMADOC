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

<div x-data="coverBuilder({{ Illuminate\Support\Js::from($payload) }})" x-cloak class="mx-auto max-w-7xl px-6 py-8">
    {{-- Header --}}
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">
                {{ $mode === 'create' ? 'Nouveau modèle de page de garde' : 'Modifier le modèle' }}
            </h1>
            <p class="mt-1 text-sm text-slate-500">
                Glissez/déposez les blocs, prévisualisez, puis enregistrez.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('cover-templates.index') }}"
               class="rounded-full border border-slate-200 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                Annuler
            </a>
            <button type="button" @click="openPreview()"
                    class="inline-flex items-center gap-2 rounded-full border border-[#004AC6] px-4 py-2 text-sm font-medium text-[#004AC6] hover:bg-blue-50">
                <span class="material-symbols-outlined text-[18px]">visibility</span>
                Aperçu
            </button>
            <button type="button" @click="save()"
                    class="inline-flex items-center gap-2 rounded-full bg-[#004AC6] px-5 py-2 text-sm font-medium text-white shadow-sm hover:bg-[#2563eb]">
                <span class="material-symbols-outlined text-[18px]">save</span>
                Enregistrer
            </button>
        </div>
    </div>

    @if ($errors->any())
        <div class="mb-4 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">
            <ul class="list-disc pl-5">
                @foreach ($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
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

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            {{-- Colonne éditeur --}}
            <div class="space-y-5 lg:col-span-2">
                {{-- Métadonnées --}}
                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">Nom du modèle</span>
                            <input type="text" name="name" x-model="name" required
                                   class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-[#004AC6] focus:outline-none focus:ring-2 focus:ring-blue-100">
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">Description</span>
                            <input type="text" name="description" x-model="description"
                                   class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-[#004AC6] focus:outline-none focus:ring-2 focus:ring-blue-100">
                        </label>
                    </div>
                    <label class="mt-4 inline-flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" x-model="isPublic" class="rounded border-slate-300 text-[#004AC6]">
                        Modèle public (visible par tous)
                    </label>
                </section>

                {{-- Style de page --}}
                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="mb-3 text-sm font-semibold text-slate-800">Style de page</h2>
                    <div class="grid grid-cols-2 gap-4 md:grid-cols-5">
                        <label class="block">
                            <span class="text-xs text-slate-500">Format</span>
                            <select x-model="pageStyle.size"
                                    class="mt-1 w-full rounded-lg border border-slate-200 px-2 py-1.5 text-sm">
                                <option>A4</option><option>A3</option><option>Letter</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs text-slate-500">Orientation</span>
                            <select x-model="pageStyle.orientation"
                                    class="mt-1 w-full rounded-lg border border-slate-200 px-2 py-1.5 text-sm">
                                <option value="portrait">Portrait</option>
                                <option value="landscape">Paysage</option>
                            </select>
                        </label>
                        @foreach (['Top' => 'marginTopMm', 'Bottom' => 'marginBottomMm', 'Left' => 'marginLeftMm', 'Right' => 'marginRightMm'] as $label => $key)
                            <label class="block">
                                <span class="text-xs text-slate-500">Marge {{ $label }} (mm)</span>
                                <input type="number" min="0" max="200" x-model.number="pageStyle.{{ $key }}"
                                       class="mt-1 w-full rounded-lg border border-slate-200 px-2 py-1.5 text-sm">
                            </label>
                        @endforeach
                    </div>
                </section>

                {{-- Lignes / cellules / blocs --}}
                <section class="space-y-3">
                    <template x-for="(row, ri) in elements" :key="ri">
                        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                            <div class="mb-3 flex items-center justify-between">
                                <h3 class="text-sm font-semibold text-slate-700">
                                    Rangée <span x-text="ri + 1"></span>
                                </h3>
                                <div class="flex items-center gap-1">
                                    <button type="button" @click="moveRow(ri, -1)" class="rounded p-1 text-slate-400 hover:bg-slate-100">▲</button>
                                    <button type="button" @click="moveRow(ri, 1)" class="rounded p-1 text-slate-400 hover:bg-slate-100">▼</button>
                                    <button type="button" @click="removeRow(ri)"
                                            class="rounded p-1 text-rose-400 hover:bg-rose-50">
                                        <span class="material-symbols-outlined text-[18px]">delete</span>
                                    </button>
                                </div>
                            </div>

                            <div class="grid gap-3" :style="`grid-template-columns: repeat(12, minmax(0,1fr));`">
                                <template x-for="(cell, ci) in row.cells" :key="ci">
                                    <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50/60 p-3"
                                         :style="`grid-column: span ${cell.gridSpan || 1} / span ${cell.gridSpan || 1};`">
                                        <div class="mb-2 flex items-center justify-between text-xs text-slate-500">
                                            <span>Cellule (gridSpan <span x-text="cell.gridSpan || 1"></span>)</span>
                                            <div class="flex items-center gap-1">
                                                <button type="button" @click="cell.gridSpan = Math.max(1, (cell.gridSpan||1) - 1)" class="rounded p-0.5 hover:bg-slate-200">−</button>
                                                <button type="button" @click="cell.gridSpan = Math.min(12, (cell.gridSpan||1) + 1)" class="rounded p-0.5 hover:bg-slate-200">+</button>
                                                <button type="button" @click="row.cells.splice(ci,1)" class="rounded p-0.5 text-rose-500 hover:bg-rose-50">
                                                    <span class="material-symbols-outlined text-[16px]">close</span>
                                                </button>
                                            </div>
                                        </div>

                                        <template x-for="(block, bi) in cell.blocks" :key="bi">
                                            <div class="mb-2 rounded-lg border border-slate-200 bg-white p-2">
                                                <div class="mb-2 flex items-center justify-between text-xs">
                                                    <select x-model="block.kind"
                                                            class="rounded border border-slate-200 px-1.5 py-0.5 text-xs">
                                                        <option value="text">Texte</option>
                                                        <option value="spacer">Espace</option>
                                                        <option value="divider">Trait</option>
                                                        <option value="image">Image</option>
                                                        <option value="logo">Logo</option>
                                                    </select>
                                                    <button type="button" @click="cell.blocks.splice(bi,1)" class="text-rose-400 hover:text-rose-600">
                                                        <span class="material-symbols-outlined text-[16px]">close</span>
                                                    </button>
                                                </div>

                                                <template x-if="block.kind === 'text'">
                                                    <div>
                                                        <textarea x-model="block.text" rows="2"
                                                                  placeholder="Texte ou @{{placeholder}}"
                                                                  class="w-full rounded border border-slate-200 px-2 py-1 text-sm"></textarea>
                                                        <div class="mt-2 grid grid-cols-2 gap-2 text-xs">
                                                            <select x-model="block.font.align" class="rounded border border-slate-200 px-1.5 py-1">
                                                                <option value="left">Gauche</option>
                                                                <option value="center">Centre</option>
                                                                <option value="right">Droite</option>
                                                            </select>
                                                            <input type="number" min="6" max="96" x-model.number="block.font.size"
                                                                   class="rounded border border-slate-200 px-1.5 py-1" placeholder="Taille">
                                                            <label class="inline-flex items-center gap-1"><input type="checkbox" x-model="block.font.bold"> Gras</label>
                                                            <label class="inline-flex items-center gap-1"><input type="checkbox" x-model="block.font.italic"> Italique</label>
                                                            <input type="color" x-model="block.font.color" class="h-7 w-full rounded border border-slate-200 p-0">
                                                        </div>
                                                    </div>
                                                </template>

                                                <template x-if="block.kind === 'image' || block.kind === 'logo'">
                                                    <div class="text-xs">
                                                        <input type="text" x-model="block.src"
                                                               placeholder="Chemin ou URL"
                                                               class="w-full rounded border border-slate-200 px-2 py-1">
                                                        <input type="number" min="20" max="2000" x-model.number="block.heightPx"
                                                               placeholder="Hauteur (px)"
                                                               class="mt-1 w-full rounded border border-slate-200 px-2 py-1">
                                                    </div>
                                                </template>

                                                <template x-if="block.kind === 'spacer'">
                                                    <div class="text-xs">
                                                        <input type="number" min="10" max="2000" x-model.number="block.heightPx"
                                                               placeholder="Hauteur (px)"
                                                               class="w-full rounded border border-slate-200 px-2 py-1">
                                                    </div>
                                                </template>
                                            </div>
                                        </template>

                                        <button type="button" @click="cell.blocks.push(newBlock('text'))"
                                                class="mt-1 inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-1 text-xs text-slate-600 hover:bg-slate-200">
                                            <span class="material-symbols-outlined text-[14px]">add</span>
                                            Ajouter un bloc
                                        </button>
                                    </div>
                                </template>
                            </div>

                            <button type="button" @click="row.cells.push(newCell())"
                                    class="mt-3 inline-flex items-center gap-1 rounded-full border border-dashed border-slate-300 px-3 py-1 text-xs text-slate-600 hover:bg-slate-50">
                                + Cellule
                            </button>
                        </div>
                    </template>

                    <button type="button" @click="elements.push(newRow())"
                            class="w-full rounded-2xl border border-dashed border-slate-300 bg-white py-4 text-sm font-medium text-slate-600 hover:bg-slate-50">
                        + Ajouter une rangée
                    </button>
                </section>
            </div>

            {{-- Colonne aperçu live --}}
            <aside class="space-y-4">
                <div class="sticky top-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="mb-3 text-sm font-semibold text-slate-800">Aperçu live</h2>
                    <div class="mx-auto bg-slate-100 p-4">
                        <div class="mx-auto bg-white shadow ring-1 ring-slate-200"
                             :style="previewStyle()">
                            <template x-for="(row, ri) in elements" :key="`p-${ri}`">
                                <div class="flex w-full"
                                     :style="`min-height: 36px;`">
                                    <template x-for="(cell, ci) in row.cells" :key="`p-${ri}-${ci}`">
                                        <div class="flex-1 border border-dashed border-slate-200 px-2 py-1 text-center"
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
                                                        <hr class="my-2 border-t border-slate-300">
                                                    </template>
                                                    <template x-if="block.kind === 'image' || block.kind === 'logo'">
                                                        <div class="text-[10px] text-slate-400">[image]</div>
                                                    </template>
                                                </div>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>
                    <p class="mt-3 text-xs text-slate-400">Aperçu proportionnel (1px ≈ 3px écran).</p>
                </div>
            </aside>
        </div>
    </form>

    {{-- Modal aperçu serveur --}}
    <div x-show="previewOpen" x-transition class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-6"
         @keydown.escape.window="previewOpen = false">
        <div class="w-full max-w-3xl rounded-2xl bg-white shadow-xl">
            <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3">
                <h3 class="text-sm font-semibold text-slate-800">Aperçu DOCX (côté serveur)</h3>
                <button @click="previewOpen = false" class="text-slate-400 hover:text-slate-600">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <div class="p-5">
                <p class="mb-3 text-xs text-slate-500">
                    Saisissez des valeurs pour les placeholders, puis générez un DOCX d'aperçu.
                </p>
                <div class="grid grid-cols-2 gap-2">
                    <template x-for="key in placeholderKeys()" :key="key">
                        <label class="block text-xs">
                            <span class="text-slate-500" x-text="key"></span>
                            <input type="text" x-model="previewValues[key]"
                                   class="mt-0.5 w-full rounded border border-slate-200 px-2 py-1 text-sm">
                        </label>
                    </template>
                </div>
                <div class="mt-4 flex justify-end gap-2">
                    <button @click="previewOpen = false" class="rounded-full border border-slate-200 px-3 py-1.5 text-xs">Fermer</button>
                    <button @click="runPreview()" :disabled="previewLoading"
                            class="inline-flex items-center gap-1 rounded-full bg-[#004AC6] px-3 py-1.5 text-xs font-medium text-white">
                        <span x-show="previewLoading" class="material-symbols-outlined animate-spin text-[14px]">progress_activity</span>
                        Générer DOCX
                    </button>
                </div>
                <template x-if="previewUrl">
                    <a :href="previewUrl" download="apercu.docx"
                       class="mt-3 inline-flex items-center gap-1 text-xs text-[#004AC6] underline">
                        ⬇ Télécharger l'aperçu
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
