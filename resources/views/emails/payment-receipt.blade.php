@component('mail::message')
# Merci {{ $user->name }} !

Votre paiement **{{ $label }}** a bien été confirmé sur **FORMADOC**.

## Récapitulatif

| | |
| --- | --- |
| **Facture** | {{ $invoice->number }} |
| **Date** | {{ $invoice->paid_at?->format('d/m/Y H:i') ?? $invoice->created_at->format('d/m/Y H:i') }} |
| **Montant** | {{ number_format($invoice->amount, 0, ',', ' ') }} {{ $invoice->currency }} |
| **Statut** | ✅ Payée |
| **Référence** | {{ $invoice->reference ?: '—' }} |

@if ($invoice->type === 'credit_purchase')
Vos **{{ number_format($invoice->amount, 0, ',', ' ') }} crédits** ont été ajoutés à votre solde.
@else
Votre abonnement est maintenant actif. Retrouvez les détails dans votre compte.
@endif

@component('mail::button', ['url' => route('account.index')])
Voir mon compte
@endcomponent

Cordialement,<br>
L'équipe **FORMADOC**
@endcomponent
