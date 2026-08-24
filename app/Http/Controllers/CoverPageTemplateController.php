<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCoverPageTemplateRequest;
use App\DocAnalyzer\DocumentReconstructor;
use App\Models\CoverPageTemplate;
use App\Services\DocumentGeneration\CoverDetectionService;
use App\Services\DocumentGeneration\CoverPageRenderer;
use App\Services\DocumentGeneration\StyleMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\PhpWord;

class CoverPageTemplateController extends Controller
{
    public function index()
    {
        $templates = CoverPageTemplate::query()
            ->orderByDesc('id')
            ->limit(60)
            ->get(['id', 'name', 'description', 'is_public', 'updated_at']);

        return view('cover-templates.index', compact('templates'));
    }

    public function create(Request $request)
    {
        // Modèles existants proposés comme base (créer à partir d'un modèle).
        $existingTemplates = CoverPageTemplate::query()
            ->orderByDesc('id')
            ->limit(60)
            ->get(['id', 'name', 'description', 'elements', 'page_style']);

        // Base facultative : ?based_on=<id> pré-remplit le builder.
        $base = null;
        if ($request->filled('based_on')) {
            $base = CoverPageTemplate::find($request->integer('based_on'));
        }

        $template = new CoverPageTemplate([
            'name' => $base ? $base->name . ' (copie)' : 'Nouveau modèle',
            'description' => $base?->description ?? '',
            'is_public' => (bool) ($base?->is_public ?? true),
            'page_style' => $base?->page_style ?? $this->defaultPageStyle(),
            'elements' => $base?->elements ?? $this->defaultElements(),
        ]);

        return view('cover-templates.edit', [
            'template' => $template,
            'mode' => 'create',
            'existingTemplates' => $existingTemplates,
            'basedOnId' => $base?->id,
        ]);
    }

    public function store(StoreCoverPageTemplateRequest $request): RedirectResponse
    {
        $data = $request->normalized();

        return DB::transaction(function () use ($data) {
            $template = CoverPageTemplate::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_public' => (bool) ($data['is_public'] ?? true),
                'based_on_id' => $data['based_on_id'] ?? null,
                'elements' => $data['elements'],
                'page_style' => $data['page_style'] ?? $this->defaultPageStyle(),
            ]);

            return redirect()
                ->route('cover-templates.edit', $template)
                ->with('status', "Modèle « {$template->name} » créé.");
        });
    }

    public function show(CoverPageTemplate $coverTemplate)
    {
        return redirect()->route('cover-templates.edit', $coverTemplate);
    }

    public function edit(CoverPageTemplate $coverTemplate)
    {
        return view('cover-templates.edit', [
            'template' => $coverTemplate,
            'mode' => 'edit',
        ]);
    }

    public function update(
        StoreCoverPageTemplateRequest $request,
        CoverPageTemplate $coverTemplate
    ): RedirectResponse {
        $data = $request->normalized();

        $coverTemplate->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_public' => (bool) ($data['is_public'] ?? true),
            'elements' => $data['elements'],
            'page_style' => $data['page_style'] ?? $coverTemplate->page_style,
        ]);

        return redirect()
            ->route('cover-templates.edit', $coverTemplate)
            ->with('status', 'Modèle mis à jour.');
    }

    public function destroy(CoverPageTemplate $coverTemplate): RedirectResponse
    {
        $coverTemplate->delete();
        return redirect()->route('cover-templates.index')->with('status', 'Modèle supprimé.');
    }

    public function duplicate(CoverPageTemplate $coverTemplate): RedirectResponse
    {
        $copy = $coverTemplate->replicate(['created_at', 'updated_at']);
        $copy->name = $coverTemplate->name . ' (copie)';
        $copy->based_on_id = $coverTemplate->id;
        $copy->save();

        return redirect()->route('cover-templates.edit', $copy)->with('status', 'Modèle dupliqué.');
    }

    /**
     * Page de garde « à partir d'un exemple » : l'utilisateur fournit une
     * couverture d'exemple (DOCX), les zones de texte et en-têtes sont
     * détectées automatiquement, puis il saisit ses informations.
     */
    public function fromExample(Request $request)
    {
        $detection = null;
        $exampleName = null;

        $coverPath = session('cover_example_path');
        if ($coverPath && Storage::disk('storage')->exists($coverPath)) {
            $exampleName = session('cover_example_name', 'Couverture');
            $detection = (new CoverDetectionService())->detect(storage_path('uploads/' . $coverPath));
        }

        return view('cover-templates.from-example', [
            'detection' => $detection,
            'exampleName' => $exampleName,
        ]);
    }

    /**
     * Reçoit l'exemple de couverture, le stocke et détecte ses zones.
     */
    public function detectExample(Request $request): RedirectResponse
    {
        $request->validate([
            'cover' => [
                'required',
                'file',
                'max:51200',
                'mimes:docx',
                'mimetypes:application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ],
        ], [
            'cover.required' => 'Veuillez fournir une couverture d\'exemple (DOCX).',
            'cover.mimes' => 'Format non supporté. Formats acceptés : .docx',
            'cover.mimetypes' => 'Le type réel du fichier ne correspond pas à un document Word (.docx).',
        ]);

        $file = $request->file('cover');
        $coverPath = $file->store('cover_templates', 'storage');

        session([
            'cover_example_path' => $coverPath,
            'cover_example_name' => $file->getClientOriginalName(),
        ]);

        return redirect()
            ->route('cover-templates.from-example')
            ->with('status', 'Zones détectées. Complétez vos informations puis créez la page de garde.');
    }

    /**
     * Crée le modèle de page de garde : les zones détectées deviennent des
     * placeholders ({{nom}}, {{titre}}, {{encadrant}}, {{date}}) en conservant
     * la structure et les styles de l'exemple.
     */
    public function storeFromExample(Request $request): RedirectResponse
    {
        $coverPath = session('cover_example_path');
        abort_unless(
            $coverPath && Storage::disk('storage')->exists($coverPath),
            422,
            'Aucun exemple de couverture fourni.'
        );

        $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'nom' => ['nullable', 'string', 'max:255'],
            'titre' => ['nullable', 'string', 'max:255'],
            'encadrant' => ['nullable', 'string', 'max:255'],
            'date' => ['nullable', 'string', 'max:255'],
        ]);

        $detection = (new CoverDetectionService())->detect(storage_path('uploads/' . $coverPath));

        $template = CoverPageTemplate::create([
            'name' => $request->input('name'),
            'description' => 'Générée à partir d\'un exemple de couverture (détection automatique).',
            'is_public' => (bool) $request->boolean('is_public', true),
            'elements' => $this->elementsFromDetection($detection),
            'page_style' => $this->defaultPageStyle(),
        ]);

        session()->forget(['cover_example_path', 'cover_example_name']);

        return redirect()
            ->route('cover-templates.edit', $template)
            ->with('status', "Page de garde « {$template->name} » créée. Complétez les placeholders à l'export.");
    }

    /**
     * Convertit les lignes détectées en structure d'éléments du builder
     * (une rangée + un bloc texte par ligne), en remplaçant les valeurs des
     * zones par des placeholders et en conservant les styles.
     *
     * @param array<string, mixed> $detection Sortie de CoverDetectionService::detect()
     *
     * @return array<int, array<string, mixed>>
     */
    private function elementsFromDetection(array $detection): array
    {
        $zoneByLine = [];
        foreach ($detection['zones'] ?? [] as $zone) {
            $zoneByLine[(int) ($zone['line_index'] ?? 0)] = $zone;
        }

        $elements = [];

        foreach ($detection['lines'] ?? [] as $i => $line) {
            $text = (string) ($line['text'] ?? '');
            $zone = $zoneByLine[$i] ?? null;

            if ($zone !== null) {
                $type = (string) ($zone['type'] ?? '');
                $label = (string) ($zone['label'] ?? '');
                $text = $label !== '' ? $label . ' {{' . $type . '}}' : '{{' . $type . '}}';
            }

            $font = StyleMapper::toFlatFont($line['styles']['font'] ?? null);
            $paragraph = StyleMapper::toFlatParagraph($line['styles']['paragraph'] ?? null);

            $elements[] = [
                'type' => 'row',
                'cells' => [[
                    'gridSpan' => 12,
                    'blocks' => [[
                        'kind' => 'text',
                        'text' => $text,
                        'font' => [
                            'bold' => (bool) ($font['bold'] ?? false),
                            'italic' => (bool) ($font['italic'] ?? false),
                            'underline' => (bool) ($font['underline'] ?? false),
                            'align' => $this->alignmentKey((string) ($paragraph['alignment'] ?? 'center')),
                            'color' => $font['color'] ?? '000000',
                            'size' => max(6, min(96, (int) round((float) ($font['size'] ?? 11)))),
                            'name' => $font['name'] ?? 'Calibri',
                        ],
                    ]],
                ]],
            ];
        }

        return $elements;
    }

    /**
     * Mappe l'alignement PhpWord vers la clé attendue par le builder.
     */
    private function alignmentKey(string $alignment): string
    {
        return match (strtolower($alignment)) {
            'left', 'start' => 'left',
            'right', 'end' => 'right',
            'both', 'justify' => 'justify',
            default => 'center',
        };
    }

    /**
     * Prévisualisation serveur : génère un DOCX éphémère pour aperçu.
     */
    public function preview(
        Request $request,
        CoverPageTemplate $coverTemplate,
        CoverPageRenderer $renderer
    ): JsonResponse {
        $values = (array) $request->input('values', []);

        $phpWord = new PhpWord();
        $renderer->render($phpWord, $coverTemplate, $values);

        $tmp = storage_path('app/preview-' . Str::random(10) . '.docx');
        $phpWord->save($tmp);

        // Applique le même post-traitement gridSpan que la génération finale
        app(DocumentReconstructor::class)->applyGridSpan($tmp);

        return response()->json([
            'url' => route('cover-templates.preview.file', [
                'cover_template' => $coverTemplate->id,
                'token' => basename($tmp),
            ]),
            'size' => File::exists($tmp) ? File::size($tmp) : 0,
        ])->header('X-Preview-Path', $tmp);
    }

    /**
     * Sert le fichier de preview puis le supprime.
     */
    public function previewFile(string $token)
    {
        $path = storage_path('app/' . $token);
        abort_unless(File::exists($path) && Str::endsWith($path, '.docx'), 404);

        return response()->download($path, 'apercu.docx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Anti-doublon : même structure JSON => retour 409 avec le modèle existant.
     */
    public function exists(StoreCoverPageTemplateRequest $request): JsonResponse
    {
        $data = $request->normalized();
        $hash = $this->hash($data['elements'] ?? []);

        $existing = CoverPageTemplate::query()
            ->where('name', $data['name'] ?? '')
            ->first();

        if (!$existing) {
            return response()->json(['duplicate' => false, 'hash' => $hash]);
        }

        $sameHash = hash('sha256', json_encode($existing->elements ?? [])) === $hash;
        return response()->json([
            'duplicate' => $sameHash,
            'hash' => $hash,
            'existing_id' => $existing->id,
        ]);
    }

    private function hash(array $elements): string
    {
        // Normalisation : tri récursif des clés pour un hash stable
        $normalize = function ($v) use (&$normalize) {
            if (is_array($v)) {
                ksort($v);
                foreach ($v as $k => $vv) {
                    $v[$k] = $normalize($vv);
                }
            }
            return $v;
        };
        return hash('sha256', json_encode($normalize($elements)));
    }

    private function defaultPageStyle(): array
    {
        return [
            'orientation' => 'portrait',
            'size' => 'A4',
            'marginTopMm' => 25,
            'marginBottomMm' => 25,
            'marginLeftMm' => 25,
            'marginRightMm' => 25,
        ];
    }

    private function defaultElements(): array
    {
        return [
            [
                'type' => 'row',
                'cells' => [
                    ['gridSpan' => 1, 'blocks' => [
                        ['kind' => 'text', 'text' => '{{universite}}', 'font' => ['bold' => true, 'size' => 16, 'align' => 'center']],
                    ]],
                ],
            ],
            [
                'type' => 'row',
                'cells' => [
                    ['gridSpan' => 1, 'blocks' => [
                        ['kind' => 'text', 'text' => '{{faculte}}', 'font' => ['bold' => true, 'size' => 13, 'align' => 'center']],
                    ]],
                ],
            ],
            [
                'type' => 'row',
                'cells' => [
                    ['gridSpan' => 1, 'blocks' => [
                        ['kind' => 'divider'],
                    ]],
                ],
            ],
            [
                'type' => 'row',
                'cells' => [
                    ['gridSpan' => 1, 'blocks' => [
                        ['kind' => 'spacer', 'heightPx' => 80],
                    ]],
                ],
            ],
            [
                'type' => 'row',
                'cells' => [
                    ['gridSpan' => 1, 'blocks' => [
                        ['kind' => 'text', 'text' => '{{titre}}', 'font' => ['bold' => true, 'size' => 22, 'align' => 'center']],
                    ]],
                ],
            ],
        ];
    }
}
