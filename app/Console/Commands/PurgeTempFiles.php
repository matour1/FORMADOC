<?php

namespace App\Console\Commands;

use App\Models\ChatSession;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

#[Signature('files:purge-temp {--ttl-hours=24 : Âge minimal en heures des fichiers temporaires à purger}')]
#[Description('Purge les fichiers temporaires (RGPD P1-6 + P2-4) : previews cover preview-*.docx, DOCX générés orphelins gen_*.docx (storage/test_scripts) et pièces jointes de sessions chat supprimées')]
class PurgeTempFiles extends Command
{
    /**
     * Exécute la purge des fichiers temporaires.
     *
     * RGPD P1-6 + P2-4 — trois familles de fichiers éphémères :
     *   1. Previews de couverture : `storage/app/preview-*.docx` générés à la
     *      volée (CoverPageTemplateController::preview) et normalement supprimés
     *      au téléchargement (deleteFileAfterSend). Si l'URL signée (15 min)
     *      expire sans téléchargement, le fichier reste sur le disque.
     *   2. DOCX reconstruits orphelins : `storage/test_scripts/gen_*.docx`
     *      (P2-4) générés à la volée par DocumentController::generateOutputPath.
     *      Normalement supprimés en cascade à la suppression du compte
     *      (AccountController::destroy) ; cette commande couvre les orphelins
     *      (document supprimé, export jamais téléchargé, purge interrompue...).
     *   3. Pièces jointes de chat : `storage/app/private/chat/attachments/{sessionId}/**`
     *      (disk 'local'). Normalement purgées en cascade à la suppression de
     *      session/compte (P1-6) ; cette commande couvre les orphelins
     *      (session déjà supprimée, purge interrompue, etc.).
     *
     * Idempotente — aucune suppression pour les fichiers récents (TTL par défaut 24 h).
     * Scheduler : routes/console.php — chaque jour à 02:30.
     * Les scripts utilitaires (storage/test_scripts/*.php, reports/) sont CONSERVÉS
     * (utilisés par les tests unitaires DocAnalyzer) : seuls gen_*.docx sont purgés.
     */
    public function handle(): int
    {
        $ttlHours = max(1, (int) $this->option('ttl-hours'));
        $threshold = now()->subHours($ttlHours)->getTimestamp();
        $deleted = 0;

        // --- 1. Previews de couverture expirées (storage/app/preview-*.docx) ---
        foreach (File::glob(storage_path('app/preview-*.docx')) ?: [] as $file) {
            if (! is_file($file)) {
                continue;
            }
            if (File::lastModified($file) < $threshold) {
                File::delete($file);
                $deleted++;
            }
        }

        // --- 2. DOCX reconstruits orphelins (storage/test_scripts/gen_*.docx, P2-4) ---
        foreach (File::glob(storage_path('test_scripts/gen_*.docx')) ?: [] as $file) {
            if (! is_file($file)) {
                continue;
            }
            if (File::lastModified($file) < $threshold) {
                File::delete($file);
                $deleted++;
            }
        }

        // --- 3. Pièces jointes de sessions chat supprimées (disk 'local') ---
        $chatStorage = Storage::disk('local');
        $sessionDirs = $chatStorage->directories('chat/attachments');

        foreach ($sessionDirs as $dir) {
            $sessionId = (int) basename((string) $dir);
            if ($sessionId <= 0) {
                continue;
            }
            if (! ChatSession::whereKey($sessionId)->exists()) {
                // Session supprimée : la cascade n'a pas (ou pas entièrement)
                // purgé le dossier — on supprime tout ce qui reste.
                $count = count($chatStorage->allFiles($dir));
                $chatStorage->deleteDirectory($dir);
                $deleted += $count;
            }
        }

        $this->info(sprintf('Purge TTL (%d h) : %d fichier(s) supprimé(s).', $ttlHours, $deleted));

        return self::SUCCESS;
    }
}
