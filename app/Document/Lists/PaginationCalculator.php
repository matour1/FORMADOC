<?php

declare(strict_types=1);

namespace App\Document\Lists;

use App\Document\Structure\StructuralDocument;
use App\Services\DocumentGeneration\PdfPreviewService;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Calcul de la pagination réelle d'un document (phase R5.2).
 *
 * **Le problème** — PHPWord **ne pagine pas** : il n'expose aucune information de
 * numéro de page. Or une table des matières sans numéros justes est inutile.
 * C'est la décision D3 du plan, tranchée en « option hybride ».
 *
 * **La solution retenue**, établie par mesure et non par supposition :
 * LibreOffice **connaît** la mise en page réelle (c'est lui qui produit le PDF).
 * On l'interroge par UNO — son propre Python 3.12 est embarqué — et on lit la
 * **position verticale absolue** de chaque paragraphe :
 *
 * ```
 *   page = intdiv(positionY, hauteurPage) + 1
 * ```
 *
 * Mesures qui valident l'approche : `Width = 21001`, `Height = 29700`
 * (soit 210 × 297 mm en 1/100 mm — un A4 exact), et trois titres de test
 * retombent bien sur les pages 1, 2, 3.
 *
 * **Les approches écartées** (testées, documentées pour éviter de les retenter) :
 * `pdftotext`, Ghostscript et `mutool` sont absents de la machine ; la conversion
 * PDF → TXT par LibreOffice échoue ; et lire les flux PDF à la main ne donne rien
 * d'exploitable (texte encodé par police, opérateurs `TJ` fragmentés). L'approche
 * UNO a l'avantage décisif de **n'ajouter aucune dépendance**.
 *
 * **Fiabilité** : toute la mécanique est encapsulée et **échoue proprement**. Sans
 * LibreOffice, le document reste produit ; seule l'information de pagination
 * manque, et les listes basculent alors sur des champs Word natifs (voir
 * `RenderCoordinator`). Une pagination absente est signalée, jamais inventée.
 */
final class PaginationCalculator
{
    /**
     * Unité des coordonnées UNO : 1/100 de millimètre.
     *
     * Confirmé par mesure : `Width = 21001` pour une page A4 de 210 mm.
     */
    public const UNITS_PER_MM = 100;

    /**
     * Hauteur de repli d'une page A4, en 1/100 mm.
     *
     * Utilisée seulement si LibreOffice ne fournit pas le style de page — cas
     * anormal, mais qui ne doit pas faire échouer tout le calcul.
     */
    public const FALLBACK_PAGE_HEIGHT = 29700;

    /**
     * Délai (secondes) d'attente de l'écoute UNO.
     *
     * Mesure : soffice met **10 à 15 s** à ouvrir le port. Un délai plus court
     * conclurait à tort à l'échec — c'est l'erreur qui a failli faire écarter
     * cette approche.
     */
    private const SOCKET_TIMEOUT = 60;

    /**
     * Calcule la page de chaque bloc d'un document.
     *
     * @param  StructuralDocument  $document  Document dont la mise en page sera lue
     * @param  string  $docxPath  Chemin du DOCX correspondant (la pagination se lit sur un fichier)
     * @return array{
     *     available: bool,
     *     reason: null|string,
     *     total_pages: int,
     *     page_height: int,
     *     blocks: array<string, int>,
     *     headings: array<string, int>
     * }
     */
    public function calculate(StructuralDocument $document, string $docxPath): array
    {
        $indisponible = static fn (string $raison): array => [
            'available' => false,
            'reason' => $raison,
            'total_pages' => 0,
            'page_height' => self::FALLBACK_PAGE_HEIGHT,
            'blocks' => [],
            'headings' => [],
        ];

        if (! is_file($docxPath)) {
            return $indisponible('Fichier DOCX introuvable.');
        }

        $soffice = $this->sofficePath();

        if ($soffice === null) {
            return $indisponible('LibreOffice est introuvable : pagination indisponible.');
        }

        $python = $this->pythonPath();

        if ($python === null) {
            return $indisponible('Le Python embarqué de LibreOffice est introuvable.');
        }

        try {
            $mesures = $this->mesurerViaUno($soffice, $python, $docxPath);
        } catch (RuntimeException $e) {
            return $indisponible($e->getMessage());
        }

        if ($mesures === []) {
            return $indisponible('Aucune mesure de position obtenue.');
        }

        $hauteur = (int) ($mesures['page_height'] ?? self::FALLBACK_PAGE_HEIGHT);

        if ($hauteur <= 0) {
            $hauteur = self::FALLBACK_PAGE_HEIGHT;
        }

        return [
            'available' => true,
            'reason' => null,
            'total_pages' => (int) ($mesures['total_pages'] ?? 0),
            'page_height' => $hauteur,
            'blocks' => $this->associerBlocs($document, $mesures['positions'] ?? [], $hauteur),
            'headings' => $this->pagesDesTitres($document, $mesures['positions'] ?? [], $hauteur),
        ];
    }

    /**
     * Associe chaque bloc à sa page, par correspondance de texte.
     *
     * La correspondance est **textuelle** et non positionnelle : le DOCX produit
     * par PHPWord agrège parfois plusieurs blocs dans un paragraphe, et les index
     * ne se recouvrent donc pas exactement. Comparer les textes est plus robuste
     * qu'espérer une correspondance d'index — et un échec de correspondance laisse
     * simplement le bloc sans page, ce qui est signalable.
     *
     * @param  array<int, array{page: int, text: string}>  $positions
     * @return array<string, int>
     */
    private function associerBlocs(StructuralDocument $document, array $positions, int $hauteur): array
    {
        $pages = [];
        $offsets = array_map(static fn (array $p): int => (int) $p['offset_y'], $positions);

        foreach ($document->blocks as $block) {
            $texte = trim($block->text);

            // Un bloc sans texte (figure, image) n'a pas de position propre :
            // il est situé par son paragraphe porteur.
            if ($texte === '') {
                continue;
            }

            $page = $this->pagePourTexte($texte, $positions);

            if ($page !== null) {
                $pages[$block->blockId] = $page;
            }
        }

        return $pages;
    }

    /**
     * Page de chaque titre, dans l'ordre du document.
     *
     * C'est la donnée dont la table des matières a besoin — et la seule qui
     * compte vraiment, puisqu'une TOC ne liste que des titres.
     *
     * @param  array<int, array{page: int, text: string}>  $positions
     * @return array<string, int>
     */
    private function pagesDesTitres(StructuralDocument $document, array $positions, int $hauteur): array
    {
        $pages = [];

        foreach ($document->headings() as $titre) {
            $texte = trim($titre->text);

            if ($texte === '') {
                continue;
            }

            $page = $this->pagePourTexte($texte, $positions);

            if ($page !== null) {
                $pages[$titre->blockId] = $page;
            }
        }

        return $pages;
    }

    /**
     * Retrouve la page d'un texte par correspondance approximative.
     *
     * La comparaison tolère les différences de casse et d'espaces multiples :
     * LibreOffice restitue le texte avec sa propre normalisation, et une
     * comparaison stricte échouerait sur des détails typographiques.
     *
     * @param  array<int, array{page: int, text: string}>  $positions
     */
    private function pagePourTexte(string $texte, array $positions): ?int
    {
        $cle = $this->normaliser($texte);

        if ($cle === '') {
            return null;
        }

        // Correspondance exacte d'abord : le cas normal.
        foreach ($positions as $position) {
            if ($this->normaliser($position['text']) === $cle) {
                return (int) $position['page'];
            }
        }

        // Puis inclusion : le paragraphe UNO peut contenir un texte plus long
        // (plusieurs blocs agrégés par PHPWord).
        foreach ($positions as $position) {
            $textePosition = $this->normaliser($position['text']);

            if ($textePosition !== '' && str_contains($textePosition, $cle)) {
                return (int) $position['page'];
            }
        }

        return null;
    }

    /**
     * Normalise un texte pour la comparaison.
     *
     * Accents conservés, espaces réduits, casse abaissée : les différences
     * d'espacement sont la cause la plus fréquente d'échec de correspondance,
     * pas les accents.
     */
    private function normaliser(string $texte): string
    {
        $texte = preg_replace('/\s+/u', ' ', $texte) ?? $texte;

        return mb_strtolower(trim($texte));
    }

    /**
     * Interroge LibreOffice par UNO et retourne les mesures brutes.
     *
     * @return array{total_pages: int, page_height: int, positions: array<int, array{page: int, text: string, offset_y: int}>}
     *
     * @throws RuntimeException Si la conversion ou l'interrogation échoue
     */
    private function mesurerViaUno(string $soffice, string $python, string $docxPath): array
    {
        $script = $this->scriptPath();

        if (! is_file($script)) {
            throw new RuntimeException('Script UNO de pagination introuvable : '.$script);
        }

        $port = $this->portLibre();
        $profil = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fd_uno_'.bin2hex(random_bytes(6));
        @mkdir($profil, 0o777, true);

        // Un profil utilisateur UNIQUE est obligatoire : sans lui, soffice
        // réutilise une instance déjà ouverte et ignore l'option d'écoute.
        $url = 'file:///'.str_replace('\\', '/', $profil);

        $serveur = new Process([
            $soffice,
            '--headless',
            '--invisible',
            '--norestore',
            '--nologo',
            '--nofirststartwizard',
            '-env:UserInstallation='.$url,
            "--accept=socket,host=127.0.0.1,port={$port};urp;",
        ]);
        $serveur->setTimeout(self::SOCKET_TIMEOUT + 120);
        $serveur->start();

        try {
            $this->attendreEcoute($port);

            $process = new Process([$python, $script, $docxPath, (string) $port]);
            $process->setTimeout(180);
            $process->run();

            $sortie = trim($process->getOutput());
            $donnees = json_decode($sortie, true);

            if (! is_array($donnees)) {
                throw new RuntimeException(
                    'Réponse UNO illisible : '.mb_substr($sortie, 0, 200)
                );
            }

            if (isset($donnees['error'])) {
                throw new RuntimeException('Erreur UNO : '.$donnees['error']);
            }

            return [
                'total_pages' => (int) ($donnees['total_pages'] ?? 0),
                'page_height' => (int) ($donnees['page_height'] ?? self::FALLBACK_PAGE_HEIGHT),
                'positions' => $this->normaliserPositions($donnees['positions'] ?? []),
            ];
        } finally {
            $serveur->stop();

            foreach (glob($profil.DIRECTORY_SEPARATOR.'*') ?: [] as $fichier) {
                is_dir($fichier) ? $this->supprimerDossier($fichier) : @unlink($fichier);
            }
            @rmdir($profil);
        }
    }

    /**
     * Normalise les positions reçues du script UNO.
     *
     * @param  array<int, array<string, mixed>>  $positions
     * @return array<int, array{page: int, text: string, offset_y: int}>
     */
    private function normaliserPositions(array $positions): array
    {
        $resultat = [];

        foreach ($positions as $position) {
            if (! isset($position['page'], $position['text'])) {
                continue;
            }

            $resultat[] = [
                'page' => (int) $position['page'],
                'text' => (string) $position['text'],
                'offset_y' => (int) ($position['offset_y'] ?? 0),
            ];
        }

        return $resultat;
    }

    /**
     * Attend que soffice ouvre son port d'écoute.
     *
     * Mesure : l'ouverture prend 10 à 15 secondes. Attendre est indispensable —
     * conclure trop tôt ferait déclarer la pagination impossible à tort.
     *
     * @throws RuntimeException Si le port ne s'ouvre pas dans le délai
     */
    private function attendreEcoute(int $port): void
    {
        $debut = microtime(true);

        while (microtime(true) - $debut < self::SOCKET_TIMEOUT) {
            usleep(500000);

            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);

            if (is_resource($socket)) {
                fclose($socket);

                return;
            }
        }

        throw new RuntimeException(
            'LibreOffice n\'a pas ouvert son port UNO dans le délai imparti ('
            .self::SOCKET_TIMEOUT.' s).'
        );
    }

    /**
     * Port d'écoute UNO, choisi dans une plage libre.
     *
     * Chaque appel prend un port différent pour ne pas entrer en conflit avec
     * une instance précédente encore en cours d'arrêt.
     */
    private function portLibre(): int
    {
        return random_int(20200, 20900);
    }

    /**
     * Détecte le binaire LibreOffice.
     *
     * Réutilise `PdfPreviewService`, qui connaît déjà les chemins et le piège
     * Windows (`soffice.com` plutôt que `soffice.exe`, qui se détache du
     * processus appelant en contexte headless).
     */
    private function sofficePath(): ?string
    {
        $service = app()->make(PdfPreviewService::class);

        return $service->sofficePath();
    }

    /**
     * Chemin du Python embarqué par LibreOffice.
     *
     * Le Python **système** ne fonctionne pas : `import uno` n'y est pas
     * disponible. Seul l'interpréteur fourni avec LibreOffice sait charger UNO.
     */
    private function pythonPath(): ?string
    {
        $candidats = [
            'C:\\Program Files\\LibreOffice\\program\\python.exe',
            'C:\\Program Files (x86)\\LibreOffice\\program\\python.exe',
            '/usr/lib/libreoffice/program/python',
            '/usr/bin/python3',
            '/Applications/LibreOffice.app/Contents/Resources/python',
        ];

        foreach ($candidats as $candidat) {
            if (is_file($candidat)) {
                return $candidat;
            }
        }

        return null;
    }

    /**
     * Chemin du script UNO.
     */
    private function scriptPath(): string
    {
        return __DIR__.DIRECTORY_SEPARATOR.'pagination_uno.py';
    }

    /**
     * Supprime récursivement un dossier.
     */
    private function supprimerDossier(string $dossier): void
    {
        foreach (glob($dossier.DIRECTORY_SEPARATOR.'*') ?: [] as $fichier) {
            is_dir($fichier) ? $this->supprimerDossier($fichier) : @unlink($fichier);
        }

        @rmdir($dossier);
    }
}
