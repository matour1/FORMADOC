<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

/**
 * Policy d'accès aux documents (P0-1 — correctif IDOR).
 *
 * Les documents sont associés à leur propriétaire via `metadata->user_id`
 * (JSON, pas de colonne dédiée). L'ownership est vérifié ici pour TOUTES
 * les routes documentaires : un utilisateur ne peut voir/consulter/éditer
 * que ses propres documents.
 */
class DocumentPolicy
{
    /**
     * L'utilisateur possède-t-il ce document ?
     */
    public function owns(User $user, Document $document): bool
    {
        return (int) ($document->metadata['user_id'] ?? 0) === (int) $user->id;
    }

    /**
     * Afficher la page de validation d'un document.
     */
    public function view(User $user, Document $document): bool
    {
        return $this->owns($user, $document);
    }

    /**
     * Aperçu du fichier source (preview).
     */
    public function preview(User $user, Document $document): bool
    {
        return $this->owns($user, $document);
    }

    /**
     * Page de traitement (étape 3).
     */
    public function process(User $user, Document $document): bool
    {
        return $this->owns($user, $document);
    }

    /**
     * Page d'export (étape 4).
     */
    public function export(User $user, Document $document): bool
    {
        return $this->owns($user, $document);
    }

    /**
     * Génération du DOCX reconstruit.
     */
    public function generate(User $user, Document $document): bool
    {
        return $this->owns($user, $document);
    }

    /**
     * Aperçu PDF (LibreOffice).
     */
    public function previewPdf(User $user, Document $document): bool
    {
        return $this->owns($user, $document);
    }

    /**
     * Sert le PDF d'aperçu (iframe).
     */
    public function previewPdfFile(User $user, Document $document): bool
    {
        return $this->owns($user, $document);
    }

    /**
     * Validation de la structure (étape 2 → 3).
     */
    public function validate(User $user, Document $document): bool
    {
        return $this->owns($user, $document);
    }
}
