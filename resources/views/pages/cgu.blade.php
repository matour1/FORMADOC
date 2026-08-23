@extends('layouts.app')

@section('title', 'Conditions générales d\'utilisation')

@section('content')
<div class="max-w-3xl" style="margin:0 auto;padding:2.5rem 0">
    <div class="page-header">
        <div>
            <span class="eyebrow">Légal</span>
            <h1>Conditions générales d'utilisation</h1>
            <p>Les règles d'utilisation du service FORMADOC. En vigueur au [date].</p>
        </div>
    </div>

    <div class="card" style="padding:1.8rem 2rem;line-height:1.75">
        <h2 class="card-title">1. Objet</h2>
        <p>
            Les présentes conditions générales régissent l'accès et l'utilisation du service
            FORMADOC, qui permet de mettre en forme automatiquement des documents
            (rapports, mémoires, CV, documents professionnels) à partir de fichiers
            téléversés, avec ou sans assistance IA.
        </p>

        <h2 class="card-title" style="margin-top:1.5rem">2. Compte utilisateur</h2>
        <p>
            La création d'un compte est requise pour utiliser le service. L'utilisateur
            s'engage à fournir des informations exactes et à conserver la confidentialité
            de ses identifiants. Toute utilisation frauduleuse du compte peut entraîner
            sa suspension.
        </p>

        <h2 class="card-title" style="margin-top:1.5rem">3. Description du service</h2>
        <ul>
            <li><strong>Traitement déterministe</strong> : analyse de la structure du document et application d'un gabarit, sans envoi de données à un service externe.</li>
            <li><strong>Assistance IA (optionnelle)</strong> : amélioration de l'analyse ou mise en forme avancée, avec estimation du coût affichée avant exécution. Le coût réel est débité après usage.</li>
            <li><strong>Crédits</strong> : unité de paiement des traitements IA (1 crédit = 1 FCFA).</li>
            <li><strong>Abonnements</strong> : plans mensuels avec quotas (documents déterministes et traitements IA), renouvelables automatiquement, résiliables à tout moment.</li>
        </ul>

        <h2 class="card-title" style="margin-top:1.5rem">4. Conditions financières</h2>
        <ul>
            <li>Achat de crédits : minimum 500 FCFA, par carte bancaire, KPay, Orange Money ou MTN MoMo.</li>
            <li>Abonnements : paiement mensuel au début de chaque période ; renouvellement automatique sauf annulation avant la date de renouvellement.</li>
            <li>Annulation : le service reste actif jusqu'à la fin de la période déjà payée, puis bascule sur le plan Gratuit.</li>
            <li>Changement de plan : le prorata des jours restants de l'ancien plan est crédité en crédits.</li>
            <li>Remboursement : en cas de double débit avéré, le montant est recrédité sous 7 jours ouvrés. Aucun remboursement des crédits consommés.</li>
        </ul>

        <h2 class="card-title" style="margin-top:1.5rem">5. Quotas et plan Gratuit</h2>
        <p>
            Le plan Gratuit inclut 5 documents déterministes par mois et 0 traitement IA.
            Les quotas sont réinitialisés le 1er de chaque mois. Les documents téléversés
            sont supprimés automatiquement après 30 jours.
        </p>

        <h2 class="card-title" style="margin-top:1.5rem">6. Obligations de l'utilisateur</h2>
        <p>
            L'utilisateur s'engage à n'utiliser FORMADOC que pour des documents dont il
            détient les droits, à ne pas téléverser de contenus illicites, frauduleux ou
            contraires à l'ordre public, et à ne pas tenter de compromettre la sécurité
            du service.
        </p>

        <h2 class="card-title" style="margin-top:1.5rem">7. Données personnelles</h2>
        <p>
            Le traitement des données est décrit dans la
            <a href="{{ route('pages.privacy') }}">Politique de confidentialité</a>.
            L'activation de l'assistance IA implique l'envoi du contenu du document à un
            prestataire d'IA ; ce choix est toujours explicite et désactivé par défaut.
        </p>

        <h2 class="card-title" style="margin-top:1.5rem">8. Responsabilité</h2>
        <p>
            FORMADOC fournit un service de mise en forme. L'utilisateur reste seul
            responsable du contenu et de l'usage de ses documents. FORMADOC ne garantit
            pas un résultat conforme à un référentiel académique donné, ni l'absence
            d'erreur d'analyse automatique.
        </p>

        <h2 class="card-title" style="margin-top:1.5rem">9. Droit applicable — litiges</h2>
        <p>
            Les présentes CGU sont soumises au droit camerounais. En cas de litige, une
            solution amiable sera recherchée avant toute action judiciaire devant les
            tribunaux compétents de Douala (Cameroun).
        </p>
    </div>
</div>
@endsection
