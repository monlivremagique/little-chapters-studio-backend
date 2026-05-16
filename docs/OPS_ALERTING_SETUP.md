# OPS_ALERTING_SETUP — Mon Livre Magique

## Architecture

`CriticalAlertDispatcher` (src/Support/CriticalAlertDispatcher.php) :
- Envoie un email via Symfony Mailer (Brevo SMTP)
- POSTe sur un webhook HTTP optionnel
- Enregistre l'alerte dans `app_operational_event`
- Silencieux sur ses propres erreurs (try/catch) — ne bloque jamais la pipeline

---

## Variables Railway requises

| Variable | Valeur attendue | Requis |
|---|---|---|
| `ALERT_EMAIL_TO` | `ops@monlivremagique.be` | Oui pour alertes email |
| `ALERT_EMAIL_FROM` | `noreply@monlivremagique.be` | Oui pour alertes email |
| `ALERT_WEBHOOK_URL` | URL HTTPS (Slack, Discord, PagerDuty…) | Optionnel |
| `MAILER_DSN` | `smtp://login:key@smtp-relay.brevo.com:587` | Déjà configuré |

**Si `ALERT_EMAIL_TO` ou `ALERT_EMAIL_FROM` est vide → alerte email silencieusement ignorée.**

---

## Configurer dans Railway

Railway Dashboard → Service `mon-livre-magique-backend` → Variables :

```
ALERT_EMAIL_TO=ops@monlivremagique.be
ALERT_EMAIL_FROM=noreply@monlivremagique.be
```

Pour un webhook Slack (exemple) :
```
ALERT_WEBHOOK_URL=https://hooks.slack.com/services/<TEAM_ID>/<CHANNEL_ID>/<TOKEN>
```
Remplacer `<TEAM_ID>/<CHANNEL_ID>/<TOKEN>` par les valeurs réelles depuis votre workspace Slack.

---

## Tester l'alerting

```bash
# Depuis Railway Terminal
php bin/console app:support:send-test-alert --env=prod

# Résultat attendu (si variables configurées) :
# [OK] Alert dispatched. Check ALERT_EMAIL_TO inbox and/or ALERT_WEBHOOK_URL logs.

# Résultat si variables absentes :
# [NOTE] If no email/webhook arrived: verify ALERT_EMAIL_TO, ALERT_EMAIL_FROM and MAILER_DSN
```

### Test local (avec MailHog)

```bash
# Docker local — MailHog reçoit les emails sur http://localhost:8026
docker compose exec php php bin/console app:support:send-test-alert --env=dev
# Ouvrir http://localhost:8026 → vérifier l'email de test
```

---

## Événements déclenchant une alerte critique

| Événement | Contexte |
|---|---|
| `pdf.render_failed` | Dompdf échoue à générer le PDF post-paiement |
| `gelato.submit_failed` | Soumission Gelato échoue |
| `production.skipped_missing_preview_version` | Session approuvée sans preview version |
| `test.manual_trigger` | Test manuel via commande |

---

## Consulter les alertes enregistrées

```bash
# Depuis Railway Terminal
php bin/console doctrine:query:sql \
  "SELECT event_type, level, created_at, order_number FROM app_operational_event \
   WHERE event_type LIKE 'alert.%' ORDER BY created_at DESC LIMIT 20" \
  --env=prod
```

---

## Validation complète

1. Railway vars `ALERT_EMAIL_TO` et `ALERT_EMAIL_FROM` configurées ✓
2. `php bin/console app:support:send-test-alert --env=prod` → `[OK]` ✓
3. Email reçu sur `ALERT_EMAIL_TO` ✓
4. (Optionnel) Message Slack/Discord reçu si `ALERT_WEBHOOK_URL` configurée ✓
