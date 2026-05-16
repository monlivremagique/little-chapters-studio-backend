# Admin Book Publication Guide

## Overview

The admin book creation pipeline transforms a YAML brief into a premium published book
visible in the frontend catalog, available in FR/NL/EN.

## Pipeline Steps

```
Brief YAML → Master generation → QA corrective pass → Re-QA (up to 3x)
→ QA gate (≥9/10) → Blueprint validation → Runtime projection FR/NL/EN
→ Runtime validation → Cover/page generation → Sylius catalog sync
→ Local verification (API + assets HTTP 200) → [Prod sync]
```

## Entry Point

```bash
php bin/console app:book-factory:create-from-brief [options] <brief.yaml>
```

### Options

| Option | Description |
|--------|-------------|
| `--generate-images` | Génère les images via Replicate FLUX 2 Pro |
| `--base-url` | URL de base pour la vérification API locale |
| `--sync-prod` | Sync le catalogue vers Railway (production) |
| `--dry-run` | Écrit les prompts Claude sans appeler Replicate |
| `--output-dir` | Répertoire de sortie personnalisé |

### Flags mutuellement exclusifs

`--generate-images` et `--sync-prod` ne peuvent pas être combinés.
Les images Replicate ne doivent JAMAIS être regénérées en production.

## Workflow Complet

### Étape 1 : Créer le brief YAML

Le fichier de brief se trouve dans `resources/book-briefs/{slug}.yaml`.

**Champs obligatoires :**
```yaml
slug: nom-du-livre                    # Identifiant unique (kebab-case)
title: Le titre du livre              # Titre en français
theme: [magic, family]                # Thèmes (array)
age: 6-8                              # Tranche d'âge
story_subject: "..."                  # Résumé de l'histoire en FR
main_emotion: "..."                   # Émotion principale
learning_message: "..."               # Message éducatif
languages: [fr, en, nl]              # Langues supportées
visual_style: "..."                   # Style visuel (anglais)
story_page_count: 6                   # Nombre de pages (standard)
```

**Champs recommandés pour la qualité premium :**
```yaml
arc_type: mystery-to-healing          # Structure narrative
climax_page: page_5                   # Page du climax
setting: "..."                        # Cadre détaillé
cultural_context: "..."               # Contexte belge
parent_emotion_goal: "..."            # Objectif émotionnel parent
secondary_characters:                 # Personnages secondaires
  - description personnage 1
  - description personnage 2
scenes:                               # Script détaillé par page
  - id: page_1
    moment: "Description précise de la scène"
```

### Étape 2 : Générer le master

```bash
php bin/console app:book-factory:create-from-brief \
  resources/book-briefs/mon-livre.yaml \
  --base-url=http://localhost:8001
```

Cette commande :
1. Appelle Claude 4 Sonnet sur Replicate pour générer le master blueprint
2. Exécute un QA correctif (Claude révise et corrige le master)
3. **Re-QA** : le master corrigé est soumis à nouveau (jusqu'à 3 fois)
4. Vérifie que le blueprint master est valide
5. Sauvegarde `master.json`

**Artéfacts générés :**
- `resources/book-blueprints/{slug}/master.json`
- `resources/book-blueprints/{slug}/claude-master-debug.json`
- `resources/book-blueprints/{slug}/claude-master-prompt.txt`
- `resources/book-blueprints/{slug}/claude-qa-report.json` (après QA)

### Étape 3 : QA Gate (≥ 9/10)

Le QA gate vérifie automatiquement :
- **heroBible** : identityRules, characterDesign, forbiddenDrift — tous non-vides
- **visualBible** : style_rules, palette, lighting — tous présents
- **Score moyen** ≥ 9.0/10 (90%)

Si le score est < 9/10, la pipeline s'arrête. Consultez :
```
resources/book-blueprints/{slug}/claude-qa-report.json
resources/book-blueprints/{slug}/claude-qa-debug.json
```

### Étape 4 : Générer les images

```bash
php bin/console app:book-factory:create-from-brief \
  resources/book-briefs/mon-livre.yaml \
  --base-url=http://localhost:8001 \
  --generate-images
```

**Ordre de génération :**
1. `page_1` + héro référence → `hero-reference.png`
2. Cover (avec héro référence) → `cover-generated.png`
3. Pages 2-6, summary (avec couverture + héro référence)
4. backCover (avec couverture + héro référence)
5. PDF print-ready → `print-ready.pdf`

**Images générées :**
- `generated-cover/cover-generated.png`
- `generated-pages/hero-reference.png`
- `generated-pages/page_{1-6}-generated.png`
- `generated-pages/summary-generated.png`
- `generated-pages/backCover-generated.png`

### Étape 5 : Sync catalogue Sylius

Le blueprint pipeline synchronise automatiquement :
- Products Sylius
- ProductVariants
- ChannelPricing
- Attributs localisés (FR/NL/EN)
- Assets physiques dans `public/uploads/books/{slug}/`

**Vérification locale :** Le pipeline vérifie que :
- Le livre apparaît dans `GET /api/books`
- Chaque locale retourne un `bookBlueprint` valide
- Les assets images retournent HTTP 200

### Étape 6 : Vérification frontend

```bash
curl http://localhost:8080/books/{slug}?locale=fr
curl http://localhost:8080/books/{slug}?locale=nl
curl http://localhost:8080/books/{slug}?locale=en
```

Le livre est visible dans le catalogue frontend (`GET /api/books`).

### Étape 7 : Publication production (--sync-prod)

```bash
# 1. Générer le manifeste
php bin/console app:book:publication-manifest {slug}

# 2. Committer les assets
git add resources/book-blueprints/{slug}/
git commit -m "feat: book {slug}"
git push

# 3. Sync production (attend le déploiement Railway vert)
php bin/console app:book-factory:create-from-brief \
  resources/book-briefs/mon-livre.yaml \
  --base-url=https://api.monlivremagique.be \
  --sync-prod
```

## QA et Re-QA

### Comment fonctionne la QA

1. **Génération du master** : Claude crée un blueprint complet
2. **QA corrective** : Un second appel Claude évalue le master sur 6 dimensions
3. **Re-QA (itératif)** : Si le QA initial retourne NO_GO avec un master corrigé,
   le master corrigé est soumis à nouveau (jusqu'à 3 itérations max)
4. **QA Gate** : Vérifie que le score final ≥ 9/10

### Dimensions de scoring (0-10)

| Dimension | Description | Seuil premium |
|-----------|-------------|---------------|
| editorial | Qualité poétique, arc narratif, fluidité lecture | ≥ 9 |
| imageability | Richesse visuelle, prompts concrets | ≥ 9 |
| heroConsistency | HeroBible précis, pas de "generic" | ≥ 9 |
| localeCompleteness | FR/NL/EN complets et idiomatiques | ≥ 9 |
| bedtimeSafety | Sûr pour l'âge cible | ≥ 9 |
| premiumBelgium | Positionnement belge premium | ≥ 9 |

### Pourquoi le score peut être bas

Raisons courantes d'échec du QA gate :

| Problème | Cause | Solution |
|----------|-------|----------|
| heroConsistency < 9 | HeroBible trop vague ("generic child") | Améliorer le brief avec des descriptions précises |
| editorial < 9 | Texte générique non-poétique | Enrichir les `scenes` du brief |
| premiumBelgium < 8 | Pas assez de spécificité belge | Ajouter `cultural_context` détaillé |
| localeCompleteness < 9 | NL/EN semblent traduits du FR | Le QA corrige automatiquement |

### Reprise après erreur

La pipeline utilise un système de checkpoint (`resources/book-blueprints/{slug}/.pipeline-state.json`).

Chaque étape réussie est enregistrée. En cas d'échec :
```
Resume mode: 2 step(s) already completed (master_generated, qa_gate_passed)
```

Les étapes déjà complétées sont sautées automatiquement.

## Recommandations pour un Livre Premium

### Brief de qualité

Un brief doit contenir :

1. **Contexte belge explicite** : maison en briques, jardin, gaufres du dimanche,
   hortensias, douceur intergénérationnelle
2. **Scènes détaillées** : chaque page doit avoir un moment narratif précis
3. **Arc narratif clair** : mystery-to-healing, adventure-to-discovery, etc.
4. **Personnages secondaires décrits** : grand-mère, animal, objet magique
5. **Objectif émotionnel** : ce que le parent veut que l'enfant ressente

### Qualité des prompts

Les prompts actuels de génération du master et de QA ont été renforcés pour exiger :
- **heroBible concret** (plus de "generic child")
- **Langage poétique** et non générique
- **Éléments culturels belges** explicites
- **Re-QA itératif** pour affiner le résultat

### Vérification manuelle recommandée

Avant publication, vérifier :
```bash
php bin/console app:book:publication-report {slug}
```

## Résolution de Problèmes

### "Claude did not return valid JSON"

Le modèle peut parfois renvoyer du texte avant/après le JSON.
Le système extrait automatiquement le JSON des `{...}`.

### "QA GATE FAILURE"

Consultez `claude-qa-report.json` pour les scores détaillés et les
`blockingIssues`. Chaque issue est une piste d'amélioration du brief.

### La pipeline s'arrête à l'étape 3e (images)

Les images Replicate échouent parfois. Re-exécutez la commande :
- Les images déjà générées sont conservées
- Les images manquantes sont regénérées
- Le checkpoint évite de re-générer le master

## Commandes Utiles

```bash
# Générer un master uniquement
php bin/console app:book:generate-master-from-brief --brief=... --qa-correct

# Valider un blueprint
php bin/console app:book:validate-blueprint --file=...  

# Projeter les runtimes
php bin/console app:book:generate-blueprint --source=...

# Sync catalogue Sylius uniquement
php bin/console app:sync-book-blueprints

# Rapport de publication
php bin/console app:book:publication-manifest {slug}
php bin/console app:book:publication-report {slug}
```
