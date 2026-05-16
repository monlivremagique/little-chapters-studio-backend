# ADMIN_GUIDE — Sylius Admin + Pipeline Création Livre Blueprint V2

URL : https://backend.monlivremagique.be/admin/

---

## Connexion

Email : `sylius@example.com`  
Mot de passe : défini lors du déploiement initial.

---

## Catalogue

| Code Sylius | Slug | Titre FR | Âge |
|---|---|---|---|
| `BOOK_FOREST_OF_LOST_STARS` | `forest-of-lost-stars` | La Forêt des Étoiles Perdues | 4–7 |
| `BOOK_VILLE_ECOLE` | `ville-ecole` | Mon Grand Jour en Ville | 3–5 |
| `BOOK_ESPACE_ROBOT` | `espace-robot` | L'Astronaute et Son Robot | 8–10 |
| `BOOK_JARDIN_SOUVENIRS` | `jardin-des-souvenirs` | Le Jardin des Souvenirs Lumineux | 6–8 |
| `BOOK_LE-SECRET-DU-BOULANGER` | `le-secret-du-boulanger` | Le Secret du Boulanger | 3–7 |

Chaque livre = 10 pages · 3 locales (FR/EN/NL) · 9 images FLUX 2 Pro · `book_blueprint_v2` schema.

---

## Pipeline de création (12 étapes)

### Principe

1 commande = 1 modèle IA (ou 0). Claude et GPT ne sont jamais appelés dans la même commande. Le pipeline est 100% séquentiel.

### Les 12 étapes

| # | Commande | Modèle | Rôle |
|---|---|---|---|
| 1 | `app:book:validate-brief` | — | Vérifie le YAML |
| 2 | `app:book:generate-master-from-brief` | **Claude** | Écrit l'histoire complète → `master.json` V1 |
| 3 | `app:book:qa-correct-master` | **GPT** | Note + corrige le master → `master.json` V2 |
| 4 | `app:book:qa-correct-master` | **GPT** | Vérifie ses corrections → `master.json` V3 |
| 5 | `app:book:qa-gate` | — | Affiche les scores (informatif, ne bloque pas) |
| 6 | `app:book:validate-blueprint` | — | Valide le schéma du master |
| 7 | `app:book:generate-blueprint` | — | Crée les runtimes FR/NL/EN |
| 8 | `app:book:validate-blueprint --runtime` | — | Valide les runtimes |
| 9 | `app:book:create-from-blueprint --generate-images` | **FLUX** | Génère cover + dedication + 6 pages + summary + backCover |
| 10 | `app:book:check-assets` | — | Vérifie tous les PNG |
| 11 | `app:sync-book-blueprints` | — | Sync le catalogue Sylius |
| 12 | `app:book:verify-catalog` | — | Vérifie API + assets HTTP 200 |

### Lancer le pipeline complet

```bash
php bin/console app:book-factory:create-from-brief \
  resources/book-briefs/{slug}.yaml \
  --generate-images
```

Ou étape par étape :

```bash
# 1. Valider le brief
php bin/console app:book:validate-brief brief.yaml

# 2. Claude écrit l'histoire
php bin/console app:book:generate-master-from-brief --brief=brief.yaml

# 3. GPT corrige
php bin/console app:book:qa-correct-master --brief=brief.yaml --source=master.json

# 4. GPT vérifie ses corrections
php bin/console app:book:qa-correct-master --brief=brief.yaml --source=master.json

# 5-12. Étapes suivantes
php bin/console app:book:qa-gate blueprint-dir
php bin/console app:book:validate-blueprint --file=master.json
php bin/console app:book:generate-blueprint --source=master.json
php bin/console app:book:validate-blueprint --file=runtime.fr.json --runtime
php bin/console app:book:create-from-blueprint --generate-images --base-url=http://nginx
php bin/console app:book:check-assets blueprint-dir
php bin/console app:sync-book-blueprints
php bin/console app:book:verify-catalog {slug} --base-url=http://nginx
```

### Options

| Option | Commande | Description |
|---|---|---|
| `--generate-images` | `factory` / `create-from-blueprint` | Autorise FLUX à générer les images |
| `--dry-run` | `factory` / `generate-master-from-brief` / `qa-correct-master` | Écrit les prompts sans appeler Replicate |
| `--base-url` | `verify-catalog` / `create-from-blueprint` | URL de l'API locale (`http://nginx` dans Docker) |

---

## Modifier un livre existant

```bash
php bin/console app:book:generate-blueprint --source=resources/book-blueprints/{slug}/master.json
php bin/console app:sync-book-blueprints
php bin/console app:diagnose-catalog-locales
```

---

## Variables d'environnement

| Variable | Défaut | Description |
|---|---|---|
| `BOOK_MODEL` | `anthropic/claude-4-sonnet` | Modèle de génération du master |
| `QA_MODEL` | `openai/gpt-5.4` | Modèle de correction QA |
| `QA_GATE_MODE` | `balanced` | `balanced` / `strict` / `lenient` |
| `REPLICATE_MODEL` | `black-forest-labs/flux-2-pro` | Modèle de génération d'images |
| `REPLICATE_API_TOKEN` | requis | Token API Replicate |

---

## Résolution de problèmes

### La génération master échoue
- Vérifier `REPLICATE_API_TOKEN`
- Logs dans : `claude-master-debug.json`

### Le QA ne converge pas
- Le correctedMaster est validé par `BlueprintValidator` avant écriture
- La stagnation est détectée : si 5/7 scores ne changent pas de plus de 0.3, le cycle s'arrête
- `translationNaturalness` n'est pas bloquante pour la validation finale

### La génération d'images échoue
- `hero-reference.png` doit être généré en premier (portrait dédié)
- Logs dans : `generated-pages/*-debug.json`
- Timeout géré : 3 tentatives, 180s par prédiction

### Le port 8001 inaccessible
- Dans Docker : utiliser `--base-url http://nginx`
- Depuis l'hôte : le port 8001 est exposé par nginx

---

## Syncer un livre en production

```bash
# Après validation locale, pousser les assets :
git add resources/book-blueprints/{slug}/ public/uploads/books/{slug}/
git commit -m "feat(book): add {slug}"
git push

# Après déploiement Railway :
railway ssh -- php bin/console app:sync-catalog
```

⚠️ Ne jamais régénérer les images Replicate en production. Copier les artefacts existants.
