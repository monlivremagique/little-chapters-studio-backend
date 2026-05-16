<?php

declare(strict_types=1);

namespace App\Error;

final class ErrorMessageMapper
{
    private const MAP = [
        // Generic tech fallbacks
        'default_400' => 'La requête n\'a pas pu être traitée. Veuillez vérifier les informations saisies.',
        'default_401' => 'Veuillez vous connecter pour accéder à cette page.',
        'default_403' => 'Vous n\'avez pas les droits nécessaires pour accéder à cette ressource.',
        'default_404' => 'La page demandée est introuvable.',
        'default_409' => 'Conflit lors du traitement de votre demande. Veuillez réessayer.',
        'default_422' => 'Certaines informations fournies ne sont pas valides.',
        'default_429' => 'Trop de requêtes. Veuillez patienter avant de réessayer.',
        'default_500' => 'Une erreur technique est survenue. Notre équipe a été notifiée.',
        'default_503' => 'Le service est temporairement indisponible. Veuillez réessayer dans quelques instants.',

        // Photo
        'photo_too_large' => 'La photo est trop volumineuse (maximum 10 Mo).',
        'photo_invalid_type' => 'Format de photo non supporté. Utilisez une image JPG ou PNG.',
        'photo_empty' => 'Aucune photo fournie. Veuillez sélectionner une photo.',
        'photo_encryption_failed' => 'La protection de votre photo a échoué. Veuillez réessayer.',
        'photo_not_found' => 'La photo demandée n\'est plus disponible.',
        'photo_access_denied' => 'Vous n\'avez pas accès à cette photo.',
        'photo_generic' => 'La photo n\'a pas pu être traitée. Veuillez réessayer avec une autre image.',

        // Generation / Preview
        'generation_incomplete' => 'Votre livre n\'a pas pu être entièrement généré. Veuillez réessayer.',
        'generation_failed' => 'La création de votre livre a été interrompue. Veuillez réessayer.',
        'generation_retry_limit' => 'Le nombre maximum de tentatives de génération a été atteint. Veuillez recommencer ou nous contacter.',
        'generation_not_ready' => 'La génération de votre livre n\'est pas encore terminée.',
        'preview_not_approved' => 'Le livre doit être approuvé avant de pouvoir continuer.',
        'preview_generic' => 'Un problème est survenu lors de la préparation de votre livre.',

        // Session
        'session_not_found' => 'Session introuvable. Veuillez recommencer votre personnalisation.',
        'session_expired' => 'Votre session a expiré. Veuillez recommencer.',
        'session_invalid_state' => 'Cette action n\'est pas possible pour l\'état actuel de votre livre.',
        'session_content_incomplete' => 'Veuillez remplir tous les champs requis avant de continuer.',
        'session_generic' => 'Un problème est survenu avec votre session de personnalisation.',

        // Cart / Checkout
        'cart_empty' => 'Votre panier est vide.',
        'cart_item_not_found' => 'Article introuvable dans votre panier.',
        'cart_attach_failed' => 'Le livre n\'a pas pu être ajouté à votre panier. Veuillez réessayer.',
        'checkout_incomplete' => 'Veuillez remplir toutes les informations de livraison.',
        'checkout_payment_failed' => 'Le paiement n\'a pas abouti. Votre compte n\'a pas été débité.',
        'checkout_payment_unavailable' => 'Le service de paiement est temporairement indisponible. Veuillez réessayer.',
        'checkout_session_expired' => 'Votre session de paiement a expiré. Veuillez recommencer.',
        'checkout_generic' => 'La commande n\'a pas pu être confirmée. Veuillez réessayer.',
        'stripe_error' => 'Le service de paiement est temporairement indisponible. Veuillez réessayer dans quelques instants.',

        // Fulfillment
        'fulfillment_not_found' => 'Les informations de livraison sont introuvables.',
        'fulfillment_generic' => 'Un problème est survenu lors de la préparation de votre commande.',

        // Order
        'order_not_found' => 'Commande introuvable.',
        'order_generic' => 'Un problème est survenu avec votre commande.',
        'project_not_found' => 'Projet introuvable.',

        // Account / Auth
        'auth_invalid_credentials' => 'Email ou mot de passe incorrect.',
        'auth_email_already_used' => 'Cet email est déjà associé à un compte.',
        'auth_registration_failed' => 'L\'inscription n\'a pas pu aboutir. Veuillez réessayer.',
        'auth_generic' => 'Un problème est survenu lors de la connexion. Veuillez réessayer.',

        // Catalog
        'catalog_book_not_found' => 'Livre introuvable.',
        'catalog_collection_not_found' => 'Collection introuvable.',
        'catalog_invalid_locale' => 'Cette langue n\'est pas disponible pour le moment.',
        'catalog_generic' => 'Le catalogue n\'a pas pu être chargé.',

        // PDF
        'pdf_not_found' => 'Le PDF demandé n\'est plus disponible.',
        'pdf_access_denied' => 'Vous n\'avez pas accès à ce document.',
        'pdf_generation_failed' => 'Le PDF n\'a pas pu être généré. Veuillez réessayer.',

        // Webhook / Alert
        'webhook_invalid_signature' => 'Signature de webhook invalide.',
        'webhook_generic' => 'Un problème est survenu lors du traitement d\'un événement externe.',

        // Admin
        'admin_project_not_found' => 'Projet introuvable.',
        'admin_generic' => 'Erreur lors du traitement de votre demande.',
    ];

    public function toPublicMessage(string $rawMessage, int $httpCode): string
    {
        // First try exact match against known patterns
        $normalized = mb_strtolower(trim($rawMessage));

        if (str_contains($normalized, 'not found') || str_contains($normalized, 'introuvable')) {
            return $httpCode === 404 ? self::MAP['default_404'] : self::MAP['default_404'];
        }
        if (str_contains($normalized, 'invalid') || str_contains($normalized, 'invalide')) {
            return $httpCode === 422 ? self::MAP['default_422'] : self::MAP['default_400'];
        }
        if (str_contains($normalized, 'forbidden') || str_contains($normalized, 'access denied')) {
            return self::MAP['default_403'];
        }
        if (str_contains($normalized, 'unauthorized')) {
            return self::MAP['default_401'];
        }
        if (str_contains($normalized, 'too many requests') || str_contains($normalized, 'trop de requêtes')) {
            return self::MAP['default_429'];
        }
        if (str_contains($normalized, 'conflict') || str_contains($normalized, 'conflit')) {
            return self::MAP['default_409'];
        }
        if (str_contains($normalized, 'stripe')) {
            return self::MAP['stripe_error'];
        }
        if (str_contains($normalized, 'gelato')) {
            return self::MAP['fulfillment_generic'];
        }
        if (str_contains($normalized, 'replicate')) {
            return self::MAP['generation_failed'];
        }

        // Keyword-based mapping for business-specific errors
        if (str_contains($normalized, 'photo') || str_contains($normalized, 'image')) {
            if (str_contains($normalized, 'size') || str_contains($normalized, 'large') || str_contains($normalized, 'poids')) {
                return self::MAP['photo_too_large'];
            }
            if (str_contains($normalized, 'type') || str_contains($normalized, 'format') || str_contains($normalized, 'mime')) {
                return self::MAP['photo_invalid_type'];
            }
            if (str_contains($normalized, 'encrypt') || str_contains($normalized, 'decrypt')) {
                return self::MAP['photo_encryption_failed'];
            }
            if (str_contains($normalized, 'empty') || str_contains($normalized, 'required') || str_contains($normalized, 'manquant')) {
                return self::MAP['photo_empty'];
            }
            return self::MAP['photo_generic'];
        }
        if (str_contains($normalized, 'preview') || str_contains($normalized, 'génération') || str_contains($normalized, 'generation') || str_contains($normalized, 'generate')) {
            if (str_contains($normalized, 'retry') || str_contains($normalized, 'limit')) {
                return self::MAP['generation_retry_limit'];
            }
            return self::MAP['generation_failed'];
        }
        if (str_contains($normalized, 'session')) {
            return self::MAP['session_generic'];
        }
        if (str_contains($normalized, 'cart') || str_contains($normalized, 'panier')) {
            return self::MAP['cart_attach_failed'];
        }
        if (str_contains($normalized, 'checkout') || str_contains($normalized, 'payment') || str_contains($normalized, 'paiement') || str_contains($normalized, 'commande')) {
            return self::MAP['checkout_payment_failed'];
        }
        if (str_contains($normalized, 'email')) {
            return self::MAP['auth_generic'];
        }

        return $this->defaultForCode($httpCode);
    }

    public function defaultForCode(int $httpCode): string
    {
        return match ($httpCode) {
            400 => self::MAP['default_400'],
            401 => self::MAP['default_401'],
            403 => self::MAP['default_403'],
            404 => self::MAP['default_404'],
            409 => self::MAP['default_409'],
            422 => self::MAP['default_422'],
            429 => self::MAP['default_429'],
            503 => self::MAP['default_503'],
            default => self::MAP['default_500'],
        };
    }
}
