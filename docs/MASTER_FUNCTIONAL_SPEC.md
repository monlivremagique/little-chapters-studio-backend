# MASTER_FUNCTIONAL_SPEC.md

# Mon Livre Magique — Workflow Admin de Création de Livre Premium

## 1. Finalité produit

L’objectif est de permettre à l’admin de créer un nouveau livre personnalisé premium à partir d’un simple briefing éditorial, puis d’obtenir un livre exploitable dans le catalogue local/prod avec :

- un produit Sylius correctement créé ou mis à jour ;
- un blueprint runtime compatible front/BookFlip ;
- des textes localisés FR/NL/EN ;
- des prompts image cohérents et premium ;
- une cover et des pages générées via Replicate ;
- des illustrations harmonisées ;
- une cohérence forte du héros sur toutes les pages ;
- une validation GO/NO-GO avant publication.

Le but business est simple : créer rapidement des livres personnalisés premium pour le Go Live Belgique, sans bricolage manuel fragile, avec une qualité suffisamment élevée pour provoquer l’effet :

> “Wow, c’est vraiment mon enfant dans un vrai livre premium.”

## 2. Rôle de l’utilisateur/admin

L’admin ne doit pas produire manuellement tout le livre.

Son rôle cible :

1. fournir un briefing clair ;
2. valider le blueprint éditorial ;
3. valider la cover ;
4. valider la cohérence des pages ;
5. publier le livre.

L’admin agit comme Creative Director / Publisher.

Le système doit gérer :

- génération du master blueprint ;
- validation du JSON ;
- projection FR/NL/EN ;
- génération images ;
- sync Sylius ;
- readiness BookFlip ;
- rapports QA.

## 3. Entrée fonctionnelle : briefing livre

À terme, l’entrée doit être un fichier court du type :

```yaml
title: La forêt des étoiles perdues
theme:
  - magie
  - courage
  - émerveillement
age: 4-7
story_subject: Un enfant aide des étoiles tombées à retrouver leur ciel.
main_emotion: émerveillement doux
learning_message: Même les petits gestes peuvent illuminer le monde.
languages:
  - fr
  - nl
  - en
page_count: 28
visual_style: premium watercolor storybook
constraints:
  - bedtime safe
  - no scary content
  - Belgian premium quality
  - coherent hero across pages
```

Pour l’exercice pilote actuel, le livre utilisé est :

- slug : `forest-of-lost-stars`
- FR : `La forêt des étoiles perdues`
- cible : 4–7 ans
- thème : magie douce, courage calme, étoiles perdues
- structure pilote : cover, dedication, 6 story pages, summary, backCover

## 4. Sortie fonctionnelle attendue

Le résultat final attendu est :

1. un livre présent dans Sylius ;
2. visible dans le catalogue local ;
3. sélectionnable par le client ;
4. compatible BookFlip ;
5. générant une preview personnalisée ;
6. affichant le texte dans la bonne langue ;
7. utilisant des images cohérentes ;
8. prêt à être testé en achat local ;
9. publiable en prod après validation.

## 5. Workflow fonctionnel cible

```text
Brief admin
  ↓
Claude 3.7 Sonnet via Replicate
  ↓
Master Blueprint V2
  ↓
QA éditoriale + correction
  ↓
Validation JSON
  ↓
Projection runtime FR/NL/EN
  ↓
Génération cover FLUX 2 Pro
  ↓
Validation humaine cover
  ↓
Génération pages FLUX 2 Pro
  ↓
Validation cohérence visuelle
  ↓
Sync Sylius
  ↓
Catalogue local
  ↓
BookFlip local
  ↓
GO / NO-GO publication
```

## 6. Règles métier clés

### 6.1 Le blueprint est la source business du livre

Le blueprint ne doit pas être un simple JSON technique. Il doit contenir :

- structure du livre ;
- textes localisés ;
- scènes ;
- prompts image ;
- hero bible ;
- visual bible ;
- assets attendus ;
- règles QA.

### 6.2 Séparation texte / visuel

Les textes sont localisés par langue.

Les prompts image restent principalement en anglais pour maximiser la qualité IA.

Règle :

```text
VISUAL = stable et partagé
TEXT = localisé FR/NL/EN
```

### 6.3 Un livre ne doit pas diverger selon la langue

FR, NL et EN doivent utiliser la même structure visuelle :

- mêmes pages ;
- mêmes scènes ;
- mêmes assets ;
- mêmes prompts visuels ;
- seuls les textes changent.

### 6.4 Cohérence héros obligatoire

Le héros doit rester cohérent :

- même âge perçu ;
- même visage ;
- même silhouette ;
- même tenue ;
- même énergie émotionnelle ;
- pas de changement brutal page à page.

Sans photo enfant, le prompt doit parler de `generic premium child hero`.

Avec photo enfant, le prompt peut parler de `personalized child hero based on the provided reference photo`.

### 6.5 Scènes illustrables au millimètre

Chaque scène doit être visuellement précise :

- caméra ;
- composition ;
- foreground ;
- midground ;
- background ;
- lighting ;
- emotion ;
- must_show ;
- must_not_show.

Le but est d’éviter des images IA jolies mais génériques.

### 6.6 La cover est le quality bar

On ne génère pas tout le livre tant que la cover n’est pas validée.

La cover doit être :

- premium ;
- émotionnelle ;
- lisible ;
- cohérente avec le livre ;
- compatible catalogue ;
- sans texte dans l’image ;
- sans logo/watermark ;
- suffisamment “wow” pour vendre le livre.

## 7. UX Admin cible

L’admin doit disposer d’un workflow simple :

1. créer ou déposer un brief ;
2. générer le master blueprint ;
3. consulter le rapport QA ;
4. corriger ou accepter ;
5. générer la cover en dry-run puis réel ;
6. valider ou régénérer la cover ;
7. générer les pages ;
8. consulter un rapport de cohérence ;
9. synchroniser en local ;
10. ouvrir le catalogue et BookFlip ;
11. publier uniquement si GO.

L’UX peut d’abord être en commandes Symfony, puis devenir une interface admin.

## 8. Critères GO / NO-GO

### GO technique

- master blueprint valide ;
- runtimes FR/NL/EN valides ;
- aucun fallback langue inattendu ;
- assets référencés existent ;
- produit Sylius créé ou mis à jour ;
- API catalogue retourne le bon livre ;
- BookFlip affiche les pages dans le bon ordre ;
- tests passants.

### GO éditorial

- histoire adaptée 4–7 ans ;
- texte simple, oral, musical ;
- pas trop adulte ;
- émotion claire ;
- promesse cohérente ;
- FR/NL/EN équivalents en sens.

### GO visuel

- cover wow ;
- pas de texte dans l’image ;
- enfant bien visible ;
- scène compréhensible immédiatement ;
- ambiance premium bedtime ;
- pas d’artefacts évidents ;
- pas de style trop générique IA.

### NO-GO automatique

- BookFlip cassé ;
- locale incorrecte ;
- blueprint invalide ;
- cover non premium ;
- scène répétée/contradictoire dans prompt ;
- payload Replicate incorrect ;
- image avec texte/logo/watermark ;
- héros incohérent ;
- sync Sylius duplique ou casse un livre existant.

## 9. Avancement connu au moment de cette spec

Déjà fait :

- spec Blueprint V2 ;
- propagation correcte de `bookLocale` FR/NL/EN ;
- fallback explicite FR ;
- `app:book:validate-blueprint` ;
- `app:book:generate-blueprint` ;
- livre pilote `forest-of-lost-stars/master.json` ;
- projection runtime FR/NL/EN ;
- `app:book:generate-cover` dry-run ;
- écriture des fichiers d’audit cover en dry-run.

En cours :

- finalisation de `CoverPromptBuilder` pour obtenir un prompt cover propre, court, non répétitif, sans incohérence photo/likeness.

Pas encore fait :

- vraie génération cover ;
- validation cover ;
- génération pages ;
- sync Sylius V2 ;
- affichage local catalogue ;
- test BookFlip réel ;
- généralisation briefing → Claude → master blueprint.

## 10. Objectif de fin de session

Ce soir, l’objectif prioritaire est :

1. finaliser le prompt cover ;
2. générer une vraie cover FLUX 2 Pro ;
3. obtenir une cover GO ;
4. générer au moins quelques pages pilotes ;
5. créer/synchroniser le livre dans Sylius local ;
6. voir le livre dans le catalogue local ;
7. vérifier que BookFlip lit le runtime blueprint.

La généralisation complète peut venir après, mais le workflow pilote doit être prouvé localement.
