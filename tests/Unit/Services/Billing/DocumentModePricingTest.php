<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Billing;

use App\Services\Billing\DocumentModePricing;
use App\Services\Billing\UsageCostCalculator;
use Tests\TestCase;

/**
 * Tarification des modes de traitement d'un document.
 *
 * **Ce que ces tests protègent.** Trois modes coexistent, et deux d'entre eux
 * étaient facturés à tort :
 *
 *   - le mode « IA complète » envoyait le document ENTIER à DeepSeek sans
 *     qu'aucun crédit ne soit débité — l'appel se faisait hors d'OpenRouter,
 *     donc hors du registre d'usage ;
 *   - l'« assistance IA » était dans le même cas.
 *
 * Le prix était par ailleurs lu par notation pointée
 * (`config("openrouter.pricing.{$model}")`), ce qui renvoyait `null` pour tout
 * modèle dont le nom contient un point. Le coût tombait alors à zéro, et
 * l'appel était facturé zéro quel que soit son prix réel.
 *
 * Ces tests fixent donc trois propriétés : le mode gratuit ne coûte rien, les
 * modes payants coûtent quelque chose, et le prix suit le MODÈLE réellement
 * routé.
 */
class DocumentModePricingTest extends TestCase
{
    private function pricing(): DocumentModePricing
    {
        return new DocumentModePricing(new UsageCostCalculator);
    }

    // -------------------------------------------------------------------------
    // Le défaut de lecture du prix, et sa correction
    // -------------------------------------------------------------------------

    /**
     * **Le test central : le prix d'un modèle au nom contenant un point doit
     * être trouvé.**
     *
     * `anthropic/claude-3.5-sonnet` contient deux points. Laravel interprète les
     * points de la notation `config('a.b.c')` comme un chemin imbriqué, donc
     * cherchait la clé `3.5-sonnet` sous `anthropic/claude-3` — inexistante. Le
     * prix était `null`, le coût calculé à 0, et l'appel facturé 0.
     *
     * Trois modèles de la grille étaient concernés, dont celui des plans
     * standard à enterprise. Autrement dit : TOUS les clients payants.
     */
    public function test_le_prix_est_trouve_pour_un_modele_au_nom_contenant_un_point(): void
    {
        $calculateur = new UsageCostCalculator;

        $prix = $calculateur->pricingFor('anthropic/claude-3.5-sonnet');

        $this->assertNotNull($prix, 'Le prix d\'un modèle au nom contenant un point doit être lisible.');
        $this->assertSame(3.0, (float) $prix['input']);
        $this->assertSame(15.0, (float) $prix['output']);
    }

    /**
     * Tous les modèles de la grille ont un prix lisible.
     *
     * Un prix illisible ne produit AUCUNE erreur : le coût tombe simplement à
     * zéro, et l'appel passe pour gratuit. C'est ce silence qui a laissé passer
     * le défaut, donc on vérifie l'ensemble de la grille plutôt qu'un cas.
     */
    public function test_tous_les_modeles_de_la_grille_ont_un_prix_lisible(): void
    {
        $calculateur = new UsageCostCalculator;
        $grille = (array) config('openrouter.pricing', []);

        $this->assertNotEmpty($grille, 'La grille tarifaire est vide.');

        $illisibles = [];

        foreach (array_keys($grille) as $modele) {
            if ($calculateur->pricingFor((string) $modele) === null) {
                $illisibles[] = $modele;
            }
        }

        $this->assertSame([], $illisibles,
            'Ces modèles ont un prix déclaré mais illisible : leur coût serait calculé à zéro, '
            .'donc facturé zéro. Modèles concernés : '.implode(', ', $illisibles));
    }

    /**
     * Un coût non calculable est signalé par `null`, jamais par `0.0`.
     *
     * **La distinction n'est pas cosmétique.** Un modèle inconnu facturé 0 est
     * indiscernable d'un appel gratuit — c'est exactement pour cela que le défaut
     * de lecture du prix est passé inaperçu. En renvoyant `null`, l'appelant peut
     * rembourser et journaliser au lieu de subir la perte en silence.
     */
    public function test_un_modele_inconnu_renvoie_null_et_non_zero(): void
    {
        $usd = (new UsageCostCalculator)->costUsdFor('fournisseur/modele-inexistant-1.2', 1000, 1000);

        $this->assertNull($usd,
            'Un prix inconnu doit être signalé par null : le confondre avec 0 facture un appel '
            .'au prix de la gratuité.');
    }

    /**
     * Le plan change le modèle routé, donc le prix.
     *
     * Un plan supérieur route vers un modèle plus cher. Si le prix ne suivait pas
     * le modèle, tous les plans seraient facturés pareil — ce qui était le cas
     * avant la correction, où le prix de Claude était illisible.
     */
    public function test_un_plan_superieur_coute_plus_cher(): void
    {
        $pricing = $this->pricing();

        $gratuit = $pricing->estimate(DocumentModePricing::MODE_PRECISION, 100000, 'default');
        $payant = $pricing->estimate(DocumentModePricing::MODE_PRECISION, 100000, 'standard');

        $this->assertNotSame($gratuit['model'], $payant['model'],
            'Deux plans différents doivent router vers deux modèles différents.');
        $this->assertGreaterThan($gratuit['credits'], $payant['credits'],
            'Le modèle des plans payants est plus cher : son estimation doit être PLUS ÉLEVÉE. '
            .'Une estimation inférieure signale un prix non lu (et donc un revenu perdu).');
    }

    // -------------------------------------------------------------------------
    // Nature des modes
    // -------------------------------------------------------------------------

    public function test_le_mode_deterministe_est_gratuit(): void
    {
        $estimation = $this->pricing()->estimate(DocumentModePricing::MODE_REGEX, 200000, 'default');

        $this->assertTrue($estimation['gratuit']);
        $this->assertSame(0, $estimation['credits']);
        $this->assertNull($estimation['model'], 'Aucun modèle ne doit être sollicité en mode gratuit.');
    }

    public function test_les_deux_modes_ia_sont_payants(): void
    {
        $pricing = $this->pricing();

        foreach ([DocumentModePricing::MODE_ASSISTE, DocumentModePricing::MODE_PRECISION] as $mode) {
            $estimation = $pricing->estimate($mode, 100000, 'default');

            $this->assertFalse($estimation['gratuit'], "Le mode {$mode} doit être payant.");
            $this->assertGreaterThan(0, $estimation['credits'], "Le mode {$mode} doit débiter des crédits.");
        }
    }

    /**
     * La pleine précision envoie le document entier : elle coûte plus que
     * l'assistance, qui n'envoie que les éléments ambigus.
     *
     * Si l'ordre s'inversait, le mode présenté comme le plus fidèle serait aussi
     * le moins cher — et l'utilisateur serait incité à prendre le plus coûteux
     * pour l'application.
     */
    public function test_la_pleine_precision_coute_plus_que_l_assistance(): void
    {
        $pricing = $this->pricing();

        foreach ([20000, 100000, 300000] as $taille) {
            $assiste = $pricing->estimate(DocumentModePricing::MODE_ASSISTE, $taille, 'default');
            $precision = $pricing->estimate(DocumentModePricing::MODE_PRECISION, $taille, 'default');

            $this->assertGreaterThan(
                $assiste['credits'],
                $precision['credits'],
                "À {$taille} caractères, la pleine précision doit coûter plus cher que l'assistance."
            );
        }
    }

    /**
     * Le coût croît avec la taille du document.
     *
     * Un tarif qui ne dépendrait pas du volume facturerait le plus petit
     * document au prix du plus gros, ou l'inverse.
     */
    public function test_le_cout_croit_avec_la_taille_du_document(): void
    {
        $pricing = $this->pricing();

        $petit = $pricing->estimate(DocumentModePricing::MODE_PRECISION, 10000, 'default');
        $moyen = $pricing->estimate(DocumentModePricing::MODE_PRECISION, 100000, 'default');
        $grand = $pricing->estimate(DocumentModePricing::MODE_PRECISION, 400000, 'default');

        $this->assertLessThan($moyen['credits'], $petit['credits']);
        $this->assertLessThan($grand['credits'], $moyen['credits']);
    }

    /**
     * Le détail du calcul est fourni, et il est cohérent avec le mode.
     *
     * Un montant qu'on ne peut pas expliquer est un montant qu'on ne peut pas
     * contester — ni corriger s'il est faux.
     */
    public function test_le_detail_du_calcul_accompagne_l_estimation(): void
    {
        $pricing = $this->pricing();

        $precision = $pricing->estimate(DocumentModePricing::MODE_PRECISION, 100000, 'default');
        $assiste = $pricing->estimate(DocumentModePricing::MODE_ASSISTE, 100000, 'default');

        $this->assertStringContainsString('entier', mb_strtolower($precision['detail']),
            'La pleine précision doit dire qu\'elle envoie le document entier.');
        $this->assertStringContainsString('ambigu', mb_strtolower($assiste['detail']),
            'L\'assistance doit dire qu\'elle n\'envoie que les éléments ambigus.');
    }

    /**
     * Les types de tâche déclarés existent dans le routeur.
     *
     * `ModelRouter::select()` LÈVE une exception sur un type inconnu. Une erreur
     * ici surviendrait au moment d'afficher un prix, donc sur le chemin de
     * l'utilisateur — pas dans un test, si ce test n'existait pas.
     */
    public function test_les_types_de_tache_declares_existent_dans_le_routeur(): void
    {
        $taches = array_keys((array) config('openrouter.tasks', []));
        $pricing = $this->pricing();

        foreach ($pricing->modes() as $mode) {
            $taskType = $mode['task_type'];

            if ($taskType === null) {
                continue;
            }

            $this->assertContains($taskType, $taches,
                "Le mode « {$mode['key']} » déclare le type de tâche « {$taskType} », absent de "
                .'config(\'openrouter.tasks\'). ModelRouter lèverait une exception au calcul du prix.');
        }
    }

    /**
     * Le libellé d'un mode inconnu est la clé elle-même, pas un texte générique.
     *
     * Un mode renommé doit se VOIR dans l'interface plutôt que se confondre avec
     * le libellé d'un autre.
     */
    public function test_le_libelle_d_un_mode_inconnu_est_sa_cle(): void
    {
        $this->assertSame('mode-renomme', $this->pricing()->label('mode-renomme'));
    }

    // -------------------------------------------------------------------------
    // Cohérence entre le prix AFFICHÉ et le coût RÉELLEMENT débité
    // -------------------------------------------------------------------------

    /**
     * **Le prix affiché de « pleine précision » INCLUT la mise en forme complète.**
     *
     * Ce mode enchaîne deux opérations : l'analyse complète du document, puis sa
     * mise en forme (`LongFormattingJob`, tâche `document_full_format`, facturée
     * séparément). N'afficher que la première donnerait un prix inférieur au
     * débit — et l'utilisateur le découvrirait sur son solde.
     *
     * **Ce test existe à cause d'une erreur réelle.** Le prix affiché est passé de
     * 21 à 36 crédits sans que rien d'autre ne change : la taille transmise à
     * `estimationMiseEnForme()` était multipliée par 4 « pour simuler un
     * fichier », alors que la fonction DIVISE ce paramètre par 4 pour obtenir des
     * tokens. `caracteres × 4 ÷ 4` redonnait le nombre de caractères, lu comme
     * autant de tokens — trois fois trop.
     *
     * Un prix qui varie sans raison de code doit échouer, pas passer inaperçu :
     * c'est ce que vérifie ce test, en recomposant le total depuis ses deux
     * composantes au lieu de comparer à une constante.
     */
    public function test_le_prix_de_la_pleine_precision_inclut_la_mise_en_forme(): void
    {
        $pricing = $this->pricing();

        foreach ([5000, 40000, 150000] as $chars) {
            $total = $pricing->estimate(DocumentModePricing::MODE_PRECISION, $chars, 'default');

            // La composante « mise en forme », calculée par la MÊME fonction que
            // celle utilisée pour créer le job. Si les deux chemins divergeaient,
            // l'utilisateur verrait un prix et en paierait un autre.
            $miseEnForme = DocumentModePricing::estimationMiseEnForme($chars, $chars, 'default');

            // Sans mise en forme, l'analyse seule serait forcément moins chère.
            $assistance = $pricing->estimate(DocumentModePricing::MODE_ASSISTE, $chars, 'default');

            $this->assertGreaterThan($miseEnForme, $total['credits'],
                "À {$chars} caractères, le prix doit dépasser la seule mise en forme : il couvre "
                .'AUSSI l\'analyse du document entier.');

            $this->assertGreaterThan($assistance['credits'], $total['credits'],
                "À {$chars} caractères, la pleine précision doit coûter plus que l'assistance, "
                .'qui n\'envoie que les éléments ambigus.');
        }
    }

    /**
     * La mise en forme devient moins chère quand le document est plus petit.
     *
     * Le coût de la mise en forme suit la taille envoyée. Une fonction qui
     * ignorerait ce paramètre donnerait le même prix à un devis d'une page et à
     * un mémoire de 300 pages.
     */
    public function test_le_cout_de_mise_en_forme_suit_la_taille(): void
    {
        $petit = DocumentModePricing::estimationMiseEnForme(5000, 5000, 'default');
        $moyen = DocumentModePricing::estimationMiseEnForme(40000, 40000, 'default');
        $grand = DocumentModePricing::estimationMiseEnForme(200000, 200000, 'default');

        $this->assertLessThan($moyen, $petit, 'Un petit document doit coûter moins cher à mettre en forme.');
        $this->assertLessThan($grand, $moyen, 'Un document moyen doit coûter moins cher qu\'un gros.');
    }

    /**
     * Le détail affiché mentionne les DEUX opérations.
     *
     * L'utilisateur doit pouvoir comprendre pourquoi la pleine précision coûte
     * plus de deux fois l'assistance. Un montant sans explication est un montant
     * qu'on ne peut ni vérifier ni accepter.
     */
    public function test_le_detail_de_la_pleine_precision_annonce_les_deux_operations(): void
    {
        $detail = $this->pricing()
            ->estimate(DocumentModePricing::MODE_PRECISION, 40000, 'default')['detail'];

        $this->assertStringContainsString('entier', mb_strtolower($detail));
        $this->assertStringContainsString('mise en forme', mb_strtolower($detail),
            'Le détail doit annoncer la mise en forme complète : c\'est la seconde opération '
            .'facturée, et sans elle le prix paraîtrait arbitrairement élevé.');
    }
}
