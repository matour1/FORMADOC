<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Docx\DocxAnalyzer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Analyse LECTURE SEULE d'un `.docx` — instrument de mesure du pipeline.
 *
 * **Pourquoi cette commande existe.** Toutes les mesures demandées par les
 * instructions d'implémentation (§4) étaient inaccessibles : il fallait écrire du
 * code ad hoc pour compter les paragraphes vides ou les champs `SEQ`. Corriger une
 * règle de détection sans cet instrument revient à modifier à l'aveugle.
 *
 * **Ce qu'elle garantit.** Elle ne modifie RIEN dans le fichier analysé : le
 * rapport décrivant le document est produit en mémoire, puis affiché ou écrit
 * dans un fichier distinct (`--output`). L'original reste intact, ce qui autorise
 * son exécution sur un document utilisateur.
 */
#[Signature('formadoc:analyse
    {fichier : Chemin du fichier .docx à analyser}
    {--json : Produit le rapport complet en JSON (par défaut : résumé lisible)}
    {--output= : Écrit le résultat JSON dans un fichier au lieu de la console}')]
#[Description('Analyse un .docx en LECTURE SEULE : statistiques, styles, champs, sommaires, candidats titres et légendes')]
class FormadocAnalyse extends Command
{
    public function handle(DocxAnalyzer $analyseur): int
    {
        $chemin = (string) $this->argument('fichier');

        if (! is_file($chemin)) {
            $this->error("Fichier introuvable : {$chemin}");

            return self::FAILURE;
        }

        if (! str_ends_with(mb_strtolower($chemin), '.docx')) {
            $this->error('Seuls les fichiers .docx sont analysés.');
            $this->line('Un .doc ou un .docm n\'est pas un paquet OOXML lisible par cette commande.');

            return self::FAILURE;
        }

        try {
            $rapport = $analyseur->analyser($chemin);
        } catch (\Throwable $e) {
            $this->error('Analyse impossible : '.$e->getMessage());

            return self::FAILURE;
        }

        $json = json_encode(
            $rapport,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($json === false) {
            $this->error('Sérialisation JSON impossible.');

            return self::FAILURE;
        }

        // `--output` écrit dans un fichier DISTINCT : le document analysé n'est
        // jamais touché, même si l'appelant se trompe de chemin.
        if ($dest = $this->option('output')) {
            File::put((string) $dest, $json);
            $this->info('Rapport écrit dans '.$dest);
            $this->line('Le document analysé n\'a pas été modifié.');

            return self::SUCCESS;
        }

        if ($this->option('json')) {
            $this->line($json);

            return self::SUCCESS;
        }

        $this->afficherResume($rapport);

        return self::SUCCESS;
    }

    /**
     * Résumé lisible : les chiffres qui décident d'une correction.
     *
     * @param  array<string, mixed>  $rapport
     */
    private function afficherResume(array $rapport): void
    {
        $fichier = $rapport['fichier'];
        $stats = $rapport['statistiques'];

        $this->info('FORMADOC — analyse en lecture seule');
        $this->line(sprintf(
            '  %s · %s Ko · %d parties',
            $fichier['nom'],
            number_format($fichier['octets'] / 1024, 1, ',', ' '),
            $fichier['parties']
        ));
        $this->newLine();

        $this->line('<options=bold>Corps du document</>');
        $this->table(
            ['Mesure', 'Valeur'],
            [
                ['Paragraphes', $stats['paragraphes']],
                ['dont vides', $stats['paragraphes_vides']],
                ['Plus longue série de vides', $stats['serie_vides_max']],
                ['Tableaux', $stats['tableaux']],
                ['Sauts de page explicites', $stats['sauts_page_explicites']],
                ['Images', $stats['images']],
                ['dont en ligne / ancrées', $stats['images_en_ligne'].' / '.$stats['images_ancrées']],
                ['Formes vectorielles', $stats['formes']],
                ['Paragraphes en style de titre', $stats['styles_de_titre']],
                ['Signets _Toc', $stats['signets_toc']],
            ]
        );

        $zones = $rapport['zones_de_texte'];
        $this->newLine();
        $this->line('<options=bold>Zones de texte</>');
        $this->table(
            ['Mesure', 'Valeur'],
            [
                // `total` compte AUSSI la copie VML de `mc:Fallback`. La valeur
                // `distinctes` est celle qui doit servir de référence : compter
                // les deux reviendrait à voir chaque zone en double.
                ['Occurrences (avec mc:Fallback)', $zones['total']],
                ['Copies de fallback', $zones['dans_fallback']],
                ['Zones distinctes', $zones['distinctes']],
                ['Candidates à la promotion', $zones['candidates_titre']],
            ]
        );

        $this->newLine();
        $this->line('<options=bold>Sommaires déjà présents</>');
        $sommaires = $rapport['sommaires'];
        $this->table(
            ['Type', 'Détecté'],
            [
                ['En champ TOC', $sommaires['en_champ']],
                ['Par style (toc N)', $sommaires['par_style']],
                ['Lignes tapées (points de suite)', $sommaires['lignes_tapees']],
            ]
        );

        $this->newLine();
        $this->line('<options=bold>Champs Word</>');

        if ($rapport['champs']['total'] === 0) {
            $this->line('  Aucun champ.');
        } else {
            $lignes = [];

            foreach ($rapport['champs']['par_type'] as $type => $nombre) {
                $lignes[] = [$type, $nombre];
            }

            $this->table(['Type', 'Occurrences'], $lignes);
        }

        $this->newLine();
        $this->line('<options=bold>Candidats titres</>');
        $titres = $rapport['candidats_titres'];
        $this->table(
            ['Source', 'Nombre'],
            [
                ['Style de titre', $titres['par_style']['nombre']],
                ['Motif de numérotation', $titres['par_motif']['nombre']],
                ['À vérifier (gras court)', $titres['a_verifier']['nombre']],
            ]
        );

        $this->newLine();
        $this->line('<options=bold>Candidats légendes</>');
        $legendes = $rapport['candidats_legendes'];

        if ($legendes['nombre'] === 0) {
            $this->line('  Aucune légende détectée.');
        } else {
            $parPreuve = [];

            foreach ($legendes['detail'] as $legende) {
                $parPreuve[$legende['preuve']] = ($parPreuve[$legende['preuve']] ?? 0) + 1;
            }

            $lignes = [];

            foreach ($parPreuve as $preuve => $nombre) {
                $lignes[] = [$preuve, $nombre];
            }

            $this->table(['Preuve', 'Nombre'], $lignes);
        }

        $this->newLine();
        $this->line('<options=bold>Sections</>');
        $this->table(
            ['#', 'Numérotation', 'Début', 'Page de garde distincte'],
            array_map(
                static fn (array $s): array => [
                    $s['index'],
                    $s['format_numerotation'] ?? '—',
                    $s['debut_numerotation'] ?? '—',
                    $s['page_de_garde_distincte'] ? 'oui' : 'non',
                ],
                $rapport['sections']['detail']
            )
        );

        $garde = $rapport['candidats_page_de_garde'];

        if ($garde['candidat'] ?? false) {
            $this->newLine();
            $this->warn(sprintf(
                'Page de garde possible : %d paragraphe(s) avant le premier titre, début « %s ».',
                $garde['paragraphes_avant_titre'],
                $garde['debut_texte'] ?? ''
            ));
        }

        if ($rapport['avertissements'] !== []) {
            $this->newLine();
            $this->warn('Avertissements :');

            foreach ($rapport['avertissements'] as $avertissement) {
                $this->line('  - '.$avertissement);
            }
        }

        $this->newLine();
        $this->line('Le document analysé n\'a pas été modifié.');
    }
}
