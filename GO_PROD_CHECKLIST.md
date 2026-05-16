# GO_PROD_CHECKLIST — Mon Livre Magique

Dernière mise à jour : 2026-05-14

---

## ❌ Blocages go-live (à régler avant d'accepter un paiement réel)

- [ ] **Stripe LIVE** : `STRIPE_SECRET_KEY=sk_live_...` + recréer webhook + `STRIPE_WEBHOOK_SECRET=whsec_live_...` dans Railway
- [ ] **Données légales réelles** : corriger dans `src/i18n/locales/*.json` → `legal.imprint.sections.publisher` (TVA, adresse, nom société, téléphone)
- [ ] **Banner cookies RGPD** : présent dans le code, nécessite `VITE_GTM_ID` pour activer GTM optionnel

---

## ✅ Validé en production

### Infrastructure
- [x] Railway déployé et VERT (BUILD + DEPLOYING + SUCCESS)
- [x] Vercel déployé et accessible sur www.monlivremagique.be
- [x] CORS configuré pour www.monlivremagique.be + *.lovableproject.com
- [x] Volumes Railway persistants (var/storage, public/media, config/jwt)
- [x] Brevo SMTP configuré (emails transactionnels)

### Catalogue
- [ ] **3 livres en catalogue — tous draft** : forest-of-lost-stars, ville-ecole, espace-robot ont `metadata.status: draft` → `enabled=false` → aucun livre visible publiquement. Générer les images, valider visuellement, setter `status=published`, relancer `app:sync-book-blueprints`.
- [ ] **espace-robot bloqué** : `claude-qa-report.json model=manual-craft` (GATE-6) + violation NL genre. Nécessite régénération complète via Replicate quand disponible.
- [x] Collections dynamiques depuis Sylius admin
- [x] Catalogue multilingue FR/EN/NL complet
- [x] API `/api/books?locale=nl` → titres NL ✓
- [x] Blueprint JSON par livre (fr_FR / en_US / nl_NL)
- [x] `app:diagnose-catalog-locales` retourne SUCCESS

### Paiement
- [x] Stripe Checkout Session fonctionnel (mode TEST)
- [x] Bancontact configuré (`payment_method_types: ['card', 'bancontact']`)
- [x] Webhook Stripe `/api/custom/payments/stripe/webhook` actif
- [x] Guard pre-paiement (session approved + panier cohérent)

### Génération IA
- [x] Replicate flux-2-pro configuré
- [x] Worker génération IA actif (supervisord)
- [x] Génération page par page implémentée
- [x] Preview viewer fonctionnel

### Fulfillment
- [x] Gelato intégré (`GelatoFulfillmentService`)
- [x] Webhook Gelato configuré (GET + secret)
- [x] PDF Dompdf post-paiement implémenté

### SEO & Frontend
- [x] react-helmet-async : titres/meta par page (FR/EN/NL)
- [x] sitemap.xml déployé
- [x] robots.txt avec Disallow et Sitemap
- [x] JSON-LD Product (fiche produit) + Organization (homepage)
- [x] Banner cookies RGPD implémenté (composant CookieConsent)
- [x] Code splitting React.lazy (bundle 775KB → 467KB)
- [x] OG image 1200×630 déployée

### Compte client & Suivi
- [x] Authentification JWT Sylius
- [x] Espace client (commandes, projets, personnalisations)
- [x] Tracking steps commande (5 étapes)

### Alerting & Ops
- [x] CriticalAlertDispatcher (email + webhook)
- [x] OperationalEventRecorder (trace métier)
- [x] Endpoint support `/api/custom/support/*`

---

## ⚠️ Haute priorité (avant premier client public)

- [ ] **Test end-to-end complet** : commande réelle Stripe TEST → PDF → Gelato sandbox
- [ ] **Vérifier ALERT_EMAIL_TO** : doit pointer vers une vraie boîte mail ops
- [ ] **GTM + GA4** : créer propriété GA4, insérer `VITE_GTM_ID` dans Vercel
- [ ] **Google Search Console** : ajouter domaine + soumettre sitemap.xml
- [ ] **Cron purge photos** : configurer `app:cleanup-personalization-photos` sur Railway

---

## 📋 Non-bloquant

- [ ] OG image brandée Mon Livre Magique (actuellement photo collection)
- [ ] CGV relue par juriste belge
- [ ] Test appareils mobiles réels (iOS/Android)
- [ ] Monitoring externe (Uptime Robot)
- [ ] Commande de validation Gelato : `php bin/console app:gelato:submit-validation-order`
- [ ] Valider qualité PDF Dompdf sur un vrai livre (Gelato accepte-t-il la résolution ?)
