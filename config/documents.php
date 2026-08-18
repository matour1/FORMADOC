<?php

declare(strict_types=1);

/**
 * Configuration du traitement des documents.
 *
 * libreoffice_path : chemin de l'exécutable soffice (aperçu PDF). Laisser
 * vide pour une détection automatique (PATH puis chemins courants).
 */
return [
    'libreoffice_path' => env('LIBREOFFICE_PATH', ''),
];
