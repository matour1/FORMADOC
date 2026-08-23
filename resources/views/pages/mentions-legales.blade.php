@extends('layouts.app')

@section('title', 'Mentions légales')

@section('content')
<div class="max-w-3xl" style="margin:0 auto;padding:2.5rem 0">
    <div class="page-header">
        <div>
            <span class="eyebrow">Légal</span>
            <h1>Mentions légales</h1>
            <p>Informations relatives à l'éditeur et à l'hébergement de FORMADOC.</p>
        </div>
    </div>

    <div class="card" style="padding:1.8rem 2rem;line-height:1.75">
        <h2 class="card-title">1. Éditeur du service</h2>
        <p>
            Le site et le service FORMADOC sont édités par :<br>
            <strong>[Raison sociale ou nom de l'éditeur]</strong><br>
            [Forme juridique, capital social] — [RCCM n° …, NIU …]<br>
            [Adresse complète], Douala, Cameroun<br>
            Email : <a href="mailto:support@formadoc.cm">support@formadoc.cm</a>
        </p>
        <p><strong>Directeur de la publication :</strong> [Nom, Prénom].</p>

        <h2 class="card-title" style="margin-top:1.5rem">2. Hébergement</h2>
        <p>
            Le service est hébergé par :<br>
            <strong>[Nom de l'hébergeur]</strong> — [adresse, contact technique]<br>
            Les données sont hébergées en [zone géographique].
        </p>

        <h2 class="card-title" style="margin-top:1.5rem">3. Propriété intellectuelle</h2>
        <p>
            L'ensemble des contenus de FORMADOC (textes, graphismes, logos, icônes, gabarits,
            code source) est protégé par le droit de la propriété intellectuelle. Toute
            reproduction, sans autorisation écrite préalable, est interdite. Les documents
            téléversés par les utilisateurs restent la propriété exclusive de leurs auteurs.
        </p>

        <h2 class="card-title" style="margin-top:1.5rem">4. Responsabilité</h2>
        <p>
            FORMADOC s'efforce d'assurer l'exactitude des informations publiées et la
            disponibilité du service, sans garantie d'absence totale d'interruption.
            L'éditeur ne saurait être tenu responsable d'un usage inapproprié des documents
            produits, ni de pertes de contenu consécutives à la suppression automatique
            des fichiers (voir CGU, rétention de 30 jours).
        </p>

        <h2 class="card-title" style="margin-top:1.5rem">5. Droit applicable</h2>
        <p>
            Les présentes mentions sont soumises au droit camerounais. Tout litige relève
            de la compétence des tribunaux de Douala (Cameroun), sauf disposition légale
            impérative contraire.
        </p>
    </div>
</div>
@endsection
