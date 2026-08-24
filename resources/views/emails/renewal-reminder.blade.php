@component('mail::message')
# Ton abonnement se renouvelle le {{ $renewDate }}

Ton abonnement **{{ $planName }}** ({{ number_format($price, 0, ',', ' ') }} {{ $currency }}/mois) sera renouvelé automatiquement le **{{ $renewDate }}**. Si tu souhaites l'annuler, fais-le avant cette date depuis ton compte — il restera actif jusqu'à la fin de la période payée.

@component('mail::button', ['url' => route('account.index')])
Gérer mon abonnement
@endcomponent

Cordialement,<br>
L'équipe **FORMADOC**
@endcomponent
