@component('mail::message')
# Bienvenue sur FORMADOC, {{ $user->name }} !

Ton compte est prêt. Dès maintenant, tu disposes de **5 documents déterministes gratuits par mois** : téléverse un fichier, choisis un gabarit, et FORMADOC s'occupe de la mise en forme — sans IA, sans crédit, sans carte bancaire.

@component('mail::button', ['url' => route('documents.create')])
Mettre en forme mon premier document
@endcomponent

Tu veux en savoir plus avant de commencer ?

- [Comment ça marche]({{ url('/') }}#parcours)
- [Les tarifs]({{ url('/') }}#tarifs)
- [La FAQ]({{ url('/') }}#faq)

Cordialement,<br>
L'équipe **FORMADOC**
@endcomponent
