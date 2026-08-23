@extends('layouts.app')

@section('title', 'Politique de confidentialité')

@section('content')
<div class="max-w-3xl" style="margin:0 auto;padding:2.5rem 0">
    <div class="page-header">
        <div>
            <span class="eyebrow">Légal</span>
            <h1>Politique de confidentialité</h1>
            <p>Comment FORMADOC collecte, utilise et protège vos données. En vigueur au [date].</p>
        </div>
    </div>

    <div class="card" style="padding:1.8rem 2rem;line-height:1.75">
        <h2 class="card-title">1. Responsable du traitement</h2>
        <p>
            Le responsable du traitement est [Raison sociale], [adresse], Douala, Cameroun.
            Contact : <a href="mailto:dpo@formadoc.cm">dpo@formadoc.cm</a>.
        </p>

        <h2 class="card-title" style="margin-top:1.5rem">2. Données collectées</h2>
        <ul>
            <li><strong>Compte :</strong> nom, adresse email, préférences (langue, thème, notifications).</li>
            <li><strong>Documents :</strong> fichiers téléversés et métadonnées associées (nom, taille, type, structure détectée).</li>
            <li><strong>Paiements :</strong> référence de transaction (via KPay ou autres passerelles) — FORMADOC ne conserve jamais les données de carte bancaire.</li>
            <li><strong>Usage :</strong> quotas consommés, conversations IA, factures.</li>
            <li><strong>Techniques :</strong> journaux de connexion, adresse IP, type de navigateur (sécurité et diagnostic).</li>
        </ul>

        <h2 class="card-title" style="margin-top:1.5rem">3. Finalités et bases légales</h2>
        <ul>
            <li>Fournir le service (contrat) : traitement des documents, gestion des quotas.</li>
            <li>Facturation (contrat / obligation légale) : factures, reçus, historique d'achat.</li>
            <li>Support et communication (intérêt légitime) : répondre aux demandes, informer sur le service.</li>
            <li>Sécurité (intérêt légitime) : prévention des abus, journalisation.</li>
            <li>Amélioration du produit (intérêt légitime) : statistiques agrégées anonymes.</li>
        </ul>

        <h2 class="card-title" style="margin-top:1.5rem">4. Durée de conservation</h2>
        <ul>
            <li>Documents téléversés : <strong>30 jours</strong> après le traitement, puis suppression automatique.</li>
            <li>Compte : tant que le compte est actif, plus 30 jours après suppression.</li>
            <li>Factures : 10 ans (obligation fiscale).</li>
            <li>Journaux techniques : 12 mois maximum.</li>
        </ul>

        <h2 class="card-title" style="margin-top:1.5rem">5. Partage des données</h2>
        <p>
            FORMADOC ne vend aucune donnée. Les données peuvent être transmises à des
            sous-traitants strictement nécessaires :
        </p>
        <ul>
            <li><strong>Hébergeur</strong> [nom] pour le stockage et l'exécution du service ;</li>
            <li><strong>Prestataires de paiement</strong> (KPay, Orange Money, MTN MoMo) pour les transactions ;</li>
            <li><strong>Prestataire d'IA</strong> (OpenRouter et modèles associés) — <em>uniquement si vous activez l'assistance IA</em> ; à défaut, aucun contenu n'est transmis.</li>
        </ul>

        <h2 class="card-title" style="margin-top:1.5rem">6. Transferts hors du Cameroun</h2>
        <p>
            L'activation de l'assistance IA peut impliquer un transfert du contenu du
            document vers des serveurs situés hors du Cameroun. Ce transfert n'a lieu
            qu'avec votre consentement exprès (case « Utiliser l'assistance IA »),
            jamais par défaut.
        </p>

        <h2 class="card-title" style="margin-top:1.5rem">7. Vos droits</h2>
        <p>
            Conformément à la loi camerounaise n° 2010/012 du 21 décembre 2010 relative
            à la protection des données à caractère personnel, vous disposez des droits
            d'accès, de rectification, de suppression et d'opposition. Pour les exercer,
            écrivez à <a href="mailto:dpo@formadoc.cm">dpo@formadoc.cm</a> ou depuis les
            Paramètres de votre compte. Une réponse vous sera adressée sous 30 jours.
        </p>

        <h2 class="card-title" style="margin-top:1.5rem">8. Sécurité</h2>
        <p>
            Les données sont chiffrées en transit (HTTPS) et les accès internes sont
            restreints. Les mots de passe sont hachés et jamais stockés en clair.
        </p>

        <h2 class="card-title" style="margin-top:1.5rem">9. Cookies</h2>
        <p>
            FORMADOC utilise uniquement des cookies techniques et de préférences
            (session, langue, thème). Aucun cookie publicitaire ni traceur tiers n'est déposé.
        </p>

        <h2 class="card-title" style="margin-top:1.5rem">10. Modifications</h2>
        <p>
            Cette politique peut être mise à jour. Les utilisateurs en seront informés par
            email en cas de changement substantiel.
        </p>
    </div>
</div>
@endsection
