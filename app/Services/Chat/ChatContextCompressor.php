<?php

namespace App\Services\Chat;

use Illuminate\Support\Str;

/**
 * Compression du contexte de conversation pour limiter la consommation de
 * crédits sur les longues conversations.
 *
 * Le coût d'un appel IA dépend du nombre de tokens envoyés (le prompt).
 * Sur une conversation qui s'allonge, l'historique complet devient coûteux :
 *  - on borne le nombre de messages envoyés (les plus récents d'abord) ;
 *  - on borne le budget total de caractères de l'historique (les messages
 *    les plus anciens au-delà du budget sont élagués) ;
 *  - le contenu des pièces jointes injecté dans le contexte est également
 *    borné (Str::limit par pièce + budget global).
 *
 * Résumé : quand la conversation dépasse la taille maximale, les messages
 * les plus anciens sont résumés par le modèle lui-même (résumé du résumé
 * précédent + nouveaux messages) pour ne jamais perdre le fil, à coût
 * maîtrisé. Cette classe fournit l'outil de résumé (sans appeler l'IA) ;
 * l'appel de résumé est piloté par le ChatController.
 */
class ChatContextCompressor
{
    /**
     * Limite l'historique à un budget de caractères : les messages les plus
     * anciens au-delà du budget sont élagués, les plus récents sont conservés.
     *
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array<int, array{role: string, content: string}>
     */
    public function limitChars(array $history, int $maxChars): array
    {
        if ($history === []) {
            return [];
        }

        $kept = [];
        $total = 0;

        // Parcourt à l'envers (du plus récent au plus ancien) et conserve
        // tant que le budget n'est pas dépassé.
        for ($i = count($history) - 1; $i >= 0; $i--) {
            $chars = strlen((string) ($history[$i]['content'] ?? ''));

            if ($total + $chars > $maxChars && $kept !== []) {
                break;
            }

            // On conserve toujours au moins le dernier message (sinon le
            // modèle n'a aucun contexte utilisateur).
            if ($kept === [] || $total + $chars <= $maxChars) {
                $kept[] = $history[$i];
                $total += $chars;
            }
        }

        // Re-remet dans l'ordre chronologique
        return array_reverse($kept);
    }

    /**
     * Tronque le contenu des pièces jointes à un budget global.
     * Préserve le champ `path` (indispensable pour les outils
     * document_edit / document_to_pdf qui ciblent la PJ).
     *
     * @param  array<int, array{name: string, path: ?string, content: ?string}>  $attachments
     * @return array<int, array{name: string, path: ?string, content: ?string}>
     */
    public function limitAttachments(array $attachments, int $maxChars): array
    {
        $total = 0;
        $result = [];

        foreach ($attachments as $att) {
            $content = (string) ($att['content'] ?? '');
            $chars = strlen($content);
            $path = $att['path'] ?? null;

            // Budget épuisé : on ne conserve que la mention (nom du fichier)
            if ($total >= $maxChars) {
                $result[] = ['name' => $att['name'], 'path' => $path, 'content' => null];

                continue;
            }

            $remaining = $maxChars - $total;
            $result[] = [
                'name' => $att['name'],
                'path' => $path,
                'content' => $chars <= $remaining ? $content : Str::limit($content, $remaining),
            ];
            $total += min($chars, $remaining);
        }

        return $result;
    }
}
