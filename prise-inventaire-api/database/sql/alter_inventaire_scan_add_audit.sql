-- Script pour ajouter les colonnes d'audit à INVENTAIRE_SCAN
-- Base de données : GESMAN2

-- Ajouter les colonnes de traçabilité
ALTER TABLE GESMAN2.INVENTAIRE_SCAN ADD (
    CREATED_AT TIMESTAMP DEFAULT SYSTIMESTAMP NOT NULL,
    UPDATED_AT TIMESTAMP DEFAULT SYSTIMESTAMP NOT NULL,
    DELETED_AT TIMESTAMP NULL
);

-- Créer un index sur DELETED_AT pour optimiser les requêtes de soft delete
CREATE INDEX IDX_INVENTAIRE_DELETED ON GESMAN2.INVENTAIRE_SCAN(DELETED_AT);

-- Commentaires sur les nouvelles colonnes
COMMENT ON COLUMN GESMAN2.INVENTAIRE_SCAN.CREATED_AT IS 'Date de création de l''enregistrement';
COMMENT ON COLUMN GESMAN2.INVENTAIRE_SCAN.UPDATED_AT IS 'Date de dernière modification';
COMMENT ON COLUMN GESMAN2.INVENTAIRE_SCAN.DELETED_AT IS 'Date de suppression logique (NULL si actif)';

-- Mettre à jour les enregistrements existants
UPDATE GESMAN2.INVENTAIRE_SCAN 
SET CREATED_AT = DATE_SAISIE, 
    UPDATED_AT = DATE_SAISIE 
WHERE CREATED_AT IS NULL;

COMMIT;
