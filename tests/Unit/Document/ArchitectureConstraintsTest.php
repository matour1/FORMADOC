<?php

declare(strict_types=1);

namespace Tests\Unit\Document;

use App\Document\Editing\ToolWhitelist;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * Test d'architecture : verrouille les interdits de REFONTE_ARCHITECTURE.md §14.
 *
 * Ces règles sont invisibles à l'exécution : rien ne casse si on les viole, mais
 * le bénéfice de la refonte disparaît (lecture PHPWord) ou la facture dérape
 * (IA dans le déterministe). On les vérifie donc structurellement.
 *
 * Un échec ici signale une erreur de conception, pas un bug fonctionnel.
 */
class ArchitectureConstraintsTest extends TestCase
{
    /** Répertoire du nouveau pipeline. */
    private const DOCUMENT_PATH = 'app/Document';

    /**
     * Interdit §14.2 : « Ne jamais utiliser PHPWord pour LIRE un document source —
     * uniquement pour écrire la sortie finale. »
     *
     * Le nouveau pipeline étant exclusivement en amont (lecture/normalisation),
     * il ne doit contenir AUCUNE référence à PHPWord. L'écriture reste dans
     * `app/DocAnalyzer/DocumentReconstructor.php` (autorisé).
     */
    public function test_le_nouveau_pipeline_n_utilise_pas_phpword(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn(self::DOCUMENT_PATH) as $file) {
            // On analyse le CODE, pas les commentaires : plusieurs classes
            // mentionnent PHPWord pour expliquer ce qui n'est PAS fait ici
            // (« le remplaçant de l'ancienne lecture PHPWord »), et ces
            // mentions sont utiles à la compréhension.
            $code = $this->stripComments((string) file_get_contents($file->getPathname()));

            if (preg_match('/PhpOffice|PhpWord|IOFactory/', $code) === 1) {
                $offenders[] = $this->relativePath($file);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Interdit §14.2 : PHPWord ne doit jamais apparaître dans app/Document/** (lecture interdite).\n"
            ."Fichiers fautifs :\n - ".implode("\n - ", $offenders)
        );
    }

    /**
     * Interdit §14.1 : « Ne jamais faire générer par un LLM le contenu texte d'un
     * bloc table/paragraph existant sans demande explicite. »
     *
     * Le moteur de gabarit (déterministe) ne doit contenir aucun appel IA : son
     * coût doit rester nul et son résultat reproductible.
     */
    public function test_le_module_de_mise_en_forme_ne_contient_aucun_appel_ia(): void
    {
        if (! is_dir(base_path(self::DOCUMENT_PATH.'/Formatting'))) {
            $this->markTestSkipped('Le module Formatting (R3) n\'est pas encore implémenté.');
        }

        $offenders = [];

        foreach ($this->phpFilesIn(self::DOCUMENT_PATH.'/Formatting') as $file) {
            $contents = (string) file_get_contents($file->getPathname());

            if (preg_match('/OpenRouter|Anthropic|DeepSeek|OpenAI|Claude|llm/i', $contents) === 1) {
                $offenders[] = $this->relativePath($file);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Le moteur de mise en forme doit rester 100 % déterministe (aucun appel IA).\n"
            ."Fichiers fautifs :\n - ".implode("\n - ", $offenders)
        );
    }

    /**
     * La génération des listes (R5) ne doit consommer aucun token : calcul
     * déterministe sur le JSON structurel + pagination du moteur de rendu (§11).
     */
    public function test_le_module_des_listes_ne_contient_aucun_appel_ia(): void
    {
        if (! is_dir(base_path(self::DOCUMENT_PATH.'/Lists'))) {
            $this->markTestSkipped('Le module Lists (R5) n\'est pas encore implémenté.');
        }

        $offenders = [];

        foreach ($this->phpFilesIn(self::DOCUMENT_PATH.'/Lists') as $file) {
            $contents = (string) file_get_contents($file->getPathname());

            if (preg_match('/OpenRouter|Anthropic|DeepSeek|OpenAI|Claude|llm/i', $contents) === 1) {
                $offenders[] = $this->relativePath($file);
            }
        }

        $this->assertSame([], $offenders, 'La génération des listes doit rester déterministe (0 token).');
    }

    /**
     * Interdit §14.7 : « Ne jamais laisser le chat appeler un tool hors de la
     * liste blanche définie en section 9. »
     *
     * Vérifie qu'aucun tool d'édition n'existe en dehors du répertoire prévu :
     * la liste blanche est donc structurellement bornée.
     */
    public function test_les_tools_d_edition_sont_contenus_dans_un_seul_repertoire(): void
    {
        $toolsPath = self::DOCUMENT_PATH.'/Editing/Tools';

        if (! is_dir(base_path($toolsPath))) {
            $this->markTestSkipped('Les tools d\'édition (R6) ne sont pas encore implémentés.');
        }

        // Seuls les 5 tools d'édition sont autorisés (le 6e, ask_user_clarification,
        // vit dans Clarification/ car il est partagé avec le mode automatique).
        $expected = [
            'RewriteParagraphTool.php',
            'InsertBlockTool.php',
            'ModifyTableTool.php',
            'DeleteBlockTool.php',
            'RegenerateSectionTool.php',
        ];

        $actual = array_map(
            static fn (SplFileInfo $file): string => $file->getFilename(),
            $this->phpFilesIn($toolsPath, recursive: false)
        );

        // Les deux côtés sont triés : la comparaison porte sur l'ENSEMBLE des
        // fichiers présents, pas sur l'ordre de l'énumération du plan (qui reste
        // ci-dessus comme documentation du §9). Trier un seul côté rendrait
        // l'assertion impossible à satisfaire.
        sort($expected);
        sort($actual);

        $this->assertSame(
            $expected,
            $actual,
            "La liste blanche des tools d'édition (§9) a changé. "
            .'Tout nouveau tool doit être ajouté explicitement et justifié.'
        );

        // Cohérence entre le répertoire et la liste blanche : sans ce contrôle,
        // on pourrait créer un tool ici ET l'exposer au modèle dans
        // `ToolWhitelist` sans que les deux énumérations s'accordent.
        $declared = array_map(
            static fn (string $name): string => class_basename(
                ToolWhitelist::classFor($name)
            ).'.php',
            ToolWhitelist::editingToolNames()
        );

        sort($declared);

        $this->assertSame(
            $declared,
            $actual,
            'La liste blanche `ToolWhitelist` et le répertoire `Editing/Tools` ne correspondent pas. '
            .'Un tool présent sur disque doit être déclaré, et inversement.'
        );
    }

    /**
     * Aucune promesse d'identité pour une source reconstruite (§14.4).
     *
     * On cherche les formulations interdites dans les vues : « identique à 100 % »,
     * « copie exacte », « à l'identique » appliquées à un import.
     */
    public function test_les_vues_ne_promettent_jamais_une_identite_parfaite(): void
    {
        $offenders = [];
        $patterns = [
            '/identique\s+à\s+100\s*%/iu',
            '/100\s*%\s+identique/iu',
            '/copie\s+exacte/iu',
        ];

        foreach ($this->phpFilesIn('resources/views') as $file) {
            $contents = (string) file_get_contents($file->getPathname());

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $contents) === 1) {
                    $offenders[] = $this->relativePath($file);
                    break;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Interdit §14.4 : ne jamais afficher « identique à 100 % » (source reconstruite → '
            ."« reconstruction fidèle, validée par vous »).\n"
            ."Fichiers fautifs :\n - ".implode("\n - ", $offenders)
        );
    }

    /**
     * Le nouveau namespace respecte le découpage en modules de la refonte.
     *
     * Empêche l'apparition de code métier à la racine de `app/Document/`, ce qui
     * signalerait un module non identifié.
     */
    public function test_le_namespace_document_respecte_les_modules_attendus(): void
    {
        $expected = ['Adapters', 'Classification', 'Clarification', 'Editing', 'Exceptions', 'Formatting', 'Lists', 'Numbering', 'Structure'];

        $directories = array_map(
            static fn (SplFileInfo $file): string => $file->getFilename(),
            array_filter(
                iterator_to_array(new RecursiveDirectoryIterator(
                    base_path(self::DOCUMENT_PATH),
                    RecursiveDirectoryIterator::SKIP_DOTS
                )),
                static fn (SplFileInfo $file): bool => $file->isDir()
            )
        );
        sort($directories);

        // On ne vérifie que les dossiers créés à ce stade (les modules à venir
        // sont ajoutés au fur et à mesure des phases).
        foreach ($directories as $directory) {
            $this->assertContains(
                $directory,
                $expected,
                "Module inattendu dans app/Document/ : « {$directory} ». "
                .'Le découpage de la refonte doit être respecté (voir PLAN_REFONTE.md §2.4).'
            );
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Liste les fichiers PHP d'un répertoire du projet.
     *
     * @return array<int, SplFileInfo>
     */
    private function phpFilesIn(string $relativePath, bool $recursive = true): array
    {
        $absolute = base_path($relativePath);

        if (! is_dir($absolute)) {
            return [];
        }

        if (! $recursive) {
            $files = [];
            foreach (glob($absolute.'/*.php') ?: [] as $path) {
                $files[] = new SplFileInfo($path);
            }

            return $files;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absolute, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $files = [];
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * Chemin relatif à la racine du projet, pour un message d'erreur lisible.
     */
    private function relativePath(SplFileInfo $file): string
    {
        return str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
    }

    /**
     * Retire commentaires et docblocks d'un source PHP.
     *
     * Permet de vérifier le CODE réellement exécuté, sans être gêné par les
     * commentaires qui expliquent justement ce qu'on ne fait pas.
     */
    private function stripComments(string $source): string
    {
        $tokens = token_get_all($source);
        $code = '';

        foreach ($tokens as $token) {
            if (is_array($token)) {
                // T_COMMENT (* … *), T_DOC_COMMENT (/** … */) et T_INLINE_HTML.
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $code .= $token[1];

                continue;
            }

            $code .= $token;
        }

        return $code;
    }
}
