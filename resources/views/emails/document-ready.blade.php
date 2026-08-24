@component('mail::message')
# Ton document est prêt, {{ $user->name }} !

**{{ $document->filename }}** a été mis en forme{{ $templateName ? ' avec le gabarit **'.$templateName.'**' : '' }}. Tu peux le prévisualiser et le télécharger dès maintenant.

@component('mail::button', ['url' => route('documents.export', $document)])
Télécharger mon document
@endcomponent

Tes fichiers sont conservés **30 jours** puis supprimés automatiquement.

Cordialement,<br>
L'équipe **FORMADOC**
@endcomponent
