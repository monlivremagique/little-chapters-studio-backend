# GELATO_VALIDATION_RUNBOOK — Mon Livre Magique

## Objectif

Valider que la chaîne complète `PDF → Gelato` fonctionne en production avant le premier vrai client.

---

## Ce que teste la commande

`app:gelato:submit-validation-order` :
1. Crée une session de personnalisation fictive (`bookId=b1`, enfant "Nora")
2. Génère un PDF de validation (35 pages, PDF valide sans images Replicate)
3. Crée une commande Sylius fictive avec adresse belge
4. Soumet à Gelato via `GelatoFulfillmentService.submit()`
5. Récupère le statut Gelato via `GelatoClient.getOrder()`
6. Teste l'idempotence (double soumission → même `providerOrderId`)

---

## Résultat du test local (2026-05-07)

```
[OK] Gelato validation order submitted successfully.
orderNumber             : GLTVAL-8c4455
providerOrderId         : 0a22bb1e-6050-4251-b2b1-4dde6afe6996
providerStatus          : created
doubleSubmitOrderId     : 0a22bb1e-6050-4251-b2b1-4dde6afe6996  ← idempotence OK
```

**Code de la chaîne : fonctionnel.** Gelato a accepté la commande.

---

## Validation obligatoire en production Railway

Avant d'accepter un premier client payant, lancer depuis Railway Terminal :

```bash
# 1. Ouvrir Railway Dashboard → Service mon-livre-magique-backend → Terminal

# 2. Lancer la validation
php bin/console app:gelato:submit-validation-order \
  'https://backend.monlivremagique.be' \
  --email='votre-email@monlivremagique.be' \
  --child-name='Nora' \
  --env=prod

# Résultat attendu :
# [OK] Gelato validation order submitted successfully.
# providerOrderId : xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
# providerStatus  : created (ou accepted, pending)
```

### Ce qu'il faut vérifier ensuite

1. **Dashboard Gelato** → `orders.gelatoapis.com` → chercher la commande par `orderNumber` `GLTVAL-xxxxxx`
2. Vérifier que la commande n'est pas en erreur (`rejected`, `failed`)
3. Vérifier que le PDF a été téléchargé par Gelato (logs Gelato ou HTTP access logs Railway)
4. **Ne pas attendre la livraison** — annuler la commande depuis le dashboard Gelato si elle est en production sandbox

---

## Que faire si la commande est rejetée par Gelato

| Erreur | Cause probable | Action |
|---|---|---|
| `GELATO_API_KEY not configured` | Variable Railway manquante | Ajouter `GELATO_API_KEY` dans Railway vars |
| PDF invalide | Dompdf génère un PDF non conforme | Tester avec un vrai PDF Dompdf d'une session réelle |
| Produit UID inconnu | `GELATO_PRODUCT_UID` incorrect | Vérifier dans dashboard Gelato → Products |
| Adresse invalide | Validation adresse Gelato échoue | Vérifier le format de l'adresse belge |

---

## Test du PDF Dompdf réel (optionnel mais recommandé)

Pour valider que le PDF généré par Dompdf est accepté par Gelato (pas seulement le PDF minimal de validation) :

```bash
# 1. Créer une vraie session approuvée en test Stripe
# 2. Récupérer l'ID de session via API support
# 3. Déclencher manuellement le PDF :
php bin/console app:personalization:render-pdf --session-id=SESSION_ID --env=prod
# 4. Récupérer l'URL du PDF et soumettre manuellement à Gelato sandbox
```

---

## Webhook Gelato (validation retour)

Pour tester le webhook de retour Gelato :

```bash
# Simuler un webhook Gelato (remplacer SECRET et ORDER_NUMBER)
curl -X GET \
  "https://backend.monlivremagique.be/api/custom/fulfillment/gelato/webhook?secret=SECRET&orderNumber=GLTVAL-xxxxx&status=in_production"
# Réponse attendue : {"received":true}
```

---

## Résumé check GO LIVE Gelato

- [ ] `app:gelato:submit-validation-order` lancé sur Railway prod → `[OK]`
- [ ] `providerOrderId` visible dans dashboard Gelato
- [ ] Commande non rejetée par Gelato
- [ ] Webhook Gelato répond 200
- [ ] Commande de validation annulée depuis dashboard Gelato
</content>
</invoke>