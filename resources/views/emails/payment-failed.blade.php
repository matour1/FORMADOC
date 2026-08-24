@component('mail::message')
# Ton paiement n'a pas abouti, {{ $user->name }}

Nous n'avons pas pu confirmer ton paiement de **{{ number_format($amount, 0, ',', ' ') }} {{ $currency }}**{{ $reference ? ' (référence '.$reference.')' : '' }}.

@if($subscription)
Ton abonnement **{{ $subscription->plan->name ?? 'en cours' }}** reste actif jusqu'à la fin de la période en cours. Tu peux réessayer dès maintenant.
@else
Tes crédits et documents ne sont pas affectés. Tu peux réessayer dès maintenant.
@endif

@component('mail::button', ['url' => route('account.index')])
Réessayer le paiement
@endcomponent

Si le problème persiste, contacte-nous via le formulaire de support — on t'aide à régler ça.

Cordialement,<br>
L'équipe **FORMADOC**
@endcomponent
