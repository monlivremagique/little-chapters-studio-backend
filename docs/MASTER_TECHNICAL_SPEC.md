# MASTER_TECHNICAL_SPEC.md

# Mon Livre Magique — Spécification Technique Pipeline Admin Livre / Blueprint V2 / Replicate

## 1. Objectif technique

Mettre en place un pipeline backend Symfony/Sylius permettant de créer un livre premium à partir d’un blueprint V2, de générer les runtimes localisés FR/NL/EN compatibles BookFlip, de générer les images via Replicate, puis de synchroniser le produit dans Sylius.

Le système doit rester compatible avec le front existant et ne pas casser BookFlip.

## 2. Contraintes absolues

- Ne pas casser le contrat runtime `book_blueprint_json`.
- Ne pas remplacer brutalement le format consommé par BookFlip.
- Garder un runtime blueprint localisé par langue.
- Garder les champs existants : `version`, `title_template`, `negative_prompt_default`, `style_rules`, `pages[]`.
- Garder les types de pages : `cover`, `story`, `dedication`, `summary`, `backCover`.
- Garder `default_image_path` au runtime.
- Garantir FR/NL/EN.
- Garantir idempotence des commandes.
- Ne jamais lancer Replicate en `--dry-run`.
- Générer des fichiers debug avant chaque appel réel IA.

## 3. Modèles Replicate officiels

### 3.1 Texte / blueprint / QA

Modèle :

```text
anthropic/claude-3.7-sonnet
```

Usage futur :

- briefing → master blueprint ;
- QA éditoriale ;
- scoring ;
- correction ;
- localisation ;
- amélioration des prompts.

### 3.2 Images

Modèle :

```text
black-forest-labs/flux-2-pro
```

Usage :

- cover ;
- hero/style references si implémenté ;
- pages story ;
- backCover ;
- éventuellement summary/dedication neutral images.

## 4. Architecture blueprint

### 4.1 Master Blueprint V2

Fichier source authoring/build :

```text
resources/book-blueprints/{slug}/master.json
```

Il contient :

- `schema = book_blueprint_v2`
- `schemaVersion = 2`
- `metadata`
- `locales.fr/en/nl`
- `visualBible`
- `heroBible`
- `sceneDefinitions`
- `imageGeneration`
- `assets`
- `qa`

Le master est multilingue, mais les prompts image sont principalement en anglais.

### 4.2 Runtime Blueprint V2 localisé

Fichiers générés :

```text
resources/book-blueprints/{slug}/generated/runtime.fr.json
resources/book-blueprints/{slug}/generated/runtime.nl.json
resources/book-blueprints/{slug}/generated/runtime.en.json
```

Ces fichiers sont compatibles avec le contrat runtime actuel :

```json
{
  "version": 2,
  "title_template": "...",
  "negative_prompt_default": "...",
  "style_rules": [],
  "metadata": {},
  "visualBible": {},
  "heroBible": {},
  "imageGeneration": {},
  "assets": {},
  "qa": {},
  "pages": []
}
```

`pages[]` doit garder :

- `id`
- `type`
- `title_template`
- `text_template`
- `default_image_path`
- `prompt_template`
- `negative_prompt`
- `personalizable`
- `aspect_ratio`
- `page_number`
- `scene_key`

## 5. Contrat front / BookFlip

Le front ne lit pas directement `resources/book-blueprints`.

Le front lit via l’API catalogue :

```text
GET /api/books/{slug}?locale=fr|nl|en
```

Le backend retourne un `bookBlueprint` issu de l’attribut Sylius traduit `book_blueprint_json`.

BookFlip dépend principalement de :

- `bookBlueprint.pages[]`
- `page.id`
- `page.type`
- `page.text_template`
- `page.default_image_path`
- ordre des pages
- preview reconstruite via endpoint session.

La preview personnalisée utilise :

```text
GET /api/personalization/sessions/{id}/preview
```

Le contrat preview doit rester :

```json
{
  "pages": [
    {
      "id": "cover",
      "type": "cover",
      "pageNumber": 1,
      "imageUrl": "...",
      "isPersonalized": true,
      "label": "...",
      "title": "...",
      "text": null
    }
  ]
}
```

## 6. Locale / i18n

Le risque critique déjà identifié était : session NL/EN pouvant recharger un blueprint FR.

Décision technique appliquée :

- `PersonalizationSession::getResolvedBookLocale(): string`
- seules `fr`, `en`, `nl` sont valides ;
- fallback explicite `fr` ;
- tout chargement de livre/blueprint dans la chaîne personnalisation utilise cette locale résolue.

Points déjà alignés :

- `PersonalizationPreviewGenerator`
- `PreviewVersionFactory`
- `PersonalizationSessionController`

À maintenir absolument dans la suite.

## 7. Commandes existantes / attendues

### 7.1 `app:book:validate-blueprint`

Statut : implémentée.

But : valider master ou runtime.

Usage :

```bash
php bin/console app:book:validate-blueprint --file=resources/book-blueprints/forest-of-lost-stars/master.json
php bin/console app:book:validate-blueprint --runtime --file=resources/book-blueprints/forest-of-lost-stars/generated/runtime.fr.json
```

Doit retourner :

- exit code 0 si OK ;
- exit code 1 si FAIL ;
- rapport console clair.

### 7.2 `app:book:generate-blueprint`

Statut : implémentée.

But : projeter master → runtime localisés.

Usage :

```bash
php bin/console app:book:generate-blueprint \
  --source=resources/book-blueprints/forest-of-lost-stars/master.json \
  --output-dir=resources/book-blueprints/forest-of-lost-stars/generated
```

Garanties :

- validation master avant projection ;
- JSON déterministe ;
- pages ordonnées par `pageNumber` ;
- prompts identiques entre langues ;
- textes localisés ;
- validation runtime après projection ;
- écriture atomique.

### 7.3 `app:book:generate-cover`

Statut : implémentée en dry-run, prompt builder en cours de correction.

But : générer uniquement la cover via Replicate/FLUX.

Usage dry-run :

```bash
php bin/console app:book:generate-cover \
  --source=resources/book-blueprints/forest-of-lost-stars/master.json \
  --output-dir=resources/book-blueprints/forest-of-lost-stars/generated-cover \
  --dry-run
```

Usage réel :

```bash
php bin/console app:book:generate-cover \
  --source=resources/book-blueprints/forest-of-lost-stars/master.json \
  --output-dir=resources/book-blueprints/forest-of-lost-stars/generated-cover
```

Avec photo enfant :

```bash
php bin/console app:book:generate-cover \
  --source=resources/book-blueprints/forest-of-lost-stars/master.json \
  --photo=/path/photo.png
```

Sorties attendues en dry-run :

- `cover-prompt.txt`
- `cover-negative-prompt.txt`
- `cover-debug.json`

Sorties attendues en réel :

- `cover-generated.png`
- `cover-prompt.txt`
- `cover-negative-prompt.txt`
- `cover-debug.json`

`cover-debug.json` doit contenir :

- source ;
- model ;
- scene ;
- dryRun ;
- photoProvided ;
- outputDir ;
- prompt ;
- negativePrompt ;
- replicateInputPayload ;
- prediction/result si réel ;
- estimatedCostUsd.

### 7.4 `app:book:build`

Statut : à implémenter.

But cible : orchestrer tout ou partie du pipeline.

Modes recommandés :

```bash
php bin/console app:book:build forest-of-lost-stars --no-sync
php bin/console app:book:build forest-of-lost-stars --generate-images --no-sync
php bin/console app:book:build forest-of-lost-stars --sync
php bin/console app:book:build forest-of-lost-stars --dry-run
```

Responsabilités futures :

- valider master ;
- générer runtimes ;
- valider runtimes ;
- vérifier assets ;
- générer images si demandé ;
- synchroniser Sylius si demandé ;
- vérifier catalogue local ;
- produire rapport final.

## 8. CoverPromptBuilder — exigence immédiate

Le prompt cover actuel est NO-GO tant qu’il :

- répète la scène ;
- concatène `promptTemplate` libre + champs structurés redondants ;
- contient des phrases cassées ;
- parle de `personalized`, `likeness`, `same face` alors qu’aucune photo n’est fournie ;
- met `inconsistent child likeness` dans le negative prompt sans photo.

### 8.1 Structure cible du prompt cover

Le prompt final doit être court, hiérarchique, non répétitif :

```text
STYLE: ...
HERO: ...
CAMERA: ...
COMPOSITION: ...
FOREGROUND: ...
MIDGROUND: ...
BACKGROUND: ...
LIGHTING: ...
EMOTION: ...
MUST_SHOW: ...
MUST_NOT_SHOW: ...
```

### 8.2 Règles sans photo

Si `--photo` absent :

- utiliser `generic premium child hero` ;
- ne pas utiliser `personalized` ;
- ne pas utiliser `likeness` ;
- ne pas utiliser `same face` ;
- ne pas utiliser `reference photo`.

Negative prompt sans photo :

```text
blurry, distorted anatomy, extra fingers, extra limbs, duplicate child, wrong anatomy, harsh darkness, horror mood, scary atmosphere, aggressive action, cluttered scene, teenage proportions, neon sci-fi, text, watermark, logo
```

### 8.3 Règles avec photo

Si `--photo` présent :

- utiliser `personalized child hero based on the provided reference photo` ;
- autoriser `preserve child likeness` ;
- autoriser `same face`, `same proportions`, `same perceived age` ;
- inclure la photo dans le payload Replicate selon les paramètres supportés par le modèle.

Negative prompt avec photo : ajouter :

```text
inconsistent child likeness, inconsistent face, different hairstyle
```

### 8.4 Tests obligatoires CoverPromptBuilder

- dry-run sans photo crée les 3 fichiers ;
- aucun `cover-generated.png` en dry-run ;
- prompt sans photo ne contient pas `personalized`, `likeness`, `same face`, `reference photo` ;
- negative sans photo ne contient pas `likeness`, `inconsistent face` ;
- prompt ne contient pas `Scene directive` ;
- prompt ne répète pas deux fois `Composition` ;
- prompt ne contient pas phrase cassée `same age and ,`.

## 9. Génération pages

Après cover GO seulement.

Commande future recommandée :

```bash
php bin/console app:book:generate-page \
  --source=resources/book-blueprints/forest-of-lost-stars/master.json \
  --page=page_1 \
  --output-dir=resources/book-blueprints/forest-of-lost-stars/generated-pages
```

Puis généralisation :

```bash
php bin/console app:book:generate-pages \
  --source=resources/book-blueprints/forest-of-lost-stars/master.json \
  --output-dir=resources/book-blueprints/forest-of-lost-stars/generated-pages
```

Ne pas générer toutes les pages avant cover GO.

## 10. Sync Sylius

À implémenter après validation images minimale.

But : écrire les runtimes dans Sylius.

Mapping :

- FR → `fr_FR` ;
- NL → `nl_NL` ;
- EN → `en_US` ;
- attribut : `book_blueprint_json` ;
- produit identifié par slug/code stable ;
- idempotence stricte.

À vérifier après sync :

```bash
GET /api/books/forest-of-lost-stars?locale=fr
GET /api/books/forest-of-lost-stars?locale=nl
GET /api/books/forest-of-lost-stars?locale=en
```

Chaque endpoint doit retourner :

- bon title_template ;
- bonnes pages ;
- bonne langue ;
- même structure ;
- cover issue de `cover.default_image_path`.

## 11. Idempotence

Toutes les commandes doivent être relançables.

Règles :

- même entrée → même sortie ;
- pas de duplication SQL ;
- comparaison JSON normalisée avant écriture ;
- pas de suppression implicite ;
- pas de régénération image si fichier existe sauf `--force` ;
- pas d’appel IA en `--dry-run`.

## 12. Sécurité coût IA

Toujours faire :

1. dry-run ;
2. review prompt/payload ;
3. appel réel uniquement si GO.

Jamais :

- générer toutes les pages directement ;
- appeler Replicate sans debug payload ;
- lancer la génération réelle si prompt contient répétitions/incohérences.

## 13. Plan d’implémentation restant

Priorité immédiate :

1. corriger définitivement `CoverPromptBuilder` ;
2. obtenir un dry-run clean ;
3. lancer une vraie cover ;
4. vérifier visuellement la cover ;
5. si GO, implémenter génération page_1 seulement ;
6. valider cohérence style/hero ;
7. implémenter génération de toutes les pages ;
8. implémenter sync Sylius locale ;
9. vérifier catalogue local ;
10. vérifier BookFlip local.

## 14. Definition of Done technique

Le workflow pilote est considéré terminé si :

- `forest-of-lost-stars/master.json` existe ;
- les runtimes FR/NL/EN sont générés et valides ;
- cover générée via FLUX 2 Pro ;
- au moins les pages pilotes générées ;
- assets sauvegardés sous le bon chemin ;
- produit Sylius local créé/mis à jour ;
- API catalogue locale retourne le livre ;
- BookFlip affiche les pages ;
- session NL charge bien le runtime NL ;
- pas de duplication à la relance ;
- tests fonctionnels passants ;
- rapport final d’exécution disponible.
