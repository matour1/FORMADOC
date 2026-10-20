<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Document\Adapters\DocxNativeAdapter;
use App\Document\Structure\BlockType;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Compare la détection du pipeline natif sur deux documents.
 *
 * **Pourquoi cette commande.** L'analyseur (`formadoc:analyse`) rapporte des
 * SIGNAUX bruts ; il ne dit pas ce que le pipeline en FERA. Pour mesurer un
 * changement de règle (rappel, précision), il faut donc voir la sortie réelle —
 * sinon on mesure l'entrée et on croit mesurer le résultat.
 *
 * **Ce qu'elle compare.** Le nombre de titres produits par le pipeline sur
 * plusieurs fichiers, et la liste des textes. C'est l'instrument qui permet de
 * dire « ce changement récupère N titres » au lieu de l'espérer.
 */
#[Signature('formadoc:compare
    {--ref= : Fichiers de référence (répétable), séparés par des virgules}
    {--json : Sortie JSON}')]
#[Description('Compare la détection de titres du pipeline natif sur plusieurs .docx (mesure de rappel)')]
class FormadocCompare extends Command
{
    public function handle(DocxNativeAdapter $adapter): int
    {
        $liste = (string) ($this->option('ref') ?? '');

        if ($liste === '') {
            $this->error('Indiquez au moins un fichier avec --ref=chemin.docx');

            return self::FAILURE;
        }

        $resultats = [];

        foreach (array_filter(array_map('trim', explode(',', $liste))) as $fichier) {
            if (! is_file($fichier)) {
                $this->warn("Fichier introuvable, ignoré : {$fichier}");

                continue;
            }

            $document = $adapter->convert($fichier, 'compare');

            $titres = array_map(
                static fn ($bloc): array => [
                    'texte' => $bloc->text,
                    'niveau' => $bloc->headingLevel,
                    'confiance' => $bloc->confidence,
                ],
                $document->headings(),
            );

            $resultats[basename($fichier)] = [
                'titres' => count($titres),
                'legendes' => count($document->blocksOfType(BlockType::Caption)),
                'tableaux' => count($document->blocksOfType(BlockType::Table)),
                'images' => count($document->blocksOfType(BlockType::Image)),
                'detail' => $titres,
            ];
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(
                $resultats,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));

            return self::SUCCESS;
        }

        $lignes = [];

        foreach ($resultats as $nom => $r) {
            $lignes[] = [$nom, $r['titres'], $r['legendes'], $r['tableaux'], $r['images']];
        }

        $this->table(['Fichier', 'Titres', 'Légendes', 'Tableaux', 'Images'], $lignes);

        return self::SUCCESS;
    }
}
