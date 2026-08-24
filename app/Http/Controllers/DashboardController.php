<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Tableau de bord et pages de bibliothèque (fidélité template).
 *
 * - dashboard : activité des documents, crédits, quotas et documents récents
 * - documents.index : bibliothèque « Mes documents » (filtres + recherche)
 */
class DashboardController extends Controller
{
    /**
     * Tableau de bord utilisateur.
     */
    public function index(Request $request): View
    {
        $user = $request->user();

        // Documents de l'utilisateur (stockés dans metadata->user_id)
        $documents = $user->documents()
            ->orderByDesc('created_at')
            ->limit(8)
            ->get();

        $documentsCount = $user->documents()->count();
        $chatCount = $user->chatSessions()->count();

        // Quotas du plan courant
        $quotaStatus = app(\App\Services\Billing\QuotaService::class)->status($user);

        return view('dashboard', [
            'user' => $user,
            'documents' => $documents,
            'documentsCount' => $documentsCount,
            'chatCount' => $chatCount,
            'quotaStatus' => $quotaStatus,
            'planSlug' => $user->currentPlanSlug(),
        ]);
    }

    /**
     * Bibliothèque « Mes documents ».
     */
    public function documents(Request $request): View
    {
        $user = $request->user();

        $query = $user->documents()
            ->orderByDesc('created_at');

        // Filtre par statut (all / processing / done / failed)
        $filter = $request->query('filter', 'all');
        if ($filter === 'processing') {
            $query->whereIn('status', ['pending', 'detected', 'validated', 'generated']);
        } elseif ($filter === 'done') {
            $query->where('status', 'ready');
        } elseif ($filter === 'failed') {
            $query->where('status', 'failed');
        }

        // Recherche par nom
        if ($search = trim((string) $request->query('q'))) {
            $query->where('filename', 'like', '%'.$search.'%');
        }

        // Compteurs par statut pour les filtres (P2 audit UI/UX)
        $statusCounts = [
            'all' => $user->documents()->count(),
            'processing' => $user->documents()->whereIn('status', ['pending', 'detected', 'validated', 'generated'])->count(),
            'done' => $user->documents()->where('status', 'ready')->count(),
            'failed' => $user->documents()->where('status', 'failed')->count(),
        ];

        return view('documents.index', [
            'documents' => $query->paginate(12)->withQueryString(),
            'filter' => $filter,
            'search' => trim((string) $request->query('q')),
            'statusCounts' => $statusCounts,
        ]);
    }
}
