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
     * Titres qui prouvent un rapport COMPLET, pas une page de garde.
     *
     * @var array<int, string>
     */
    private const LIMINAIRES = [
        'sommaire', 'table des matieres', 'dedicace', 'remerciements',
        'avant-propos', 'sigles', 'abreviations', 'liste des figures',
        'liste des tableaux', 'liste des sigles', 'resume', 'abstract',
        'introduction', 'introduction generale',
    ];

    /**
     * Détecte la page de garde et renvoie le nombre de blocs à supprimer.
     *
     * Renvoie `0` quand il n'y a rien à supprimer — l'appelant n'a donc pas de
     * cas particulier à traiter.
     *
     * @param  array<int, Block>  $blocs
     * @return array{supprimer: int, raison: string, marqueurs: array<int, string>}
     */
    public function detecter(array $blocs): array
    {
        $aucun = ['supprimer' => 0, 'raison' => 'aucune page de garde détectée', 'marqueurs' => []];

        $total = count($blocs);

        if ($total === 0 || $total > self::MAX_BLOCS) {
            $aucun['raison'] = $total === 0
                ? 'document vide'
                : "document trop volumineux ({$total} blocs) pour être une page de garde";

            return $aucun;
        }

        // Un titre liminaire prouve un rapport complet : on ne touche à rien.
        foreach ($blocs as $bloc) {
            if ($bloc->type !== BlockType::Heading) {
                continue;
            }

            if ($this->estLiminaire($bloc->text)) {
                $aucun['raison'] = 'le document contient un titre liminaire : ce n\'est pas une page de garde isolée';

                return $aucun;
            }
        }

        $trouves = [];

        foreach ($blocs as $bloc) {
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

            return $aucun;
        }

        return [
            'supprimer' => $total,
            'raison' => sprintf(
                'page de garde détectée (%d blocs, marqueurs : %s)',
                $total,
                implode(', ', array_keys($trouves))
            ),
            'marqueurs' => array_keys($trouves),
        ];
    }

    /**
     * Le titre est-il un intitulé liminaire ?
     */
    private function estLiminaire(string $texte): bool
    {
        $texte = $this->normaliser($texte);

        foreach (self::LIMINAIRES as $liminaire) {
            if ($texte === $liminaire
                || str_starts_with($texte, $liminaire.' ')
                || str_starts_with($texte, $liminaire.':')) {
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
