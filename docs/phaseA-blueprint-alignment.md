# Phase A - Alignement JSON-driven

## Objectif

Aligner l'existant avec la decision finale:

- produit Sylius porte `book_blueprint_json`
- le backend catalogue expose ce blueprint
- les assets par defaut vivent cote backend

## Decisions appliquees

- `book_blueprint_json` est porte par un attribut produit Sylius dedie:
  - code: `book_blueprint_json`
  - type: `text`
- les blueprints sources sont versionnes dans `resources/book-blueprints/*.json`
- une commande Symfony synchronise ces blueprints vers les produits Sylius
- la meme commande genere les assets par defaut manquants dans `public/uploads/books/{slug}/`

## Commande

```bash
php bin/console app:sync-book-blueprints
```

## Donnees exposees

`GET /api/books/{slug}` retourne maintenant:

- toutes les cles catalogue deja stabilisees
- `bookBlueprint`

`bookBlueprint` contient:

- `version`
- `title_template`
- `negative_prompt_default`
- `style_rules`
- `pages`

## Limite volontaire de cette phase

- le front n'est pas encore pilote par le blueprint
- le viewer actuel n'est pas encore branche page par page sur ce JSON
- la generation actuelle n'est pas encore reconstruite autour du blueprint

Ces points sont reserves aux phases suivantes du plan mis a jour.
