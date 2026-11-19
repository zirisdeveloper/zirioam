-- =====================================================================
-- ZIRI OAM — Oracle Developer Access Manager
-- Script : upgrades/1.1.0.sql
-- Objet  : Script de mise à jour incrémentale v1.1.0
-- =====================================================================

BEGIN
    -- Ajout éventuel de nouvelles métadonnées de version
    UPDATE ZIRIOAM_OWNER.ZIRIOAM_INSTALLATION 
    SET PRODUCT_VERSION = '1.1.0', INSTALL_DATE = SYSTIMESTAMP 
    WHERE PRODUCT_CODE = 'ZIRIOAM_DEVELOPER_MANAGER';
    COMMIT;
EXCEPTION
    WHEN OTHERS THEN
        NULL;
END;
/

