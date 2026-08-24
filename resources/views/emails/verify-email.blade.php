@component('mail::message')
# Confirme ton adresse email, {{ $user->name }} !

Tu viens de créer ton compte FORMADOC. Confirme ton adresse pour sécuriser ton compte et ne rien rater (reçus de paiement, documents prêts, rappels).

@component('mail::button', ['url' => $verificationUrl])
Confirmer mon adresse email
@endcomponent

Pas besoin de confirmer tout de suite : tu peux déjà utiliser FORMADOC. Le lien expire dans **24 heures**.

Cordialement,<br>
L'équipe **FORMADOC**
@endcomponent
