<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Services\Anthropic\ClaudeSkillsService;

/**
 * Inventaire dynamique des capacités de FORMADOC pour l'assistant IA.
 *
 * Exigence : « l'IA doit tout connaître sur l'app : ce qu'elle peut faire ou
 * ne pas faire, et se mettre à jour après chaque amélioration. »
 *
 * L'inventaire n'est PAS écrit en dur : il est généré à partir du code réel
 * (schémas d'outils de ChatToolsService, opérations de DocumentEditService,
 * configuration, skills Claude) → chaque amélioration du code met
 * automatiquement à jour ce que l'IA sait faire.
 *
 * Le prompt système du chat injecte le résultat de `toSystemPrompt()` :
 * l'IA connaît ainsi les outils, les opérations d'édition supportées, les
 * conversions disponibles et les options MANUELLES lorsque l'IA ne peut pas
 * agir (ex. une police exotique non rendue par LibreOffice).
 */
class CapabilitiesService
{
    public function __construct(
        private readonly ChatToolsService $tools,
        private readonly DocumentEditService $documentEditor,
    ) {}

    /**
     * Liste des outils actifs (noms + descriptions courtes).
     *
     * @return array<int, array{name: string, description: string}>
     */
    public function tools(): array
    {
        $tools = [];
        foreach ($this->tools->schemas() as $schema) {
            $fn = $schema['function'] ?? [];
            $tools[] = [
                'name' => (string) ($fn['name'] ?? ''),
                'description' => (string) ($fn['description'] ?? ''),
            ];
        }

        return $tools;
    }

    /**
     * Opérations d'édition de document supportées par l'outil interne.
     *
     * @return array<int, array{operation: string, description: string}>
     */
    public function documentOperations(): array
    {
        return $this->documentEditor->supportedOperations();
    }

    /**
     * Conversions de format disponibles (via LibreOffice headless).
     *
     * @return array<int, array{from: string, to: string, description: string}>
     */
    public function conversions(): array
    {
        return [
            [
                'from' => 'docx',
                'to' => 'pdf',
                'description' => 'Conversion DOCX → PDF (LibreOffice headless) via document_to_pdf.',
            ],
            [
                'from' => 'pdf',
                'to' => 'docx',
                'description' => 'Conversion PDF → DOCX éditable (LibreOffice headless) via document_to_docx.',
            ],
        ];
    }

    /**
     * Ce que l'IA NE PEUT PAS faire automatiquement + options manuelles.
     *
     * @return array<int, array{limitation: string, manual_option: string}>
     */
    public function limitations(): array
    {
        return [
            [
                'limitation' => 'Polices exotiques non installées sur le serveur '
                    .'(ex. une police d\'entreprise) : LibreOffice/PhpWord appliquent '
                    .'une police de substitution.',
                'manual_option' => 'Ouvrez le document dans Word : Sélectionner tout (Ctrl+A) '
                    .'→ onglet Accueil → choisir la police dans le menu déroulant. '
                    .'Ou Word : Accueil → Remplacer (Ctrl+H) → Remplacer la police.',
            ],
            [
                'limitation' => 'Fichiers PDF protégés (mot de passe) : impossible de les '
                    .'convertir en DOCX sans le mot de passe.',
                'manual_option' => 'Ouvrez le PDF dans Adobe Acrobat : Outils → Protéger → '
                    .'Supprimer le cryptage, puis réessayez la conversion.',
            ],
            [
                'limitation' => 'Images contenant du texte (scan, photo) : le texte n\'est pas '
                    .'modifiable tant que le document n\'a pas été passé par un OCR.',
                'manual_option' => 'Word : Ouvrir le PDF scanné → Word lance l\'OCR automatiquement '
                    .'(Fichier → Ouvrir → sélectionner le PDF). Puis enregistrer en DOCX.',
            ],
            [
                'limitation' => 'Macros VBA / documents .docm : non exécutées, contenu '
                    .'potentiellement perdu.',
                'manual_option' => 'Word : Fichier → Options → Centre de gestion de la confidentialité '
                    .'→ Paramètres des macros → Activer, puis ré-enregistrer en .docm.',
            ],
        ];
    }

    /**
     * Skills documentaires Claude disponibles (plans payants / pay-per-use).
     *
     * @return array<int, array{skill: string, eligible: string}>
     */
    public function claudeSkills(): array
    {
        $skills = [];
        foreach (ClaudeSkillsService::SKILLS as $skill) {
            $skills[] = [
                'skill' => $skill,
                'eligible' => 'Abonnement ≥ '.config('billing.skills_min_plan', 'standard')
                    .' inclus, sinon pay-per-use en crédits (×'.config('billing.skills_no_subscription_multiplier', 1.5).').',
            ];
        }

        return $skills;
    }

    /**
     * Génère le bloc texte injecté dans le prompt système du chat.
     */
    public function toSystemPrompt(): string
    {
        $lines = [];
        $lines[] = 'CAPACITÉS DE FORMADOC (inventaire dynamique, mis à jour à chaque amélioration) :';
        $lines[] = '';

        $lines[] = 'OUTILS DISPONIBLES :';
        foreach ($this->tools() as $tool) {
            $lines[] = '  - '.$tool['name'].' : '.$tool['description'];
        }

        $lines[] = '';
        $lines[] = 'OPÉRATIONS D\'ÉDITION DE DOCUMENT (document_edit) :';
        foreach ($this->documentOperations() as $op) {
            $lines[] = '  - '.$op['operation'].' : '.$op['description'];
        }

        $lines[] = '';
        $lines[] = 'CONVERSIONS DE FORMAT :';
        foreach ($this->conversions() as $conv) {
            $lines[] = '  - '.$conv['from'].' → '.$conv['to'].' : '.$conv['description'];
        }

        $lines[] = '';
        $lines[] = 'SKILLS CLAUDE DOCUMENTAIRES (génération native docx/xlsx/pptx/pdf) :';
        foreach ($this->claudeSkills() as $skill) {
            $lines[] = '  - '.$skill['skill'].' : '.$skill['eligible'];
        }

        $lines[] = '';
        $lines[] = 'LIMITATIONS ET OPTIONS MANUELLES (si un outil échoue, proposez ces options) :';
        foreach ($this->limitations() as $limit) {
            $lines[] = '  - Limitation : '.$limit['limitation'];
            $lines[] = '    Option manuelle : '.$limit['manual_option'];
        }

        return implode("\n", $lines);
    }
}
