<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Document\Clarification\ClarificationService;
use App\Document\Structure\StructuralDocument;
use App\Models\Document;
use App\Models\DocumentClarification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

/**
 * Interface de clarification : confirmer les blocs dont la classification est incertaine.
 *
 * **Pourquoi cet écran est indispensable.** R2 classe automatiquement ce qui est
 * sûr et laisse de côté ce qui ne l'est pas — c'est ce qui rend le pipeline
 * économe (68 % des blocs classés sans appel IA). Mais sans moyen de répondre,
 * ces blocs restent indéfiniment ambigus : l'utilisateur subit les erreurs de
 * détection sans pouvoir les corriger, et la promesse « vous gardez le contrôle »
 * n'est pas tenue.
 *
 * **Une question porte sur UN bloc, jamais sur le document entier** (§7, §8 de la
 * spécification). C'est délibéré : un formulaire global demanderait à l'utilisateur
 * de trancher des dizaines de cas d'un coup, alors qu'il reconnaît immédiatement
 * les quelques passages qui comptent.
 */
class ClarificationController extends Controller
{
    public function __construct(
        private readonly ClarificationService $clarifications,
    ) {}

    /**
     * Liste les blocs en attente de confirmation pour un document.
     */
    public function index(Document $document): View
    {
        $this->authorizeDocument($document);

        $enAttente = $this->clarifications->pendingFor($document->id);

        // On sépare les questions déjà traitées : l'utilisateur doit pouvoir
        // vérifier ce qu'il a répondu, et revenir sur une décision s'il s'est
        // trompé. Les masquer donnerait l'impression d'un choix irréversible.
        $repondues = $document->clarifications()
            ->whereNotNull('answered_at')
            ->orderByDesc('answered_at')
            ->get();

        return view('documents.clarifications', [
            'document' => $document,
            'pending' => $enAttente,
            'answered' => $repondues,
            'structureAvailable' => $document->structure?->structuralDocument() !== null,
        ]);
    }

    /**
     * Enregistre les réponses et les applique à la structure persistée.
     *
     * Les réponses attendues ont la forme `answers[block_id] = "Titre niveau 2"`.
     * Le libellé est celui affiché à l'utilisateur : la traduction vers le type du
     * schéma est faite par le modèle (`DocumentClarification::typeFromAnswer`), ce
     * qui permet à plusieurs écrans de l'interpréter de façon identique.
     */
    public function store(Request $request, Document $document): RedirectResponse
    {
        $this->authorizeDocument($document);

        $reponses = (array) $request->input('answers', []);

        if ($reponses === []) {
            return back()->with('warning', 'Aucune réponse à enregistrer.');
        }

        $structure = $document->structure;

        if ($structure === null || $structure->structuralDocument() === null) {
            return back()->withErrors([
                'clarifications' => 'Ce document n\'a pas de structure récente : relancez son analyse pour pouvoir confirmer les passages.',
            ]);
        }

        try {
            // --- 1. Enregistrer les réponses ---------------------------------
            // Une réponse ne corrige QUE le bloc visé (§8) : appliquer une
            // décision utilisateur à des blocs similaires serait lui faire dire
            // ce qu'il n'a pas dit.
            $enregistrees = 0;

            foreach ($reponses as $blockId => $reponse) {
                if (! is_string($reponse) || trim($reponse) === '') {
                    continue;
                }

                /** @var DocumentClarification|null $clarification */
                $clarification = $document->clarifications()
                    ->where('block_id', (string) $blockId)
                    ->first();

                if ($clarification === null) {
                    continue;
                }

                $clarification->answer(trim($reponse));
                $enregistrees++;
            }

            if ($enregistrees === 0) {
                return back()->with('warning', 'Aucune réponse valide à enregistrer.');
            }

            // --- 2. Appliquer à la structure ---------------------------------
            $structurel = $structure->structuralDocument();
            $resultat = $this->clarifications->applyAnswers($document->id, $structurel);

            // --- 3. Persister -------------------------------------------------
            $structure->update([
                'structural_json' => $resultat['document']->toArray(),
                'schema_version' => StructuralDocument::SCHEMA_VERSION,
                'pipeline' => $structure->pipeline ?? 'native',
            ]);

            Log::info('Clarifications appliquées', [
                'document_id' => $document->id,
                'reponses' => $enregistrees,
                'blocs_corriges' => $resultat['applied'],
                'restantes' => $resultat['pending'],
            ]);

            $message = $resultat['applied'].' passage(s) confirmé(s).';

            if ($resultat['pending'] > 0) {
                $message .= ' '.$resultat['pending'].' restent à préciser.';
            }

            return redirect()
                ->route('documents.clarifications.index', $document)
                ->with('success', $message);
        } catch (Throwable $e) {
            // Un échec d'application ne doit pas laisser croire que la réponse
            // a été prise en compte : on le dit explicitement.
            Log::error('Clarifications : application échouée', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);

            return back()->withErrors([
                'clarifications' => 'L\'enregistrement a échoué : '.$e->getMessage(),
            ]);
        }
    }

    /**
     * Vérifie l'appartenance du document à l'utilisateur connecté.
     *
     * Même règle que `DocumentController` : 404 et non 403, pour ne pas révéler
     * l'existence d'un document dont on n'est pas propriétaire.
     */
    private function authorizeDocument(Document $document): void
    {
        $userId = (int) ($document->metadata['user_id'] ?? 0);
        $currentUserId = (int) Auth::id();

        if ($userId === 0 || $userId !== $currentUserId) {
            abort(404, 'Document introuvable.');
        }
    }
}
