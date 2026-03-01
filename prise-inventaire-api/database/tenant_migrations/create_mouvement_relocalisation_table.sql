-- Migration pour créer la table mouvement_relocalisation dans les bases tenant
-- À exécuter sur chaque base de données tenant

CREATE TABLE IF NOT EXISTS `mouvement_relocalisation` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `type` ENUM('arrivage', 'transfert', 'sortie', 'ajustement') NOT NULL,
    `produit_numero` VARCHAR(50) NOT NULL,
    `produit_nom` VARCHAR(255) NULL,
    `secteur_source` VARCHAR(100) NULL,
    `secteur_destination` VARCHAR(100) NULL,
    `quantite` DECIMAL(15, 4) NOT NULL,
    `unite_mesure` VARCHAR(20) NULL DEFAULT 'unité',
    `motif` VARCHAR(255) NULL,
    `employe` VARCHAR(100) NOT NULL,
    `date_mouvement` DATETIME NOT NULL,
    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX `idx_type` (`type`),
    INDEX `idx_produit_numero` (`produit_numero`),
    INDEX `idx_secteur_source` (`secteur_source`),
    INDEX `idx_secteur_destination` (`secteur_destination`),
    INDEX `idx_date_mouvement` (`date_mouvement`),
    INDEX `idx_employe` (`employe`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
