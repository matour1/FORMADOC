<?php

declare(strict_types=1);

namespace App\DocAnalyzer;

use InvalidArgumentException;

/**
 * Orchestrateur de l'analyse structurelle d'un document Word.
 *
 * Pipeline :
 *   1. DocumentParser::parse()      → extraction structurelle (styles + positions)
 *   2. RuleBasedDetector::detect()  → détection déterministe par règles (prioritaire)
 *   3. DeepSeekAnalyzer::analyze()  → IA complémentaire UNIQUEMENT si une catégorie
 *                                      critique (titres, sous_titres) est vide, ou si
 *                                      forceIA=true ; jamais fatale en cas d'échec
 *   4. ResultMerger::merge()        → règles d'abord, IA comble les manques
 *
 * Le résultat est conforme au contrat AnalyzerResult::CATEGORIES :
 *   { titres, sous_titres, en_tetes, pieds_de_page, tableaux, images,
 *     elements_flottants }
 *
 * Chaque item : { texte, position (section_index, element_index, parent),
 *                 styles, type }
 */
class DocAnalyzer
{
    /**
     * Chemin du fichier de règles (config/analyzer.yaml ou équivalent).
     */
    private ?string $configPath;

    /**
     * @param  string|null  $configPath  Chemin du fichier de règles (défaut : config/analyzer.php)
     */
    public function __construct(?string $configPath = null)
    {
        $this->configPath = $configPath;
    }

    /**
     * Méthodes de détection des titres.
     *
     *  - 'regex' : déterministe et rapide (styles Word + motifs regex).
     *    Aucun appel IA automatique : l'IA n'intervient que si l'utilisateur
     *    l'a explicitement activée (exigence Phase 4 — IA non intrusive).
     *  - 'ia'    : appel systématique à l'IA (DeepSeek), plus lent.
     */
    public const METHOD_REGEX = 'regex';

    public const METHOD_IA = 'ia';

    /**
     * Analyse un document et retourne la structure détectée.
     *
     * @param  string  $filePath  Chemin absolu du fichier DOCX
     * @param  bool  $forceIA  Force l'appel à l'IA même si les règles suffisent
     * @param  string  $titleMethod  Méthode de détection des titres :
     *                               'regex' (défaut) ou 'ia'
     * @return array<string, array<int, array<string, mixed>>> Résultat conforme au contrat
     *
     * @throws InvalidArgumentException Si le fichier de règles est introuvable
     */
    public function analyze(string $filePath, bool $forceIA = false, string $titleMethod = self::METHOD_REGEX): array
    {
        // 1. Extraction structurelle
        $parser = new DocumentParser($filePath);
        $parsed = $parser->parse();

        // 2. Détection déterministe (prioritaire)
        $rules = $this->loadRules();
        $detector = new RuleBasedDetector($rules);
        $rulesResult = $detector->detect($parsed);

        // 2bis. Méthode 'regex' : complément par motifs textuels (numérotation,
        //       mots-clés). Le RuleBasedDetector reste toujours actif pour les
        //       catégories non-titres (en-têtes, pieds de page, tableaux…).
        $regexResult = AnalyzerResult::empty();
        if ($titleMethod === self::METHOD_REGEX) {
            $regexTitles = (new RegexTitleDetector)->detect($parsed['context_text_with_positions']);
            $regexResult['titres'] = $regexTitles['titres'];
            $regexResult['sous_titres'] = $regexTitles['sous_titres'];
        }

        $rulesResult = (new ResultMerger)->merge($rulesResult, $regexResult);

        // 3. IA complémentaire UNIQUEMENT si demandée explicitement.
        //    Exigence Phase 4 : aucun fallback automatique vers l'IA — si
        //    l'utilisateur n'a pas coché « Utiliser l'assistance IA », aucun
        //    appel externe n'est émis, même si aucune catégorie critique
        //    (titres, sous_titres) n'a été détectée.
        $iaResult = AnalyzerResult::empty();
        $callIa = $forceIA || $titleMethod === self::METHOD_IA;

        if ($callIa) {
            $iaResult = $this->analyzeWithIa($parsed['context_text_with_positions']);
        }

        // 4. Fusion déterministe (règles d'abord, IA comble les manques)
        $merger = new ResultMerger;

        return $merger->merge($rulesResult, $iaResult);
    }

    /**
     * Applique un gabarit de mise en forme à un document.
     *
     * Phase 2 : le gabarit est appliqué en reconstruisant le document à
     * partir de sa structure détectée (DocumentReconstructor). Le paramètre
     * $gabarit est transmis comme options de reconstruction (non utilisées
     * pour l'instant, conservées pour la compatibilité Phase 3).
     *
     * @param  string  $filePath  Chemin absolu du fichier DOCX à formater
     * @param  array<string, mixed>  $gabarit  Règles de mise en forme (police, taille, interligne…)
     * @return array<string, mixed> Informations sur l'application du gabarit
     */
    public function applyStyles(string $filePath, array $gabarit): array
    {
        // 1. Analyse de la structure existante (règles déterministes)
        $analysis = $this->analyze($filePath);

        // 2. Reconstruction du document avec la structure détectée
        $reconstructor = new DocumentReconstructor;
        $outputPath = $this->outputPathFor($filePath);

        $reconstructor->reconstruct($analysis, $outputPath);

        return [
            'success' => true,
            'output_path' => $outputPath,
            'gabarit' => $gabarit,
            'message' => 'Document reconstruit avec la structure détectée (Phase 2).',
        ];
    }

    /**
     * Détermine le chemin du fichier reconstruit (même dossier, suffixe
     * "-reconstruit").
     */
    private function outputPathFor(string $filePath): string
    {
        $dir = dirname($filePath);
        $base = pathinfo($filePath, PATHINFO_FILENAME);
        $ext = pathinfo($filePath, PATHINFO_EXTENSION);

        return $dir.DIRECTORY_SEPARATOR.$base.'-reconstruit.'.$ext;
    }

    /**
     * Charge les règles de détection depuis le fichier config.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws InvalidArgumentException Si le fichier est introuvable ou sans règles
     */
    private function loadRules(): array
    {
        $path = $this->configPath ?? config_path('analyzer.php');

        if (! is_file($path)) {
            throw new InvalidArgumentException(
                "DocAnalyzer : fichier de règles introuvable : {$path}"
            );
        }

        /** @var array<string, mixed> $config */
        $config = require $path;

        $rules = $config['rules'] ?? null;
        if (! is_array($rules) || $rules === []) {
            throw new InvalidArgumentException(
                "DocAnalyzer : aucune règle trouvée dans {$path} (clé 'rules' vide ?)"
            );
        }

        return $rules;
    }

    /**
     * Appelle l'IA complémentaire (jamais fatale).
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function analyzeWithIa(string $contextTextWithPositions): array
    {
        try {
            $apiKey = (string) config('deepseek.api_key', '');

            if ($apiKey === '') {
                return AnalyzerResult::empty();
            }

            $analyzer = new DeepSeekAnalyzer($apiKey);

            return $analyzer->analyze($contextTextWithPositions);
        } catch (\Throwable $e) {
            // Ne jamais bloquer l'analyse : on retombe sur les règles seules.
            return AnalyzerResult::empty();
        }
    }
}
