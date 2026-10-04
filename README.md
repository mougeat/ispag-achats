# ISPAG Achats 🛒

Gestion des **achats fournisseurs** d'ISPAG : commandes, articles commandés, suivi des statuts et documents.
Ce plugin s'appuie sur **ISPAG Project Manager** (articles standard, prix, droits) et sur **ISPAG CRM** (fournisseurs et contacts).

## Fonctionnalités

### Commandes fournisseurs
- Liste des achats et détail d'une commande : articles regroupés, sous-articles, prix d'achat, remises, devises, dates de livraison.
- Création d'une commande à partir d'un projet (demande d'achat) et ajout de produits depuis le catalogue.
- Statuts de commande avec contrôle automatique et étape suivante (confirmation, expédition, livraison, facturation).
- Lien direct entre une commande d'achat et son projet de vente (« Vers achats » depuis le projet).

### Documents et e-mails
- **Bon de commande PDF** et documents associés, générés **dans la langue du fournisseur** (descriptifs de cuves compris).
- E-mails au fournisseur à partir de modèles par type et par langue (page « Email templates »), avec balises (`{PRODUCT_LIST}`, `{ORDER_REF}`, …) ; brouillon d'e-mail possible.
- Analyse de documents fournisseurs (offres, confirmations) pour pré-remplir les lignes.

### Montants automatiques
- **Transport** : ligne TRANS proposée pour les fournisseurs cochés, au tarif par tranche de 1000 L de cuves.
- **Dédouanement** : ligne DED proposée pour les commandes en EUR, selon le taux d'achat.
- Boutons « Appliquer » dans le bandeau d'alerte de la commande.

### Fournisseurs
- Informations d'achat (devise, TVA, langue, délais) et contacts (commande, plans, facturation, livraison) lus depuis la fiche entreprise du CRM.
- **Fournisseur par défaut** des lignes de soudure et d'isolation créées automatiquement depuis une cuve.
- Gestion des Carry Box (logistique).

## Réglages
Page **Purchase settings** (`ispag-achats-settings`, administrateurs) :
- montant du transport par 1000 L et taux de dédouanement des achats ;
- fournisseurs avec transport automatique ;
- fournisseurs par défaut pour la soudure et l'isolation.

Droits : voir l'écran « ISPAG Rights » d'ISPAG Project Manager (`view_supplier_order`, `edit_supplier_order`, `read_orders`).

## Mise à jour
Mise à jour automatique depuis GitHub (branche définie dans les réglages d'ISPAG Project Manager).
Traductions FR / DE dans `languages/`.

---
© 2026 ISPAG - Confidential - Internal Use Only
