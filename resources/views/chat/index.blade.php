@extends('layouts.app')

@section('title', 'Assistant IA')

@section('content')
    <div class="page-header">
        <div>
            <span class="eyebrow">Assistant IA</span>
            <h1>Discuter avec l'assistant</h1>
            <p>
                Posez vos questions sur la mise en forme de rapports, la rédaction, la structure de vos documents.
                L'assistant peut <strong>exécuter des actions</strong> : pages de garde, reconstruction, recherche web, images.
            </p>
        </div>
        <div class="credits-badge {{ auth()->user()->credits_balance < 100 ? 'low' : '' }}" title="Solde de crédits — 1 crédit = 1 FCFA">
            <i data-lucide="coins" style="width:14px;height:14px"></i>
            {{ number_format(auth()->user()->credits_balance, 0, ',', ' ') }} crédits
        </div>
    </div>

    {{-- Nouvelle conversation --}}
    <div class="card" style="margin-bottom:1.5rem">
        <form action="{{ route('chat.send') }}" method="POST">
            @csrf
            <div class="form-group" style="margin-bottom:0">
                <label for="first-message">Premier message</label>
                <textarea name="message" id="first-message" rows="2" required maxlength="12000"
                          placeholder="Ex. : Comment structurer un rapport de stage de 30 pages ?"
                          class="form-control"></textarea>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;margin-top:.6rem">
                <p class="chat-cost-preview" style="margin:0">
                    <i data-lucide="coins"></i>
                    Coût estimé : <strong>{{ $estimatedCredits ?? 0 }} crédit(s)</strong> — confirmé avant envoi
                </p>
                <button type="submit" class="btn btn-primary">
                    <i data-lucide="send" style="width:15px;height:15px"></i> Envoyer
                </button>
            </div>
            <p class="chat-disclaimer" style="margin-top:.5rem">
                Une nouvelle conversation sera créée. Chaque message est facturé à son coût réel (ajustement automatique).
                @if ($claudeEligible ?? false)
                    Skills documentaires Claude activés (expérimental, Pro).
                @endif
            </p>
        </form>
    </div>

    {{-- Historique des sessions --}}
    <div class="card">
        <h2 class="card-title" style="margin-bottom:.5rem">Mes conversations</h2>

        @if ($sessions->isEmpty())
            <p style="text-align:center;padding:2rem 0;color:var(--color-text-muted)">
                <i data-lucide="message-circle" style="width:36px;height:36px;margin:0 auto .6rem;display:block;opacity:.6"></i>
                Aucune conversation pour le moment. Commencez à discuter ci-dessus !
            </p>
        @else
            <div class="table-wrap" style="padding:0">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Conversation</th>
                            <th style="width:120px">Messages</th>
                            <th style="width:130px">Coût</th>
                            <th style="width:170px">Dernière activité</th>
                            <th style="width:60px"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sessions as $session)
                            <tr>
                                <td>
                                    <a href="{{ route('chat.show', $session) }}" style="font-weight:600;color:var(--color-ink)">
                                        {{ $session->title ?: 'Sans titre' }}
                                    </a>
                                </td>
                                <td class="mono">{{ $session->messages_count }} message{{ $session->messages_count > 1 ? 's' : '' }}</td>
                                <td class="mono">{{ $session->total_cost_credits }} cr</td>
                                <td>{{ $session->updated_at->diffForHumans() }}</td>
                                <td>
                                    <a href="{{ route('chat.show', $session) }}" class="btn btn-ghost btn-sm">
                                        Ouvrir
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection

