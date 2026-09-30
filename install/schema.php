<?php
/**
 * Schéma de base de données — ISPAG Achats
 *
 * Généré à partir de la structure de production (export phpMyAdmin du 2026-09-29).
 * Chaque entrée est un CREATE TABLE IF NOT EXISTS : exécuté sans risque sur un site existant
 * (rien n'est modifié si la table existe déjà). {prefix} = $wpdb->prefix, {charset} = $wpdb->get_charset_collate().
 * Les tables sont classées pour que les clés étrangères pointent vers des tables déjà créées.
 */
defined('ABSPATH') || exit;

return [
    'achats_articles_cmd_fournisseurs' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}achats_articles_cmd_fournisseurs` (
  `Id` int NOT NULL AUTO_INCREMENT,
  `tri` int NOT NULL DEFAULT '0',
  `IdCommande` int NOT NULL,
  `IdArticleStandard` int DEFAULT NULL,
  `IdCommandeClient` int NOT NULL,
  `RefSurMesure` text NOT NULL,
  `DescSurMesure` text NOT NULL,
  `Qty` int NOT NULL,
  `Recu` int NOT NULL,
  `Facture` int NOT NULL,
  `UnitPrice` decimal(10,2) NOT NULL DEFAULT '0.00',
  `discount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `TimestampDateLivraison` int NOT NULL,
  `TimestampDateLivraisonConfirme` int NOT NULL,
  `archive` int DEFAULT NULL,
  PRIMARY KEY (`Id`)
) ENGINE=InnoDB {charset}
SQL
    ,
    'achats_articles_purchase' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}achats_articles_purchase` (
  `Id` int NOT NULL AUTO_INCREMENT,
  `article_id` int NOT NULL,
  `supplier_id` int NOT NULL,
  `supplier_reference` text NOT NULL,
  `supplier_description` text NOT NULL,
  `purchase_price` decimal(10,2) NOT NULL,
  `discount` decimal(10,0) NOT NULL DEFAULT '0',
  `currency` text NOT NULL,
  `delivery_days` mediumint NOT NULL,
  `proposal` int NOT NULL,
  PRIMARY KEY (`Id`)
) ENGINE=InnoDB {charset}
SQL
    ,
    'achats_articles_purchase_price_history' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}achats_articles_purchase_price_history` (
  `Id` int NOT NULL AUTO_INCREMENT,
  `purchase_id` int NOT NULL,
  `purchase_price` decimal(10,2) NOT NULL,
  `discount` decimal(10,0) NOT NULL DEFAULT '0',
  `currency` varchar(10) NOT NULL DEFAULT 'CHF',
  `valid_from` date NOT NULL,
  `valid_to` date DEFAULT NULL,
  `changed_by` int DEFAULT NULL,
  `note` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`Id`),
  KEY `idx_purchase_id` (`purchase_id`),
  KEY `idx_valid_range` (`purchase_id`,`valid_from`,`valid_to`)
) ENGINE=InnoDB {charset}
SQL
    ,
    'achats_commande_liste_fournisseurs' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}achats_commande_liste_fournisseurs` (
  `Id` int NOT NULL AUTO_INCREMENT,
  `hubspot_deal_id` text NOT NULL,
  `RefCommande` text NOT NULL,
  `NrCommande` text NOT NULL,
  `TimestampDateCreation` int NOT NULL,
  `TimestampDateReception` int NOT NULL,
  `TimestampDateReceptionConfirmee` int NOT NULL,
  `IdFournisseur` int NOT NULL,
  `delivery_number` varchar(255) DEFAULT NULL,
  `invoice_number` varchar(255) DEFAULT NULL,
  `Remarque` text NOT NULL,
  `ConfCmdFournisseur` text NOT NULL,
  `Total` decimal(10,0) NOT NULL,
  `EtatCommande` int NOT NULL,
  `is_manual` tinyint(1) NOT NULL DEFAULT '0',
  `Abonne` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` int NOT NULL,
  PRIMARY KEY (`Id`),
  KEY `idx_id` (`Id`)
) ENGINE=InnoDB {charset}
SQL
    ,
    'achats_etat_commandes_fournisseur' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}achats_etat_commandes_fournisseur` (
  `Id` int NOT NULL AUTO_INCREMENT,
  `steps` varchar(255) NOT NULL,
  `Etat` text NOT NULL,
  `ordre` int NOT NULL,
  `ClassCss` text NOT NULL,
  `is_automatic` tinyint(1) NOT NULL DEFAULT '0',
  `color` text NOT NULL,
  `ActionText` varchar(255) DEFAULT NULL,
  `JsHook` varchar(255) DEFAULT NULL,
  `allow_price_recalculation` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`Id`),
  KEY `idx_id` (`Id`)
) ENGINE=InnoDB {charset}
SQL
    ,
    'achats_fournisseurs' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `{prefix}achats_fournisseurs` (
  `Id` int NOT NULL AUTO_INCREMENT,
  `viag_id` bigint NOT NULL,
  `isSupplier` int NOT NULL,
  `isIngenieur` int NOT NULL,
  `Fournisseur` text NOT NULL,
  `IdContactCommande` int NOT NULL,
  `IdContactPlan` int NOT NULL,
  `IdContactFacturation` int NOT NULL DEFAULT '0',
  `IdContactLivraison` int NOT NULL DEFAULT '0',
  `TVA` text NOT NULL,
  `Mail` text NOT NULL,
  `compagnyDomain` text NOT NULL,
  `SupplierAdresse` text NOT NULL,
  `Ville` text NOT NULL,
  `CodePostal` text NOT NULL,
  `region` text NOT NULL,
  `Pays` text NOT NULL,
  `industry` enum('Installateur CVC','Ingenieur CVC') DEFAULT NULL,
  `NumTel` text NOT NULL,
  `Langue` text NOT NULL,
  `Monnaie` text NOT NULL,
  `deliveryDays` int NOT NULL,
  `TransportTime` int NOT NULL DEFAULT '0',
  `Image` text NOT NULL,
  PRIMARY KEY (`Id`),
  KEY `compagnydomain_idx` (`compagnyDomain`(100))
) ENGINE=InnoDB {charset}
SQL
    ,
];
