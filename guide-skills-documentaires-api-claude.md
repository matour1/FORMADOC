# Utiliser les Skills documentaires de Claude dans une application (API)

## 1. Vue d'ensemble

Anthropic fournit 4 Agent Skills pré-construites pour la génération/manipulation de documents, utilisables via l'API `/v1/messages` :

| skill_id | Usage |
|---|---|
| `docx` | Word : création, édition, tracked changes |
| `xlsx` | Excel : formules, formatage, modèles financiers |
| `pptx` | PowerPoint : création de slides, templates |
| `pdf` | PDF : extraction, remplissage de formulaires, fusion |

**Prérequis non négociable : ces Skills tournent *dans* le conteneur de l'outil `code_execution`.** Sans cet outil activé dans ta requête, `container.skills` ne fait rien. C'est le piège n°1 des implémentations qui échouent silencieusement.

---

## 2. Appel API minimal

```python
from anthropic import Anthropic

client = Anthropic()

response = client.beta.messages.create(
    model="claude-sonnet-4-6",
    max_tokens=16000,
    betas=["skills-2025-10-02"],
    container={
        "skills": [{"type": "anthropic", "skill_id": "pptx", "version": "latest"}]
    },
    tools=[{"type": "code_execution_20260521", "name": "code_execution"}],
    messages=[
        {"role": "user", "content": "Crée une présentation de 5 slides sur les énergies renouvelables"}
    ],
)
```

### Récupérer le fichier généré

Le fichier n'arrive **pas** dans le texte de réponse. Il faut parcourir les blocs de résultat pour trouver un `file_id`, puis appeler l'API Files pour le télécharger :

```python
import tempfile
from pathlib import Path

file_id = None
for block in response.content:
    if block.type == "bash_code_execution_tool_result":
        if block.content.type == "bash_code_execution_result":
            for output in block.content.content:
                file_id = output.file_id

if file_id:
    output_path = Path(tempfile.gettempdir()) / "presentation.pptx"
    file_content = client.beta.files.download(file_id=file_id)
    file_content.write_to_file(output_path)
```

**Ce que ça implique pour une application réelle :**
- Il faut un étage de parsing dédié (ne jamais supposer que le fichier est à un index fixe du tableau `content`).
- Il faut gérer le cas où `file_id` reste `None` (échec silencieux, timeout du conteneur, erreur d'exécution du script interne au skill).
- Prévoir un stockage intermédiaire (S3, disque temporaire) avant de servir le fichier à l'utilisateur final — l'API Files n'est pas un CDN.

---

## 3. Coûts (à vérifier sur la doc officielle avant mise en prod — les prix évoluent)

| Poste | Coût |
|---|---|
| Tokens input/output du modèle | Prix standard du modèle choisi (ex. Sonnet, Opus, Haiku) |
| `code_execution` seul | 50h gratuites/jour/organisation (~1 550h/mois), puis **0,05 $/heure de conteneur**, minimum 5 minutes par exécution |
| `code_execution` combiné à web search/fetch | Gratuit |
| Requête avec fichiers joints | Le temps d'exécution est facturé **même si l'outil n'est jamais invoqué**, car les fichiers sont préchargés sur le conteneur |
| Batch API | -50% sur les tokens, mais traitement asynchrone (pas de génération de fichier en temps réel) |

**Angle mort fréquent :** le minimum de 5 minutes par exécution veut dire qu'une génération de document de 10 secondes coûte quand même 5 minutes de conteneur. Si ton app génère 10 000 petits documents/jour en calls séparés, tu factures potentiellement 10 000 × 5 min = ~833h de conteneur, bien au-delà des 50h gratuites.

---

## 4. Comportement en cas de demande en masse

### Ce qui limite réellement le débit
1. **Rate limits par tier** (RPM / TPM), déterminés par ton historique de dépense sur le compte Anthropic — pas configurables manuellement à la hausse sans upgrade de tier.
2. **Conteneurs = ressource physique** : chaque session avec `code_execution` consomme un conteneur sandboxé. Une rafale de requêtes simultanées peut se heurter à une limite de concurrence avant même de toucher les limites de tokens.
3. **Coût qui grimpe non linéairement** : au-delà du quota gratuit journalier, chaque conteneur additionnel est facturé — un pic de trafic peut transformer un coût prévisible en facture surprise si rien n'encadre le volume.

### Ce qu'il faut mettre en place avant un go-live à volume
- **File d'attente (queue) + traitement asynchrone** plutôt que des appels synchrones un par un — ne jamais laisser un utilisateur final déclencher un appel `code_execution` directement sans throttling en amont.
- **Retry avec backoff exponentiel** sur les erreurs 429 (rate limit) — ne pas relancer en boucle serrée, ça aggrave la situation et peut geler tout ton tier temporairement.
- **Batch API pour tout ce qui n'est pas temps réel** (rapports de fin de journée, exports groupés) : moitié prix, mais délai de traitement à accepter.
- **Grouper les demandes similaires** quand c'est possible plutôt que de multiplier les appels unitaires — chaque appel paie le minimum de 5 minutes de conteneur, donc consolider réduit mécaniquement le nombre de conteneurs facturés.
- **Alerting sur le quota gratuit** : suivre la consommation d'heures de conteneur en continu, pas a posteriori sur la facture.
- **Séparer les tiers de charge** : un plan Team/Enterprise ou un tier API supérieur augmente les limites RPM/TPM, mais ne réduit pas le coût par conteneur — ce sont deux leviers différents (débit vs coût unitaire).

---

## 5. Ce que tu n'as probablement pas envisagé

- **Claude Code n'a pas ces skills documentaires pré-construites.** Si une partie de ton pipeline passe par Claude Code plutôt que par l'API brute, tu ne peux pas t'appuyer sur `docx`/`pptx`/`xlsx`/`pdf` de la même façon — il faudrait des skills custom réimplémentées.
- **Pas d'accès réseau ni d'installation de packages dans le conteneur** — si ton skill custom dépend d'une librairie tierce non préinstallée, ça échoue silencieusement en prod alors que ça marchait en local.
- **Le coût par conteneur peut dominer le coût en tokens** pour des documents courts et nombreux — le calcul "prix par token du modèle" que la plupart des gens font pour estimer un budget est souvent trompeur ici. Fais le calcul sur le nombre d'*appels*, pas seulement sur le volume de texte.

---

*Sources : documentation officielle Claude Platform (agent-skills/overview, agent-skills/quickstart, pricing) — à revérifier avant mise en production, les tarifs et limites évoluent régulièrement.*
