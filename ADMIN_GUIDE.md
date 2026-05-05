# ADMIN_GUIDE — Little Chapters Studio

Guide d'administration Sylius : création de produits, blueprints, assets.

---

## Accès à l'admin

- URL : `http://localhost:8001/admin` (local) ou `https://<domaine>/admin` (prod)
- Identifiants créés lors des fixtures : voir `.env.local` ou les fixtures `little_chapters_phase2`
- Admin par défaut après seed : `admin@example.com` / `admin` (à changer en prod)

---

## Créer un livre (produit Sylius)

### 1. Créer le produit dans Sylius

1. Aller dans **Catalogue → Produits → Créer**
2. Remplir :
   - **Nom** : titre du livre (ex : "Thomas et l'aventure enchantée")
   - **Slug** : identifiant URL (ex : `aventure-enchantee`) — utilisé par le front pour `GET /api/books/{slug}`
   - **Canal** : sélectionner `Little Chapters`
   - **Taxon principal** : sélectionner la collection correspondante

### 2. Ajouter les attributs du livre

Dans l'onglet **Attributs** du produit, remplir :

| Attribut | Obligatoire | Valeur exemple |
|----------|-------------|----------------|
| `book_blueprint_json` | OUI | JSON complet (voir section suivante) |
| `book_subtitle` | non | "Une aventure magique rien que pour toi" |
| `book_age_min` | non | `3` |
| `book_age_max` | non | `8` |
| `book_theme` | non | `adventure` |
| `book_badge` | non | `Bestseller` |
| `book_personalization_level` | non | `full` |
| `book_format` | non | `200x200mm` |
| `book_cover_type` | non | `hardcover` |
| `book_language` | non | `fr` |
| `book_pages` | non | `24` |

### 3. Créer un variant et définir le prix

1. Onglet **Variants** → **Ajouter**
2. **Code** : ex. `AVENTURE-ENCHANTEE-FR`
3. Onglet **Tarification** : définir le prix par canal

### 4. Uploader les images par défaut

1. Onglet **Images** → **Ajouter**
2. Uploader l'image de couverture et les images de pages illustrées
3. Les chemins des images uploadées s'utilisent dans le blueprint (`default_image_path`)

---

## Blueprint JSON — structure complète

Le champ `book_blueprint_json` est la **source de vérité** de la structure du livre. Le front et le backend le consomment directement.

### Structure minimale

```json
{
  "version": 1,
  "title_template": "{child_name} et l'aventure enchantée",
  "negative_prompt_default": "blurry, distorted face, extra fingers, extra limbs, duplicate character, wrong eyes, bad anatomy, low detail",
  "style_rules": [
    "children's book illustration",
    "same child character across pages",
    "same visual style across all pages",
    "coherent palette"
  ],
  "pages": []
}
```

### Types de pages

Un livre doit contenir, dans cet ordre :

1. `cover` — couverture
2. `dedication` — dédicace (texte seul)
3. `page_1` … `page_n` — pages histoire
4. `summary` — résumé (texte seul)
5. `backCover` — quatrième de couverture (illustration seule)

### Exemple de blueprint complet

```json
{
  "version": 1,
  "title_template": "{child_name} et l'aventure enchantée",
  "negative_prompt_default": "blurry, distorted face, extra fingers, extra limbs, duplicate character, wrong eyes, bad anatomy",
  "style_rules": [
    "children's book illustration",
    "same child character across pages",
    "same visual style across all pages",
    "coherent warm palette"
  ],
  "pages": [
    {
      "id": "cover",
      "type": "cover",
      "title_template": "{child_name} et l'aventure enchantée",
      "text_template": null,
      "default_image_path": "/uploads/books/aventure-enchantee/cover-default.jpg",
      "prompt_template": "children's book cover, {child_name} entering a magical forest, warm light, detailed illustration",
      "negative_prompt": null,
      "personalizable": true
    },
    {
      "id": "dedication",
      "type": "dedication",
      "title_template": null,
      "text_template": "Pour {child_name}, avec tout notre amour.",
      "default_image_path": null,
      "prompt_template": null,
      "negative_prompt": null,
      "personalizable": false
    },
    {
      "id": "page_1",
      "type": "story",
      "title_template": null,
      "text_template": "Il était une fois {child_name}, un enfant courageux qui adorait explorer la forêt près de sa maison.",
      "default_image_path": "/uploads/books/aventure-enchantee/page1-default.jpg",
      "prompt_template": "children's book illustration, {child_name} walking into a magical forest, curious expression, detailed background",
      "negative_prompt": null,
      "personalizable": true
    },
    {
      "id": "summary",
      "type": "summary",
      "title_template": null,
      "text_template": "Dans ce livre, {child_name} a vécu une aventure inoubliable. Quelle sera la prochaine ?",
      "default_image_path": null,
      "prompt_template": null,
      "negative_prompt": null,
      "personalizable": false
    },
    {
      "id": "backCover",
      "type": "backCover",
      "title_template": null,
      "text_template": null,
      "default_image_path": "/uploads/books/aventure-enchantee/back-default.jpg",
      "prompt_template": "children's book back cover illustration, magical forest scene, warm sunset",
      "negative_prompt": null,
      "personalizable": true
    }
  ]
}
```

### Règles champ par champ

| Champ | Requis si | Notes |
|-------|-----------|-------|
| `id` | toujours | Identifiant stable de la page. Utilisé dans les artefacts. |
| `type` | toujours | `cover`, `dedication`, `story`, `summary`, `backCover` |
| `title_template` | couverture | Supporte `{child_name}` |
| `text_template` | pages texte | Supporte `{child_name}`, `{dedication}` |
| `default_image_path` | pages illustrées | Chemin public absolu (ex : `/uploads/...`) |
| `prompt_template` | pages illustrées | Supporte `{child_name}`. Envoyé à Replicate. |
| `negative_prompt` | optionnel | Override page du négatif global |
| `personalizable` | toujours | `true` = page générée par IA. `false` = texte seulement. |

---

## Synchroniser les blueprints

Après modification du blueprint dans l'admin Sylius :

```bash
docker compose exec php php bin/console app:sync-book-blueprints --no-interaction
```

Cette commande recharge les blueprints depuis `resources/book-blueprints/` (fichiers JSON sources) vers les attributs Sylius. À exécuter aussi si des fichiers JSON sources ont été modifiés directement.

---

## Gérer les collections (taxons)

Les collections correspondent aux taxons Sylius.

1. **Catalogue → Taxons → Créer**
2. Remplir :
   - **Nom** : "Aventure"
   - **Slug** : `aventure` — utilisé par `GET /api/collections/{slug}`
   - **Parent** : sélectionner le taxon racine `little_chapters`
3. Associer les produits à ce taxon via **Taxon principal** dans la fiche produit

---

## Uploader les assets (images par défaut)

Les images par défaut des pages doivent être uploadées dans Sylius et référencées dans le blueprint.

Les chemins sont de la forme `/uploads/books/{slug}/{nom-image}.jpg`.

Accès direct en local : `http://localhost:8001/uploads/books/...`

Pour uploader via l'admin : **Catalogue → Produits → [produit] → Images → Ajouter**.

---

## Gérer les clients

- **Clients → Clients** : liste des comptes
- **Clients → [client]** : modifier email, nom, adresses
- Réinitialisation de mot de passe : action disponible dans la fiche client

## Gérer les commandes

- **Commerce → Commandes** : liste avec filtres
- **Commerce → Commandes → [commande]** : détail, statut paiement, expédition
- L'`orderNumber` (ex : `#000001234`) est l'identifiant utilisé dans les endpoints support et fulfillment

---

## Compte admin par défaut (après seed)

Le seed `little_chapters_phase2` crée un admin. Identifier les identifiants dans :
`config/packages/little_chapters_phase2_fixtures.yaml` → section `admin_users`.

**Changer le mot de passe admin en production :**

```bash
docker compose exec php php bin/console sylius:admin:change-password admin@example.com newpassword
```
