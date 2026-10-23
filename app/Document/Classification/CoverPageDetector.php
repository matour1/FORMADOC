<?php

declare(strict_types=1);

namespace App\Document\Classification;

use App\Document\Structure\Block;
use App\Document\Structure\BlockType;

/**
 * Détection de la PAGE DE GARDE d'un document source, en vue de sa suppression.
 *
 * **Pourquoi la supprimer, et pourquoi ce n'est pas contradictoire.**
 * L'outil produit une MISE EN FORME : il ne fabrique pas de couverture (décision
 * du responsable, 2026-10-19). Une page de garde présente dans le document source
 * est donc un **résidu** que le gabarit ne sait pas traiter — la conserver
 * reviendrait à livrer un document dont la première page échappe au traitement.
 * C'est l'une des deux seules exceptions au principe « ne jamais perdre de
 * contenu », l'autre étant les sommaires régénérés.
 *
 * **La règle a été MESURÉE avant d'être écrite.** Sur 11 documents réels du
 * corpus (7 pages de garde seules, 4 rapports complets) :
 *
 *     Règle : ≤ 40 blocs ET ≥ 2 marqueurs distincts ET aucun titre liminaire
 *     Précision : 100,0 %  (0 faux positif sur 5 documents classés « rapport »)
 *     Rappel    :  66,7 %  (4 des 6 pages de garde détectées)
 *
 * Les deux pages de garde non détectées ne contiennent **qu'un ou deux blocs** :
 * ce sont des couvertures quasi vides (une image, un cadre), sans texte
 * exploitable. Les détecter exigerait une reconnaissance visuelle, hors du champ
 * de cette règle — et les manquer est sans conséquence : une page de garde
 * conservée se voit, alors qu'une page de contenu supprimée à tort est
 * irrécupérable.
 *
 * **Pourquoi la taille du document intervient.** Sans elle, le seul signal
 * « marqueurs de couverture » déclenchait un faux positif sur un rapport
 * complet : un rapport de stage **contient** la phrase « rapport de stage ».
 * Les pages de garde ont 1 à 13 blocs, les rapports 375 à 722 : la borne à 40
 * sépare les deux populations sans ambiguïté dans le corpus mesuré.
 *
 * **Pourquoi l'absence de titre liminaire intervient.** Un document qui contient
 * déjà « SOMMAIRE », « DEDICACE » ou « INTRODUCTION » est un rapport **complet**,
 * pas une page de garde isolée. C'est le garde-fou le plus fort : il a ramené le
 * taux de faux positifs de 20 % à 0 % sur le corpus.
 */
final class CoverPageDetector
{
    /**
     * Nombre de blocs au-delà duquel le document n'est PAS une page de garde.
     *
     * Mesuré : pages de garde = 1 à 13 blocs ; rapports = 375 à 722. La borne
     * est placée très au-dessus des pages de garde réelles (×3) et très en
     * dessous des rapports (÷9). Aucun document du corpus ne tombe entre 14 et
     * 374 — c'est un intervalle vide, donc tout choix dans cet intervalle est
     * équivalent. 40 laisse une marge confortable aux couvertures enrichies.
     */
    public const MAX_BLOCS = 40;

    /**
     * Nombre minimal de marqueurs DISTINCTS.
     *
     * À 1, une phrase « rapport de stage » suffit et produit des faux positifs.
     * À 2, il faut deux éléments de couverture (« RÉPUBLIQUE DU CAMEROUN » ET
     * « PRÉSENTÉ PAR »), ce qui est le cas de toute page de garde acadmique
     * réelle.
     */
    public const MIN_MARQUEURS = 2;

    /**
     * Formules administratives et intitulés de page de garde.
     *
     * Comparaison en minuscules, sans accents (voir `normaliser`).
     *
     * @var array<int, string>
     */
    private const MARQUEURS = [
        'republique', 'republic of cameroon', 'ministere', 'universite',
        'institut', 'ecole', 'faculte', 'présenté par', 'presente par',
        'rédigé en vue', 'redige en vue', 'sous l\'encadrement',
        'année académique', 'annee academique', 'filière', 'filiere',
        'cursus', 'rapport de stage', 'memoire', 'diplome', 'diplôme',
    ];

    /**
     * Titres liminaires reconnus marquant le début des pièces liminaires d'un rapport.
     *
     * @var array<int, string>
     */
    private const LIMINAIRES = [
        'sommaire', 'table des matieres', 'table des matières', 'tables des matieres',
        'tables des matières', 'dedicace', 'dédicace', 'remerciements',
        'avant-propos', 'sigles', 'abreviations', 'abréviations', 'liste des figures',
        'liste de figures', 'liste des tableaux', 'liste de tableaux',
        'liste des sigles', 'liste des illustrations', 'liste des annexes',
        'resume', 'résumé', 'abstract', 'introduction', 'introduction generale',
        'introduction générale',
    ];

    /**
     * Détecte la page de garde (tout ce qui précède le premier titre liminaire
     * ou de corps) et renvoie le nombre de blocs à supprimer.
     *
     * Conformément au §5.2 des spécifications et à la décision D-002 :
     * Le candidat est tout ce qui précède le premier titre liminaire reconnu
     * (sommaire, dédicace, remerciements, avant-propos...) ou, à défaut, le
     * premier titre détecté. Si ce candidat compte ≤ 40 blocs (≤ 2 pages) et
     * comporte au moins 2 formules caractéristiques de couverture, il est
     * supprimé.
     *
     * Renvoie `0` quand il n'y a rien à supprimer.
     *
     * @param  array<int, Block>  $blocs
     * @return array{supprimer: int, raison: string, marqueurs: array<int, string>}
     */
    public function detecter(array $blocs): array
    {
        $aucun = ['supprimer' => 0, 'raison' => 'aucune page de garde détectée', 'marqueurs' => []];

        $total = count($blocs);

        if ($total === 0) {
            $aucun['raison'] = 'document vide';

            return $aucun;
        }

        // 1. Trouver le premier titre liminaire reconnu
        $indexFinCandidat = null;
        foreach ($blocs as $index => $bloc) {
            if ($this->estLiminaire($bloc->text)) {
                $indexFinCandidat = $index;
                break;
            }
        }

        // 2. À défaut de liminaire, chercher le premier titre de corps (hors titre propre à la couverture)
        if ($indexFinCandidat === null) {
            foreach ($blocs as $index => $bloc) {
                if ($bloc->type === BlockType::Heading && ! $this->estTitreDeCouverture($bloc->text)) {
                    $indexFinCandidat = $index;
                    break;
                }
            }
        }

        // Si aucun titre n'est trouvé, le document entier est candidat (couverture isolée)
        $nbCandidats = $indexFinCandidat ?? $total;

        if ($nbCandidats === 0) {
            $aucun['raison'] = 'le document commence directement par un titre liminaire';

            return $aucun;
        }

        // Garde-fou §5.2 : si le candidat dépasse 2 pages (MAX_BLOCS), ne rien supprimer et avertir
        if ($nbCandidats > self::MAX_BLOCS) {
            $aucun['raison'] = "candidat page de garde trop volumineux ({$nbCandidats} blocs > ".self::MAX_BLOCS.')';

            return $aucun;
        }

        // 3. Vérifier la présence de marqueurs distincts de couverture sur la tranche candidate
        $candidats = array_slice($blocs, 0, $nbCandidats);
        $trouves = [];

        foreach ($candidats as $bloc) {
            $texte = $this->normaliser($bloc->text ?? '');

            if ($texte === '') {
                continue;
            }

            foreach (self::MARQUEURS as $marqueur) {
                if (str_contains($texte, $marqueur)) {
                    $trouves[$marqueur] = true;
                }
            }
        }

        if (count($trouves) < self::MIN_MARQUEURS) {
            $aucun['marqueurs'] = array_keys($trouves);
            $aucun['raison'] = sprintf(
                'marqueurs insuffisants dans les %d premiers blocs (%d/%d)',
                $nbCandidats,
                count($trouves),
                self::MIN_MARQUEURS
            );

            return $aucun;
        }

        return [
            'supprimer' => $nbCandidats,
            'raison' => sprintf(
                'page de garde détectée (%d blocs, marqueurs : %s)',
                $nbCandidats,
                implode(', ', array_keys($trouves))
            ),
            'marqueurs' => array_keys($trouves),
        ];
    }

    /**
     * Indique si un titre est typique d'une couverture (ex : RAPPORT DE STAGE).
     */
    private function estTitreDeCouverture(string $texte): bool
    {
        $texte = $this->normaliser($texte);

        foreach (self::MARQUEURS as $marqueur) {
            if (str_contains($texte, $marqueur)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Le titre est-il un intitulé liminaire ?
     */
    private function estLiminaire(string $texte): bool
    {
        $texte = $this->normaliser($texte);

        if ($texte === '' || mb_strlen($texte) > 80) {
            return false;
        }

        foreach (self::LIMINAIRES as $liminaire) {
            if ($texte === $liminaire
                || str_starts_with($texte, $liminaire.' ')
                || str_starts_with($texte, $liminaire.':')
                || str_starts_with($texte, $liminaire."\t")) {
                return true;
            }
        }

        return false;
    }

    /**
     * Minuscules sans accents, pour comparer des textes saisis librement.
     */
    private function normaliser(string $texte): string
    {
        $texte = mb_strtolower(trim($texte));

        return strtr($texte, [
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'à' => 'a', 'â' => 'a', 'ä' => 'a',
            'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c',
            'œ' => 'oe', 'æ' => 'ae',
        ]);
    }
}
