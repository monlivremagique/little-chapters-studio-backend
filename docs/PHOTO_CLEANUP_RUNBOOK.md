# PHOTO_CLEANUP_RUNBOOK — Mon Livre Magique

## Objectif RGPD

Les photos d'enfants uploadées pour la personnalisation sont stockées dans :
`/srv/sylius/var/storage/personalizations/photos/` (volume Railway persistant).

La loi belge (RGPD Art.17) impose la suppression des données personnelles lorsque
la finalité de traitement est atteinte. La photo doit être supprimée une fois la
commande terminée et le PDF généré.

La commande `app:cleanup-personalization-photos` purge les photos soft-deleted
après la période de grâce configurée.

---

## Fonctionnement

1. Les photos sont **soft-deleted** (`deleted_at IS NOT NULL`) après approbation de la session
2. La commande purge les entrées DB + les fichiers physiques dont `deleted_at <= NOW() - grace_days`
3. L'opération est **irréversible** sur les fichiers — vérifier avant de lancer

---

## Commande Railway Terminal

```bash
# Depuis Railway Dashboard → Service mon-livre-magique-backend → Terminal

# Exécution standard (grace period 7 jours)
php bin/console app:cleanup-personalization-photos \
  --deleted-grace-days=7 \
  --env=prod

# Résultat attendu :
# [OK] Purged X soft-deleted personalization photo record(s) older than 7 day(s).

# Mode dry-run (compter sans supprimer) — à exécuter avant le premier passage
php bin/console doctrine:query:sql \
  "SELECT COUNT(*) FROM app_personalization_photo WHERE deleted_at IS NOT NULL AND deleted_at <= NOW() - INTERVAL '7 days'" \
  --env=prod
```

---

## Fréquence recommandée

| Fréquence | Grâce | Raison |
|---|---|---|
| **Hebdomadaire** (recommandé) | 7 jours | Confort client en cas de problème (PDF régénérable) |
| Quotidien | 3 jours | Si volume élevé de commandes |
| Mensuel | 30 jours | Minimum RGPD acceptable pour PME |

### Configurer un cron Railway (TODO)

Railway ne supporte pas nativement les crons sur un service existant.
Options :
1. Railway Cron Job (service séparé) — créer un service `cron` avec l'image PHP
2. GitHub Actions scheduled workflow qui appelle Railway Terminal via API
3. Externe : EasyCron, cron-job.org, Upstash QStash

---

## Validation

```bash
# Vérifier les photos restantes après purge
php bin/console doctrine:query:sql \
  "SELECT COUNT(*) AS total_photos,
          SUM(CASE WHEN deleted_at IS NOT NULL THEN 1 ELSE 0 END) AS soft_deleted,
          SUM(CASE WHEN deleted_at IS NOT NULL AND deleted_at <= NOW() - INTERVAL '7 days' THEN 1 ELSE 0 END) AS purgeable \
   FROM app_personalization_photo" \
  --env=prod
```

---

## Risques

| Risque | Mitigation |
|---|---|
| Suppression prématurée (PDF pas encore généré) | Le soft-delete intervient après `markPrintReady()` — PDF déjà créé |
| Perte irréversible | La grace period de 7 jours laisse une fenêtre de sécurité |
| Volume `/var/storage` plein | Surveiller Railway Dashboard → Volumes → taille utilisée |

---

## Registre RGPD

Documenter chaque exécution :
- Date d'exécution
- Nombre de photos purgées
- Responsible : ops@monlivremagique.be
