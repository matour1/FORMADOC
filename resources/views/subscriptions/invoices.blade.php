@extends('layouts.app')

@section('title', 'Mes factures')

@section('content')
    <div class="page-header">
        <div>
            <span class="eyebrow">Espace personnel</span>
            <h1>Mes factures</h1>
            <p>
                Factures émises pour vos abonnements, renouvellements et achats de crédits.
                Un PDF est disponible pour chaque paiement.
            </p>
        </div>
        <a href="{{ route('account.index') }}" class="btn btn-ghost btn-sm">
            <i data-lucide="arrow-left" style="width:14px;height:14px"></i> Retour au compte
        </a>
    </div>

    <div class="card">
        @if ($invoices->isEmpty())
            <p style="text-align:center;padding:2.5rem 0;color:var(--color-text-muted)">
                Aucune facture pour le moment. Vos factures apparaîtront ici après chaque paiement.
            </p>
        @else
            <div class="table-wrap" style="padding:0">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>N°</th>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Description</th>
                            <th style="text-align:right">Montant</th>
                            <th>Statut</th>
                            <th style="text-align:right">PDF</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($invoices as $invoice)
                            <tr>
                                <td class="mono" style="white-space:nowrap">{{ $invoice->number }}</td>
                                <td class="mono" style="white-space:nowrap">{{ $invoice->created_at->format('d/m/Y') }}</td>
                                <td>
                                    @if ($invoice->type === 'subscription')
                                        <span class="badge badge-info">Abonnement</span>
                                    @elseif ($invoice->type === 'renewal')
                                        <span class="badge badge-info">Renouvellement</span>
                                    @elseif ($invoice->type === 'credit_purchase')
                                        <span class="badge badge-success">Crédits</span>
                                    @else
                                        <span class="badge">{{ $invoice->type }}</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($invoice->plan)
                                        {{ $invoice->plan->name }}
                                    @else
                                        Achat de crédits
                                    @endif
                                </td>
                                <td style="text-align:right" class="mono">{{ $invoice->formattedAmount() }}</td>
                                <td>
                                    @if ($invoice->status === 'paid')
                                        <span class="badge badge-success"><i data-lucide="check" style="width:11px;height:11px"></i> Payée</span>
                                    @else
                                        <span class="badge badge-warning">{{ ucfirst($invoice->status) }}</span>
                                    @endif
                                </td>
                                <td style="text-align:right">
                                    <a href="{{ route('invoices.download', $invoice) }}" class="btn btn-ghost btn-sm" title="Télécharger le PDF">
                                        <i data-lucide="download" style="width:13px;height:13px"></i> PDF
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($invoices->hasPages())
                <div class="pagination" style="margin-top:1.25rem">
                    {{ $invoices->links() }}
                </div>
            @endif
        @endif
    </div>
@endsection
