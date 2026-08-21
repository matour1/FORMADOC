<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Models\CoverPageTemplate;
use App\Models\User;
use App\Services\DocumentGeneration\CoverGenerationService;
use App\Services\DocumentGeneration\CoverPageRenderer;
use App\Services\OpenRouter\OpenRouterService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Outils actionnables exposés au chat IA (exigence A).
 *
 * Le modèle (via function calling OpenRouter) peut demander l'exécution
 * d'actions concrètes au lieu de se contenter de répondre en texte :
 *
 * OUTILS INTERNES (exécution locale, PHPWord / LibreOffice) :
 *   - cover_page.generate  : génère une page de garde DOCX depuis un gabarit
 *   - document.reconstruct : reconstruit un DOCX depuis une structure
 *                            détectée (reconstructeur)
 *   - table_of_contents    : génère un sommaire (champ TOC PhpWord)
 *   - structure.correct    : applique des corrections de structure
 *
 * OUTILS EXTERNES (via OpenRouter) :
 *   - web.search           : recherche web native (web_search_options)
 *   - image.generate       : génération d'image (gpt-image-*)
 *
 * Chaque outil est décrit par un schéma OpenAI (tools[]) et exécuté par
 * execute() : c'est la cible du callable `executor` passé à
 * OpenRouterService::chat().
 */
class ChatToolsService
{
    public function __construct(
        private readonly OpenRouterService $openRouter,
        private readonly CoverGenerationService $coverGeneration,
        private readonly CoverPageRenderer $coverPageRenderer,
    ) {
    }

    /**
     * Liste des identifiants d'outils actifs (utilisée pour le menu
     * « Outils » de l'interface).
     *
     * @return string[]
     */
    public function availableTools(): array
    {
        return array_values(array_map(
            fn (array $schema): string => (string) ($schema['function']['name'] ?? ''),
            $this->schemas(),
        ));
    }

    /**
     * Schémas OpenAI (function calling) de tous les outils.
     *
     * @return array<int, array<string, mixed>>
     */
    public function schemas(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'cover_page.generate',
                    'description' => 'Génère une page de garde professionnelle (DOCX) pour un rapport '
                        .'de stage / mémoire à partir d\'un gabarit de couverture existant. '
                        .'Arguments : template_id (identifiant du gabarit), et les valeurs à injecter '
                        .'(school, department, title, author, supervisor, date, location, academic_year…).',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'template_id' => ['type' => 'integer', 'description' => 'ID du gabarit de couverture'],
                            'values' => ['type' => 'object', 'description' => 'Valeurs à injecter dans les placeholders {{key}}'],
                            'output_filename' => ['type' => 'string', 'description' => 'Nom du fichier DOCX généré (sans extension)'],
                        ],
                        'required' => ['template_id', 'values'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'document.reconstruct',
                    'description' => 'Reconstruit un document DOCX complet à partir d\'une structure JSON '
                        .'(titres, sous-titres, en-têtes, pieds de page, tableaux, images, légendes) '
                        .'en appliquant la convention rapport de stage (frontispice romain, corps arabe, TOC).',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'structure' => ['type' => 'object', 'description' => 'Structure détectée à reconstruire'],
                            'output_filename' => ['type' => 'string', 'description' => 'Nom du fichier DOCX généré (sans extension)'],
                        ],
                        'required' => ['structure'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'structure.correct',
                    'description' => 'Corrige une structure détectée en promouvant / rétrogradant des titres '
                        .'ou en retirant des items du plan. '
                        .'Arguments : structure (JSON), corrections (map clé de position → action '
                        .'1|2|3|remove).',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'structure' => ['type' => 'object', 'description' => 'Structure à corriger'],
                            'corrections' => ['type' => 'object', 'description' => 'Corrections à appliquer'],
                        ],
                        'required' => ['structure', 'corrections'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'table_of_contents',
                    'description' => 'Génère un document DOCX contenant un sommaire (champ TOC PhpWord '
                        .'niveaux 1-3, mis à jour à l\'ouverture via updateFields). '
                        .'Arguments : title (intitulé du sommaire, défaut SOMMAIRE), output_filename.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string', 'description' => 'Intitulé du sommaire (défaut : SOMMAIRE)'],
                            'output_filename' => ['type' => 'string', 'description' => 'Nom du fichier DOCX généré (sans extension)'],
                        ],
                        'required' => [],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'web.search',
                    'description' => 'Effectue une recherche web en temps réel (recommandé pour vérifier '
                        .'une information récente ou des sources). Retourne des extraits sourcés.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => ['type' => 'string', 'description' => 'Requête de recherche'],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'image.generate',
                    'description' => 'Génère une image (schéma, illustration, couverture visuelle) à partir '
                        .'d\'une description textuelle. Génère et enregistre le fichier dans le stockage.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'prompt' => ['type' => 'string', 'description' => 'Description détaillée de l\'image à générer'],
                            'output_filename' => ['type' => 'string', 'description' => 'Nom du fichier image généré (sans extension)'],
                        ],
                        'required' => ['prompt'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Exécute un appel d'outil demandé par le modèle.
     *
     * @param array<string, mixed> $toolCall  {id, name, arguments (string JSON)}
     * @param User|null            $user      Utilisateur connecté (nullable)
     * @param string               $planSlug  Plan courant pour le routage des modèles
     *
     * @return array{result?: string, error?: string}
     */
    public function execute(array $toolCall, ?User $user, string $planSlug): array
    {
        $name = (string) ($toolCall['name'] ?? '');
        $arguments = $this->decodeArguments($toolCall['arguments'] ?? '{}');

        try {
            return match ($name) {
                'cover_page.generate' => $this->generateCoverPage($arguments, $user),
                'document.reconstruct' => $this->reconstructDocument($arguments),
                'structure.correct' => $this->correctStructure($arguments),
                'table_of_contents' => $this->generateTableOfContents($arguments),
                'web.search' => $this->webSearch($arguments, $planSlug),
                'image.generate' => $this->generateImage($arguments, $planSlug),
                default => ['error' => "Outil inconnu : {$name}"],
            };
        } catch (\Throwable $e) {
            Log::warning('Échec d\'exécution d\'un outil du chat', [
                'tool' => $name,
                'error' => $e->getMessage(),
            ]);

            return ['error' => "Échec de l'outil {$name} : ".$e->getMessage()];
        }
    }

    /* ------------------------------------------------------------------
     |  Outils internes
     | ------------------------------------------------------------------ */

    /**
     * Génère une page de garde DOCX via CoverPageRenderer.
     *
     * @param array<string, mixed> $arguments
     * @return array{result: string}
     */
    private function generateCoverPage(array $arguments, ?User $user): array
    {
        $templateId = (int) ($arguments['template_id'] ?? 0);
        $values = (array) ($arguments['values'] ?? []);
        $filename = $this->safeFilename(
            (string) ($arguments['output_filename'] ?? 'page_de_garde'),
            'docx'
        );

        $query = CoverPageTemplate::query();
        if (! ($arguments['allow_any_template'] ?? false) && $user) {
            $query->where(function ($q) use ($user) {
                $q->where('is_public', true)->orWhere('user_id', $user->id);
            });
        }
        $template = $query->find($templateId);

        if (! $template) {
            return ['error' => 'Gabarit de couverture introuvable (id='.$templateId.'). '
                .'Demandez à l\'utilisateur de créer ou sélectionner un gabarit.'];
        }

        $path = $this->storagePath($filename);
        $this->ensureDirectory($path);
        $phpWord = new \PhpOffice\PhpWord\PhpWord();
        $this->coverPageRenderer->render($phpWord, $template, $values);
        $writer = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save(Storage::disk('local')->path($path));

        return ['result' => 'Page de garde générée : '.$path];
    }

    /**
     * Reconstruit un document DOCX complet.
     *
     * @param array<string, mixed> $arguments
     * @return array{result: string}
     */
    private function reconstructDocument(array $arguments): array
    {
        $structure = (array) ($arguments['structure'] ?? []);
        $filename = $this->safeFilename(
            (string) ($arguments['output_filename'] ?? 'document_reconstruit'),
            'docx'
        );

        $reconstructor = new \App\DocAnalyzer\DocumentReconstructor();
        $path = $this->storagePath($filename);
        $this->ensureDirectory($path);
        $reconstructor->reconstruct($structure, Storage::disk('local')->path($path));

        return ['result' => 'Document reconstruit : '.$path];
    }

    /**
     * Applique des corrections de structure.
     *
     * @param array<string, mixed> $arguments
     * @return array{result: string}
     */
    private function correctStructure(array $arguments): array
    {
        $structure = (array) ($arguments['structure'] ?? []);
        $corrections = (array) ($arguments['corrections'] ?? []);

        $corrected = app(\App\Services\Detection\StructureCorrectionService::class)
            ->apply($structure, $corrections);

        return ['result' => 'Structure corrigée : '.json_encode($corrected, JSON_UNESCAPED_UNICODE)];
    }

    /**
     * Génère un DOCX contenant un sommaire (champ TOC PhpWord).
     *
     * @param array<string, mixed> $arguments
     * @return array{result: string}
     */
    private function generateTableOfContents(array $arguments): array
    {
        $title = (string) ($arguments['title'] ?? 'SOMMAIRE');
        $filename = $this->safeFilename(
            (string) ($arguments['output_filename'] ?? 'sommaire'),
            'docx'
        );

        $phpWord = new \PhpOffice\PhpWord\PhpWord();
        $phpWord->getSettings()->setUpdateFields(true);
        $section = $phpWord->addSection();

        $section->addText($title !== '' ? $title : 'SOMMAIRE', ['bold' => true, 'size' => 16]);
        // Champ TOC natif (niveaux 1-3), mis à jour à l'ouverture du document
        $section->addTOC(null, null, 1, 3);

        $path = $this->storagePath($filename);
        $this->ensureDirectory($path);
        $writer = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save(Storage::disk('local')->path($path));

        return ['result' => 'Sommaire généré : '.$path];
    }

    /* ------------------------------------------------------------------
     |  Outils externes
     | ------------------------------------------------------------------ */

    /**
     * Recherche web via OpenRouter (web_search_options).
     *
     * @param array<string, mixed> $arguments
     * @return array{result: string}
     */
    private function webSearch(array $arguments, string $planSlug): array
    {
        $query = (string) ($arguments['query'] ?? '');
        if ($query === '') {
            return ['error' => 'Requête de recherche vide.'];
        }

        $response = $this->openRouter->chat(
            'web_search',
            [
                ['role' => 'user', 'content' => $query],
            ],
            $planSlug,
            [
                'web_search_options' => ['search_context_size' => 'high'],
            ],
        );

        $content = (string) ($response['content'] ?? '');
        $citations = $response['raw']['citations'] ?? [];

        $result = $content;
        if (! empty($citations)) {
            $result .= "\n\nSources :\n";
            foreach ($citations as $i => $citation) {
                $result .= ($i + 1).'. '.($citation['title'] ?? 'Source')
                    .' — '.($citation['url'] ?? '');
            }
        }

        return ['result' => $result];
    }

    /**
     * Génération d'image via OpenRouter (gpt-image-*).
     *
     * @param array<string, mixed> $arguments
     * @return array{result: string}
     */
    private function generateImage(array $arguments, string $planSlug): array
    {
        $prompt = (string) ($arguments['prompt'] ?? '');
        if ($prompt === '') {
            return ['error' => 'Description de l\'image vide.'];
        }

        $filename = $this->safeFilename(
            (string) ($arguments['output_filename'] ?? 'image_generee'),
            'png'
        );

        $response = $this->openRouter->chat(
            'image_generation',
            [
                ['role' => 'user', 'content' => $prompt],
            ],
            $planSlug,
            [
                'response_format' => ['type' => 'image'],
                'n' => 1,
            ],
        );

        // Le binaire de l'image peut arriver en base64 dans le contenu
        $content = (string) ($response['content'] ?? '');
        $imageData = null;

        if (preg_match('/^data:image\/(png|jpeg|jpg|webp);base64,(.+)$/s', $content, $m)) {
            $imageData = base64_decode($m[2], true);
            $extension = $m[1] === 'jpeg' ? 'jpg' : $m[1];
        } else {
            // Fallback : le binaire brut peut être dans content
            $imageData = $content;
            $extension = 'png';
        }

        if ($imageData === null || $imageData === '') {
            return ['error' => 'Aucune image retournée par le modèle.'];
        }

        $path = $this->storagePath($filename, $extension);
        Storage::disk('local')->put($path, $imageData);

        return ['result' => 'Image générée : '.$path.' ('.strlen($imageData).' octets)'];
    }

    /* ------------------------------------------------------------------
     |  Utilitaires
     | ------------------------------------------------------------------ */

    /**
     * Décode les arguments JSON d'un tool_call.
     *
     * @return array<string, mixed>
     */
    private function decodeArguments(mixed $arguments): array
    {
        if (is_array($arguments)) {
            return $arguments;
        }

        if (! is_string($arguments) || $arguments === '') {
            return [];
        }

        $decoded = json_decode($arguments, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Chemin de stockage relatif (disk 'local') avec nom nettoyé.
     */
    private function storagePath(string $filename, string $extension = ''): string
    {
        $base = 'chat/generated/'.date('Y/m/d');
        $name = $this->safeFilename($filename, $extension ?: pathinfo($filename, PATHINFO_EXTENSION));

        return $base.'/'.$name;
    }

    /**
     * Garantit que le répertoire parent d'un chemin existe (PHPWord exige
     * un dossier présent avant d'écrire le fichier).
     */
    private function ensureDirectory(string $path): void
    {
        $directory = \dirname(Storage::disk('local')->path($path));
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
    }

    /**
     * Nettoie un nom de fichier (supprime caractères dangereux, limite longueur).
     */
    private function safeFilename(string $name, string $extension): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?? 'fichier';
        $name = trim($name, '._-');
        $name = $name !== '' ? $name : 'fichier';
        $name = substr($name, 0, 80);
        $extension = preg_replace('/[^a-z0-9]/i', '', $extension) ?: 'docx';

        return $name.'.'.$extension;
    }
}
