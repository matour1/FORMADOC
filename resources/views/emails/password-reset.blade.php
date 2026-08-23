@component('mail::message')
# Bonjour {{ $user->name }} !

Vous avez demandé la réinitialisation de votre mot de passe **FORMADOC**.

Cliquez sur le bouton ci-dessous pour choisir un nouveau mot de passe. Ce lien est valable **60 minutes**.

@component('mail::button', ['url' => $resetUrl])
Réinitialiser mon mot de passe
@endcomponent

Si vous n'êtes pas à l'origine de cette demande, vous pouvez ignorer cet email : votre mot de passe restera inchangé.

Cordialement,<br>
L'équipe **FORMADOC**
@endcomponent
