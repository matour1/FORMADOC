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
     *
     * @var string|null
     */
    private ?string $configPath;

    /**
     * Catégories considérées critiques : si l'une d'elles est vide après les
     * règles, on appelle l'IA pour tenter de la compléter.
     *
     * @var string[]
     */
    private const CRITICAL_CATEGORIES = ['titres', 'sous_titres'];

    /**
     * @param string|null $configPath Chemin du fichier de règles (défaut : config/analyzer.yaml)
     */
    public function __construct(?string $configPath = null)
    {
        $this->configPath = $configPath;
    }

    /**
     * Analyse un document et retourne la structure détectée.
     *
     * @param string $filePath Chemin absolu du fichier DOCX
     * @param bool   $forceIA  Force l'appel à l'IA même si les règles suffisent
     *
     * @return array<string, array<int, array<string, mixed>>> Résultat conforme au contrat
     *
     * @throws InvalidArgumentException Si le fichier de règles est introuvable
     */
    public function analyze(string $filePath, bool $forceIA = false): array
    {
        // 1. Extraction structurelle
        $parser = new DocumentParser($filePath);
        $parsed = $parser->parse();

        // 2. Détection déterministe (prioritaire)
        $rules = $this->loadRules();
        $detector = new RuleBasedDetector($rules);
        $rulesResult = $detector->detect($parsed);

        // 3. IA complémentaire si nécessaire
        $iaResult = AnalyzerResult::empty();

        if ($forceIA || $this->criticalCategoriesEmpty($rulesResult)) {
            $iaResult = $this->analyzeWithIa($parsed['context_text_with_positions']);
        }

        // 4. Fusion déterministe (règles d'abord, IA comble les manques)
        $merger = new ResultMerger();

        return $merger->merge($rulesResult, $iaResult);
    }

    /**
     * Applique un gabarit de mise en forme à un document.
     *
     * Phase 2 (gabarit) : expérimental, hors scope V1. Déclenche une erreur
     * explicite tant que DocumentReconstructor n'est pas implémenté.
     *
     * @param string $filePath Chemin absolu du fichier DOCX à formater
     * @param array<string, mixed> $gabarit Règles de mise en forme (police, taille, interligne…)
     *
     * @return array<string, mixed> Informations sur l'application du gabarit
     *
     * @throws \RuntimeException Toujours (non implémenté en V1)
     */
    public function applyStyles(string $filePath, array $gabarit): array
    {
        throw new \RuntimeException(
            'DocAnalyzer::applyStyles : gabarit non implémenté en V1 (Phase 2 — DocumentReconstructor).'
        );
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

        if (!is_file($path)) {
            throw new InvalidArgumentException(
                "DocAnalyzer : fichier de règles introuvable : {$path}"
            );
        }

        /** @var array<string, mixed> $config */
        $config = require $path;

        $rules = $config['rules'] ?? null;
        if (!is_array($rules) || $rules === []) {
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

    /**
     * Vérifie si une catégorie critique est vide (déclenche l'IA).
     *
     * @param array<string, array<int, array<string, mixed>>> $result
     */
    private function criticalCategoriesEmpty(array $result): bool
    {
        foreach (self::CRITICAL_CATEGORIES as $category) {
            if (empty($result[$category] ?? [])) {
                return true;
            }
        }

        return false;
    }
}
