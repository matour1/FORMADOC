<?php

declare(strict_types=1);

namespace App\Services\OpenRouter;

use RuntimeException;

/**
 * Le modèle a répondu en texte alors que `tool_choice: required` exigeait un
 * appel d'outil.
 *
 * Constaté avec `deepseek/deepseek-chat` via OpenRouter : la réponse est un
 * succès HTTP (donc facturée) mais ne contient AUCUN `tool_calls`, ce qui
 * laissait la demande utilisateur sans effet (aucun fichier produit, aucun
 * lien de téléchargement) tout en débitant des crédits.
 *
 * Cette exception sert à basculer sur le candidat suivant (`openai/gpt-4o-mini`,
 * qui honore la contrainte) sans enregistrer un échec supplémentaire dans le
 * registre d'usage : le tour a déjà été comptabilisé en succès.
 */
class ToolChoiceIgnoredException extends RuntimeException {}
