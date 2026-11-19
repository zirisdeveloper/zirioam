-- =====================================================================
-- ZIRI OAM — Oracle Developer Access Manager
-- Script : check_installation.sql
-- Objet  : Diagnostic complet de l'état d'installation et de validité
-- =====================================================================

SELECT 
    'INSTALLATION' AS CHECK_TYPE,
    PRODUCT_CODE AS ITEM_NAME,
    PRODUCT_VERSION AS ITEM_VERSION,
    INSTALL_STATUS AS STATUS,
    TO_CHAR(INSTALL_DATE, 'YYYY-MM-DD HH24:MI:SS') AS DETAILS
FROM (
    SELECT PRODUCT_CODE, PRODUCT_VERSION, INSTALL_STATUS, INSTALL_DATE FROM C##ZIRIOAM_ADMIN.ZIRIOAM_INSTALLATION
    UNION ALL
    SELECT PRODUCT_CODE, PRODUCT_VERSION, INSTALL_STATUS, INSTALL_DATE FROM ZIRIOAM_OWNER.ZIRIOAM_INSTALLATION
)
WHERE ROWNUM = 1;

