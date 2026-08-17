<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCoverPageTemplateRequest;
use App\DocAnalyzer\DocumentReconstructor;
use App\Models\CoverPageTemplate;
use App\Services\DocumentGeneration\CoverPageRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
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

    public function create()
    {
        return view('cover-templates.edit', [
            'template' => new CoverPageTemplate([
                'name' => 'Nouveau modèle',
                'description' => '',
                'is_public' => true,
                'page_style' => $this->defaultPageStyle(),
                'elements' => $this->defaultElements(),
            ]),
            'mode' => 'create',
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
