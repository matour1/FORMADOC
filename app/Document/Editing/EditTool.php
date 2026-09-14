<?php

declare(strict_types=1);

namespace App\Document\Editing;

use App\Document\Structure\StructuralDocument;

/**
 * Contrat commun aux tools d'édition (phase R6.1).
 *
 * Chaque tool reçoit un document structurel, applique **une** transformation, et
 * renvoie un nouveau document. Trois propriétés sont garanties par ce contrat :
 *
 *  1. **Fonction pure sur le document** — un tool ne modifie jamais l'objet reçu
 *     (`StructuralDocument` et `Block` sont immuables), il en produit un nouveau.
 *     C'est ce qui permet le snapshot avant action et l'annulation.
 *
 *  2. **Aucun accès à l'extérieur** — pas d'appel réseau, pas d'écriture de
 *     fichier, pas d'appel IA. Un tool est une transformation de données. Les
 *     effets de bord (persistance, ré-export) appartiennent à l'orchestrateur.
 *
 *  3. **Échec explicite** — un tool qui ne peut pas faire son travail lève une
 *     `EditingException` avec un message affichable. Il ne devine pas, et il
 *     n'applique jamais une transformation partielle.
 */
interface EditTool
{
    /**
     * Nom du tool, tel qu'exposé au modèle (identifiant de la liste blanche).
     */
    public function name(): string;

    /**
     * Nombre de blocs que l'action va affecter.
     *
     * Calculé **avant** toute modification : c'est ce qui permet à
     * l'orchestrateur de décider si une confirmation utilisateur est nécessaire
     * (§9.16) et de dimensionner le snapshot.
     *
     * @param  array<string, mixed>  $arguments  Arguments validés
     */
    public function affectedBlockCount(StructuralDocument $document, array $arguments): int;

    /**
     * Le tool est-il destructif (une annulation doit être possible) ?
     */
    public function isDestructive(): bool;

    /**
     * Applique la transformation.
     *
     * @param  array<string, mixed>  $arguments  Arguments déjà validés par `ToolOutputValidator`
     * @return array{document: StructuralDocument, summary: string, details: array<string, mixed>}
     *
     * @throws EditingException Si le tool ne peut pas appliquer son action
     */
    public function apply(StructuralDocument $document, array $arguments): array;
}
