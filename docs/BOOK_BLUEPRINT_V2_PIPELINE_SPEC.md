# BOOK_BLUEPRINT_V2_PIPELINE_SPEC

## 1. Objet

Definir la spec technique du pipeline `Book Blueprint V2` avant implementation, sans casser le contrat actuel expose au front/BookFlip.

Perimetre:
- pas de migration
- pas de nouvelle commande codee
- pas d'appel Replicate
- pas de refactor front/back
- doc uniquement

## 2. Etat Actuel Observe

### Backend Symfony/Sylius

- Produit Sylius: `src/Entity/Product/Product.php`
- Traductions produit: `src/Entity/Product/ProductTranslation.php`
- Attributs produit: `src/Entity/Product/ProductAttribute.php`
- Valeurs d'attribut: `src/Entity/Product/ProductAttributeValue.php`
- Images produit: `src/Entity/Product/ProductImage.php`
- API catalogue front: `src/Controller/FrontCatalogController.php`
- Projection catalogue front: `src/FrontCatalog/FrontCatalogProvider.php`
- Sync blueprint fichier -> Sylius: `src/Command/SyncBookBlueprintsCommand.php`
- Diagnostic catalogue/locales: `src/Command/DiagnoseCatalogLocalesCommand.php`
- Pipeline preview/generation: `src/Controller/PersonalizationSessionController.php`, `src/Personalization/PersonalizationPreviewGenerator.php`, `src/Personalization/PreviewVersionFactory.php`

### Source de verite actuelle

Il y a en pratique 2 niveaux de verite:

1. Source build locale: `resources/book-blueprints/*.json`
2. Source runtime front: attribut Sylius traduit `book_blueprint_json` stocke dans `sylius_product_attribute_value.text_value`

Le runtime front ne lit pas les fichiers `resources/book-blueprints/*.json` directement. Il lit toujours la valeur Sylius via `FrontCatalogProvider`.

### Mapping Sylius actuel

| Domaine | Stockage | Observation |
|---|---|---|
| Produit | `sylius_product` | entite `Product` |
| Slug, nom, short description | `sylius_product_translation` | lookup par slug FR stable, fallback FR |
| Blueprint | `sylius_product_attribute_value` | attribut traduit `book_blueprint_json` |
| Textes marketing | `sylius_product_attribute_value` | `book_subtitle`, `book_description`, `book_long_description`, etc. |
| Media produit fallback | `sylius_product_image` | 1ere image utilisee si pas de cover dans blueprint |
| Collections | `sylius_taxon` + translations + images | hors blueprint, mais expose au front |

### Locales actuelles

- API front accepte `fr`, `en`, `nl`
- Mapping interne Sylius: `fr_FR`, `en_US`, `nl_NL`
- `FrontCatalogProvider` applique la priorite: locale demandee > fallback `fr_FR` > attribut sans locale
- `availableLocales` est derive des lignes `book_blueprint_json` existantes

## 3. Contrat Exact Observe Avec Le Front / BookFlip

Note: le code front/BookFlip n'est pas present dans ce repo. La consommation est deduite du contrat backend expose et des payloads reconstruits cote personnalisation.

### Entree catalogue

Endpoint principal:

- `GET /api/books/{slug}?locale=fr|en|nl`

Le backend retourne `bookBlueprint` brut dans la payload livre, par exemple:

```json
{
  "bookBlueprint": {
    "version": 1,
    "title_template": "{child_name} et l'aventure enchantee",
    "negative_prompt_default": "...",
    "style_rules": ["..."],
    "pages": [
      {
        "id": "cover",
        "type": "cover",
        "title_template": "...",
        "text_template": null,
        "default_image_path": "/uploads/books/aventure-enchantee/cover-default.svg",
        "prompt_template": "...",
        "negative_prompt": null,
        "personalizable": true,
        "aspect_ratio": "3:4"
      }
    ]
  }
}
```

### Champs effectivement consommes aujourd'hui

#### Par le catalogue

- `bookBlueprint.pages[]`
- page `id=cover`
- `cover.default_image_path` pour construire `coverImage`

#### Par le pipeline preview/generation

- racine: `version`, `title_template`, `negative_prompt_default`, `style_rules`, `pages`
- page: `id`, `type`, `title_template`, `text_template`, `default_image_path`, `prompt_template`, `negative_prompt`, `personalizable`, `aspect_ratio`, optionnellement `page_number` / `pageNumber`

### Semantique actuelle des types de page

- `cover`: utilise pour preview + generation
- `story`: utilise pour preview + generation
- `backCover`: utilise pour preview + generation
- `dedication`: utilise pour preview, pas pour generation IA
- `summary`: utilise pour preview, pas pour generation IA

### Contrat preview reconstruit pour BookFlip

Endpoint:

- `GET /api/personalization/sessions/{id}/preview`

Le backend reconstruit une liste `pages` dans ce format:

```json
{
  "pages": [
    {
      "id": "cover",
      "type": "cover",
      "pageNumber": 1,
      "imageUrl": "https://...",
      "isPersonalized": true,
      "label": "Couverture",
      "title": "...",
      "text": null
    }
  ]
}
```

Conclusion: le front/BookFlip depend aujourd'hui surtout de la projection `pages[]` ordonnee et des champs texte/image calcules depuis `bookBlueprint`.

## 4. Contraintes De Compatibilite V2

Pour rester compatible:

1. Conserver au runtime les cles deja lues aujourd'hui:
   - `version`
   - `title_template`
   - `negative_prompt_default`
   - `style_rules`
   - `pages`
2. Conserver la structure actuelle de `pages[]`
3. Ne pas renommer les `type` de page existants
4. Ne pas remplacer `default_image_path` par un autre champ au runtime
5. Ne pas supprimer le modele 1 blueprint par locale dans `book_blueprint_json`
6. Toute donnee V2 additionnelle doit etre additive et ignorable par le code actuel

## 5. Structure Cible Proposee

### Decision de design

Le V2 doit distinguer:

1. `Master Blueprint V2` d'authoring/build, multilingue
2. `Runtime Blueprint V2 localise` projete dans Sylius par locale, compatible V1

Cette separation evite de casser le runtime actuel tout en permettant une source multilingue riche.

### 5.1 Master Blueprint V2 (source build)

Format cible propose:

```json
{
  "schema": "book_blueprint_v2",
  "schemaVersion": 2,
  "metadata": {
    "bookId": "b1",
    "slug": "aventure-enchantee",
    "productCode": "BOOK_AVENTURE_ENCHANTEE",
    "version": 2,
    "status": "draft",
    "sourceLocale": "fr",
    "supportedLocales": ["fr", "en", "nl"],
    "pageCount": 8,
    "generationPageCount": 6
  },
  "locales": {
    "fr": {
      "book": {
        "title_template": "{child_name} et l'aventure enchantee"
      },
      "pages": {
        "cover": {
          "title_template": "{child_name} et l'aventure enchantee"
        },
        "dedication": {
          "text_template": "Pour {child_name}, ..."
        }
      }
    },
    "en": {
      "book": {
        "title_template": "{child_name} and the enchanted adventure"
      },
      "pages": {}
    },
    "nl": {
      "book": {
        "title_template": "{child_name} en het betoverde avontuur"
      },
      "pages": {}
    }
  },
  "visualBible": {
    "style_rules": [
      "children's book illustration",
      "same child character across pages"
    ],
    "palette": ["#F4A261", "#457B9D"],
    "lighting": "warm premium storybook mood",
    "compositionRules": [
      "keep the child readable",
      "avoid cluttered backgrounds"
    ]
  },
  "heroBible": {
    "ageRange": "3-7",
    "identityRules": [
      "same face across pages",
      "same age perception across pages"
    ],
    "forbiddenDrift": [
      "different hair length",
      "different face shape"
    ]
  },
  "sceneDefinitions": [
    {
      "id": "cover",
      "type": "cover",
      "pageNumber": 1,
      "personalizable": true,
      "generateImage": true,
      "aspectRatio": "3:4",
      "promptTemplate": "children's book cover showing {child_name} entering a magical forest",
      "negativePrompt": null,
      "assetKey": "cover-default"
    },
    {
      "id": "dedication",
      "type": "dedication",
      "pageNumber": 2,
      "personalizable": true,
      "generateImage": false,
      "assetKey": "dedication-default"
    }
  ],
  "imageGeneration": {
    "provider": "replicate",
    "modelStrategy": "default",
    "negativePromptDefault": "blurry, distorted face, extra fingers",
    "resolution": "1 MP",
    "outputFormat": "png",
    "inputImages": {
      "pageReference": true,
      "childPhoto": true
    }
  },
  "assets": {
    "basePublicPath": "/uploads/books/aventure-enchantee",
    "defaults": {
      "cover-default": "/uploads/books/aventure-enchantee/cover-default.svg",
      "dedication-default": "/uploads/books/aventure-enchantee/dedication-default.svg",
      "summary-default": "/uploads/books/aventure-enchantee/summary-default.svg"
    }
  },
  "qa": {
    "requiredPageTypes": ["cover", "dedication", "summary", "backCover"],
    "requiredLocales": ["fr", "en", "nl"],
    "placeholderPolicy": ["child_name"],
    "rules": [
      "every generated page must have promptTemplate",
      "every page must have default asset",
      "locale page ids must be identical across locales"
    ]
  }
}
```

### 5.2 Runtime Blueprint V2 localise (stocke dans `book_blueprint_json`)

Projection cible par locale, compatible avec l'existant:

```json
{
  "version": 2,
  "title_template": "{child_name} et l'aventure enchantee",
  "negative_prompt_default": "blurry, distorted face, extra fingers",
  "style_rules": [
    "children's book illustration",
    "same child character across pages"
  ],
  "metadata": {
    "schema": "book_blueprint_v2",
    "schemaVersion": 2,
    "bookId": "b1",
    "slug": "aventure-enchantee",
    "locale": "fr"
  },
  "visualBible": {
    "palette": ["#F4A261", "#457B9D"]
  },
  "heroBible": {
    "identityRules": ["same face across pages"]
  },
  "imageGeneration": {
    "resolution": "1 MP",
    "outputFormat": "png"
  },
  "assets": {
    "basePublicPath": "/uploads/books/aventure-enchantee"
  },
  "qa": {
    "requiredLocales": ["fr", "en", "nl"]
  },
  "pages": [
    {
      "id": "cover",
      "type": "cover",
      "title_template": "{child_name} et l'aventure enchantee",
      "text_template": null,
      "default_image_path": "/uploads/books/aventure-enchantee/cover-default.svg",
      "prompt_template": "children's book cover showing {child_name} entering a magical forest",
      "negative_prompt": null,
      "personalizable": true,
      "aspect_ratio": "3:4",
      "page_number": 1,
      "scene_key": "cover"
    }
  ]
}
```

Principe: le runtime garde les champs V1 indispensables et ajoute des blocs V2 non cassants.

## 6. Mapping Sylius Cible

### Stockage maintenu

- `book_blueprint_json` reste l'unite runtime lue par le front
- 1 valeur par produit x locale (`fr_FR`, `en_US`, `nl_NL`)
- stockage en JSON texte dans `sylius_product_attribute_value.text_value`

### Mapping recommande

| Donnee V2 | Source cible | Destination runtime |
|---|---|---|
| `metadata.slug`, `metadata.bookId` | master V2 | copie dans `book_blueprint_json.metadata` |
| `locales.{locale}.book.title_template` | master V2 | `title_template` racine |
| `visualBible.style_rules` | master V2 | `style_rules` racine |
| `imageGeneration.negativePromptDefault` | master V2 | `negative_prompt_default` racine |
| `sceneDefinitions[*].pageNumber` | master V2 | `pages[*].page_number` |
| `sceneDefinitions[*].promptTemplate` | master V2 | `pages[*].prompt_template` |
| `sceneDefinitions[*].assetKey` + `assets.defaults` | master V2 | `pages[*].default_image_path` |
| `locales.{locale}.pages.{id}.title_template` | master V2 | `pages[*].title_template` |
| `locales.{locale}.pages.{id}.text_template` | master V2 | `pages[*].text_template` |

### Attributs Sylius hors blueprint concernes

Le pipeline V2 ne remplace pas ces attributs front existants:

- `book_subtitle`
- `book_description`
- `book_long_description`
- `book_emotional_promise`
- `book_features`
- `book_reviews_json`
- `book_badge`
- `book_pages`
- `book_format`
- `book_cover_type`
- `book_language`
- `book_personalization_level`
- `book_theme`

La spec V2 suppose un mapping editorial clair entre `metadata/pageCount` et `book_pages`, mais sans changer le stockage actuel.

## 7. Mapping Front / BookFlip

### Flux cible conserve

1. BookFlip charge le livre via `GET /api/books/{slug}?locale={fr|en|nl}`
2. Le backend expose `bookBlueprint` localise depuis Sylius
3. Le front cree une session de personnalisation avec `bookLocale`
4. Le backend reconstruit le preview depuis `bookBlueprint.pages[]`
5. BookFlip affiche les pages du preview via `GET /api/personalization/sessions/{id}/preview`

### Champs runtime que BookFlip peut continuer a utiliser sans changement

- `bookBlueprint.version`
- `bookBlueprint.title_template`
- `bookBlueprint.pages[]`
- `page.id`
- `page.type`
- `page.text_template`
- `page.default_image_path`

### Champs V2 additionnels accessibles sans impact

- `bookBlueprint.metadata`
- `bookBlueprint.visualBible`
- `bookBlueprint.heroBible`
- `bookBlueprint.imageGeneration`
- `bookBlueprint.assets`
- `bookBlueprint.qa`
- `page.scene_key`

## 8. Commandes Symfony

### `app:book:validate-blueprint`

Statut:
- implementee

But:
- valider un master blueprint V2 ou un blueprint runtime localise
- verifier schema, locales, assets, placeholders, compatibilite runtime

Entrees:
- `--file=/path/to/file.json` obligatoire
- `--runtime` pour valider le JSON cible Sylius

Sorties:
- code `0` si valide
- code `1` si invalide
- rapport console:
  - erreurs schema
  - pages manquantes
  - placeholders inconnus
  - assets manquants
  - regressions de compatibilite V1/V2

Idempotence:
- lecture seule, aucun effet de bord

Exemples d'usage:

```bash
# Valider un master Blueprint V2
docker compose exec -T php php bin/console app:book:validate-blueprint --file=tests/Fixtures/book-blueprints/master-valid.json

# Valider un runtime Blueprint localise
docker compose exec -T php php bin/console app:book:validate-blueprint --runtime --file=tests/Fixtures/book-blueprints/runtime-valid.json
```

### `app:book:generate-blueprint`

Statut:
- implementee

But:
- projeter un master blueprint V2 multilingue en blueprints runtime localises compatibles Sylius

Entrees:
- `--source=/path/master.json` obligatoire
- `--output-dir=/path` obligatoire
- `--locales=fr,en,nl` optionnel, defaut `fr,en,nl`
- `--dry-run`

Sorties:
- fichiers JSON runtime localises par locale
- rapport console avec:
  - locales generees
  - pages generees
  - assets
  - warnings QA

Idempotence:
- meme entree => meme sortie byte a byte apres normalisation JSON
- ordre des cles stable
- ordre des pages stable
- aucun enrichissement implicite non deterministe

Exemples d'usage:

```bash
# Generer les runtimes FR/EN/NL
docker compose exec -T php php bin/console app:book:generate-blueprint --source=tests/Fixtures/book-blueprints/master-valid.json --output-dir=/tmp/book-blueprints

# Generer uniquement FR/NL sans ecriture
docker compose exec -T php php bin/console app:book:generate-blueprint --source=tests/Fixtures/book-blueprints/master-valid.json --output-dir=/tmp/book-blueprints --locales=fr,nl --dry-run
```

### `app:book:build`

Statut:
- non implementee

But:
- orchestrer validation + projection + sync Sylius + verification post-build

Entrees:
- argument `slug` optionnel, ou `--all`
- `--locales=fr,en,nl`
- `--dry-run`
- `--no-sync` pour ne pas ecrire en base
- `--strict`

Sorties:
- projection runtime par locale
- ecriture des `book_blueprint_json` Sylius si `--no-sync` absent
- verification finale des assets requis
- resume console des produits/locales syncs

Idempotence:
- relancer plusieurs fois ne doit pas dupliquer de lignes ni modifier un JSON deja equivalent
- comparer avant ecriture le JSON normalise courant vs cible
- ne pas recrer un asset si le fichier existe deja et correspond au chemin attendu

## 9. Regles D'Idempotence

1. Slug stable = identifiant principal du livre
2. `sceneDefinitions[].id` stable dans le temps
3. `page_number` derive de l'ordre des scenes, pas d'un index implicite variable
4. projection locale deterministe
5. encodage JSON deterministe
6. aucune dependance a l'ordre d'insertion SQL
7. aucune regeneration d'asset par defaut si deja present et valide
8. aucune suppression implicite de cles inconnues lors d'une simple validation

Commande de test:

```bash
docker compose exec -T php php vendor/bin/phpunit tests/Functional/Command/ValidateBlueprintCommandTest.php
docker compose exec -T php php vendor/bin/phpunit tests/Functional/Command/GenerateBlueprintCommandTest.php
```

## 10. Risques De Compatibilite Avec BookFlip

### Risques majeurs

1. Supprimer/renommer `pages` casserait le catalogue, la preview et la generation
2. Supprimer `default_image_path` casserait la cover catalogue et les references d'image de generation
3. Renommer les `type` existants (`cover`, `story`, `dedication`, `summary`, `backCover`) casserait la logique preview/generation
4. Changer le format des templates (`{child_name}`) casserait la compilation des textes/titres

### Risques moderes

1. Changer l'ordre des pages modifierait `pageNumber` et donc l'ordre BookFlip
2. Injecter un blueprint multilingue unique brut dans `book_blueprint_json` casserait le code actuel qui attend un blueprint deja localise
3. Passer d'assets relatifs a absolus dans `default_image_path` pourrait casser les usages backend sur filesystem local

### Decision technique appliquee

Le risque critique locale a ete corrige avant Blueprint V2 avec la regle suivante:

- `PersonalizationSession` expose une locale resolue unique via `getResolvedBookLocale()`
- seules `fr`, `en`, `nl` sont acceptees comme locales explicites
- toute locale absente ou invalide retombe explicitement sur `fr`
- tout chargement du livre pilotant un blueprint dans la chaine personnalisation doit utiliser cette locale resolue

Points alignes:

- `PersonalizationPreviewGenerator`
- `PreviewVersionFactory`
- `PersonalizationSessionController` pour la lecture preview

Conclusion: la generation, le snapshot approuve et la preview lisent desormais tous le meme blueprint localise, avec fallback explicite FR.

## 11. Plan D'Implementation Recommande

1. Figer le schema `Master Blueprint V2`
2. Figer le schema `Runtime Blueprint V2 localise`
3. Ecrire le validateur de schema + compatibilite V1
4. Ecrire le projecteur master -> runtime par locale
5. Ecrire la sync runtime -> `book_blueprint_json` Sylius
6. Brancher la build command orchestration
7. Maintenir l'usage de la locale resolue `bookLocale` dans toute la chaine preview/generation
8. Tester sur 1 livre pilote
9. Generaliser aux 5 livres
10. Activer le build V2 dans le flux catalogue existant seulement apres validation manuelle

## 12. Points A Valider Manuellement

1. `GET /api/books/{slug}?locale=fr|en|nl` retourne bien un blueprint localise avec `pages[]` intact
2. `availableLocales` reste `['fr','en','nl']`
3. la cover catalogue continue a venir de la page `cover.default_image_path`
4. `POST /api/personalization/sessions` avec `bookLocale=en|nl` pilote bien la preview dans la bonne langue
5. `GET /api/personalization/sessions/{id}/preview` garde le meme contrat `pages[]`
6. chaque page `dedication` et `summary` a toujours un `default_image_path`
7. tous les assets references existent physiquement sous `public/uploads/books/{slug}/`
8. les placeholders obligatoires sont coherents sur les 3 locales
9. `book_pages` Sylius reste coherent avec le nombre editorial attendu

## 13. Decision Finale Recommandee

Recommendation:

- introduire un `Master Blueprint V2` multilingue comme source d'authoring
- continuer a projeter vers un `book_blueprint_json` localise, compatible V1, pour le runtime front/BookFlip
- considerer `book_blueprint_json` comme contrat runtime stable a court terme
- ne faire evoluer BookFlip qu'apres une phase transitoire ou V2 est purement additif

Cette approche est la plus sure pour etendre le pipeline sans casser l'existant.
