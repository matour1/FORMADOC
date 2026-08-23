# 06 – Limitations de PHPWord et alternatives

## Ce que PHPWord gère bien

- Sections multiples
- Titres + Table des matières
- Tableaux (avec largeurs explicites)
- Images
- TextBox basiques
- Header / Footer
- `setUpdateFields(true)`

## Ce que PHPWord gère mal ou pas du tout

| Fonctionnalité                     | État actuel                          | Recommandation |
|------------------------------------|--------------------------------------|----------------|
| Captions + SEQ + Table of Figures  | Très limité / fragile                | Génération manuelle des listes |
| Formes arrondies complexes         | Support VML limité                   | Table + bgColor |
| Positionnement absolu précis       | Difficile                            | Tables + paragraphes |
| Double bordure décorative de page  | Quasi impossible proprement          | Ignorer ou post-traiter |
| Styles de liste très avancés       | Fragile entre Word et LibreOffice    | Rester simple |
| Champs complexes imbriqués         | Support partiel                      | Éviter |

## Alternatives possibles (si un jour besoin)

1. **Template Word + TemplateProcessor**
   - Avantage : design ultra-fidèle
   - Inconvénient : moins flexible, problèmes de corruption parfois avec LibreOffice récents

2. **Génération HTML → PDF → conversion**
   - Avantage : contrôle CSS
   - Inconvénient : document Word final souvent moins éditable

3. **Injection de XML brut pour les champs SEQ**
   - Possible mais technique et fragile
   - À réserver pour une version future

4. **OnlyOffice / Collabora** en édition collaborative
   - Si tu veux vraiment laisser les utilisateurs éditer dans le navigateur

## Position actuelle de la plateforme

On reste sur **génération pure en code PHPWord** + listes manuelles + page de garde construite avec tables et TextBox.

C’est le meilleur compromis entre :
- Fidélité visuelle acceptable
- Éditabilité réelle dans Word
- Maintenabilité du code
- Robustesse (Word + LibreOffice)

## Quand reconsidérer l’approche

- Si les utilisateurs exigent une page de garde **pixel-perfect** identique au PDF
- Si tu as besoin de vraies Table of Figures automatiques avec mise à jour dynamique
- Si le volume de documents devient très élevé et que tu veux un moteur de templates plus puissant
