<?php

declare(strict_types=1);

namespace App\Document\Editing;

/**
 * Prompt système du chat d'édition structurelle (phase R6.13).
 *
 * **Pourquoi un prompt dédié** — le modèle doit comprendre trois choses qu'aucun
 * schéma de tool ne peut transmettre :
 *
 *  1. **La différence entre un document analysé et une pièce jointe.** Les tools
 *     d'édition exigent un `document_id` ; `document_edit` travaille sur un
 *     fichier attaché. Confondre les deux fait échouer chaque appel, et c'est la
 *     confusion la plus probable puisqu'un utilisateur dit simplement « modifie
 *     mon rapport ».
 *
 *  2. **Qu'il ne réécrit pas les données.** Un modèle à qui l'on demande
 *     « corrige ce tableau » propose spontanément de reformuler les montants.
 *     C'est exactement ce que le système interdit — la consigne doit donc être
 *     explicite dans le prompt, pas seulement dans les garde-fous.
 *
 *  3. **Qu'il ne boucle pas sur une confirmation.** Quand une action dépasse le
 *     seuil, l'outil demande l'accord de l'utilisateur. Un modèle qui réessaie
 *     sans cet accord consomme des tokens sans effet.
 *
 * Le prompt est **généré** à partir de `ToolWhitelist` : les seuils et la liste
 * des outils y sont injectés, donc aucune divergence n'est possible entre ce que
 * le prompt annonce et ce que le code applique.
 */
final class ChatEditAgent
{
    /**
     * Prompt système complet, avec les outils réellement disponibles.
     *
     * @param  array<int, array<string, mixed>>  $catalogue  Sortie de `ToolWhitelist::catalogue()`
     */
    public static function systemPrompt(array $catalogue = []): string
    {
        $catalogue ??= ToolWhitelist::catalogue();
        $seuil = ToolWhitelist::confirmThreshold('regenerate_section') ?? 5;

        $outils = self::toolList($catalogue);

        return <<<PROMPT
        Tu édites un document **déjà analysé** : sa structure est connue bloc par bloc, ce qui te permet
        de cibler précisément ce que l’utilisateur veut changer.

        ## Différence essentielle : document analysé vs pièce jointe

        - Les outils d’édition (`rewrite_paragraph`, `insert_block`, `modify_table`, `delete_block`,
          `regenerate_section`, `undo_last_action`) agissent sur un **document analysé**, désigné par son
          numéro dans `document_id`. Sa structure t’est fournie dans le contexte de la conversation :
          chaque bloc y porte un identifiant (`b_0042`) que tu dois réutiliser tel quel.
        - `document_edit` agit sur une **pièce jointe** de la conversation, désignée par son
          `source_path`. Ne confonds pas les deux : si l’utilisateur parle d’un document dont tu vois la
          structure, utilise un outil d’édition.

        Si aucun `document_id` ne t’est fourni, demande à l’utilisateur de quel document il parle —
        n’invente pas d’identifiant.

        ## Ce que tu ne dois JAMAIS faire

        - **Ne réécris jamais les données d’un tableau** (montants, dates, références, quantités) de ta
          propre initiative. Le contenu d’une cellule ne se reformule pas : une erreur y serait invisible
          à la relecture et se retrouverait dans un document livré. Tu peux modifier une valeur
          uniquement si l’utilisateur te l’a donnée explicitement, via `modify_table` avec l’opération
          `set_cell`.
        - **Ne supprime pas un bloc dont tu n’es pas sûr.** Un `delete_block` est annulable, mais
          demande-toi d’abord si l’utilisateur voulait vraiment supprimer ce passage.
        - **N’invente pas de numérotation.** Les numéros de figures, tableaux et renvois sont recalculés
          automatiquement après chaque édition. Ne les écris pas toi-même.

        ## Confirmations

        Les actions destructrices sur plus de {$seuil} blocs exigent l’accord explicite de l’utilisateur.
        Si un outil te répond qu’une confirmation est nécessaire :

        1. **expose clairement** à l’utilisateur ce qui va être modifié et son ampleur ;
        2. **attends sa réponse** — ne relance pas l’appel de ton propre chef ;
        3. s’il accepte, relance **le même appel** en ajoutant `confirmed: true`.

        ## Annulation

        Chaque action destructrice enregistre automatiquement l’état antérieur. Si l’utilisateur dit que
        le résultat ne lui convient pas, ne tente pas de « réparer » en réécrivant : propose d’annuler
        avec `undo_last_action`.

        ## Style de réponse

        Annonce **ce que tu as fait**, en une phrase, en citant l’identifiant des blocs touchés. Ne
        recopie pas le contenu du document : l’utilisateur l’a sous les yeux. Si une action a été
        refusée, explique pourquoi et propose une alternative concrète.

        ## Outils à ta disposition

        {$outils}
        PROMPT;
    }

    /**
     * Liste lisible des outils, construite depuis la liste blanche.
     *
     * @param  array<int, array<string, mixed>>  $catalogue
     */
    private static function toolList(array $catalogue): string
    {
        $lignes = [];

        foreach ($catalogue as $outil) {
            $nom = (string) ($outil['name'] ?? '');
            $description = (string) ($outil['description'] ?? '');

            if ($nom === '' || $description === '') {
                continue;
            }

            $marque = ($outil['destructive'] ?? false) ? ' *(destructif, annulable)*' : '';
            $lignes[] = "- `{$nom}` — {$description}{$marque}";
        }

        return implode("\n", $lignes);
    }

    /**
     * Rappel du prompt destiné au contexte d'un document précis.
     *
     * Injecter la structure complète du document serait coûteux en tokens ; on
     * ne transmet donc que l'essentiel : l'identifiant du document et le nombre
     * de blocs. Le modèle réclame le détail s'il en a besoin.
     *
     * @param  array<string, mixed>  $contexte  Données du document (id, blocks, headings…)
     */
    public static function documentContext(array $contexte): string
    {
        $documentId = $contexte['document_id'] ?? null;

        if ($documentId === null) {
            return '';
        }

        $parties = ['Document en cours d’édition : document_id = '.$documentId.'.'];

        if (isset($contexte['block_count'])) {
            $parties[] = 'Il contient '.(int) $contexte['block_count'].' blocs.';
        }

        if (! empty($contexte['block_ids'])) {
            // La liste des identifiants permet au modèle de viser un bloc sans
            // devoir demander la structure complète — c'est le gain de tokens le
            // plus net sur une conversation longue.
            $ids = array_slice((array) $contexte['block_ids'], 0, 60);
            $parties[] = 'Identifiants de blocs (dans l’ordre) : '.implode(', ', $ids)
                .(count((array) $contexte['block_ids']) > 60 ? ' …' : '').'.';
        }

        return implode(' ', $parties);
    }
}
