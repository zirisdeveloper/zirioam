-- =====================================================================
-- ZIRI OAM — Oracle Developer Access Manager
-- Script : 007_initial_data.sql
-- Cible  : PDB Locale (Schéma ZIRIOAM_OWNER)
-- Objet  : Enregistrement de l'état d'installation et paramètres par défaut
-- Idempotent : Oui
-- =====================================================================

BEGIN
    -- 1. Enregistrement de l'état d'installation PDB
    MERGE INTO ZIRIOAM_OWNER.ZIRIOAM_INSTALLATION dest
    USING (
        SELECT 'ZIRIOAM_DEVELOPER_MANAGER' AS product_code,
               '1.0.0' AS product_version,
               'INSTALLED' AS install_status,
               SYS_CONTEXT('USERENV', 'CON_NAME') AS pdb_name
        FROM DUAL
    ) src
    ON (dest.PRODUCT_CODE = src.product_code)
    WHEN MATCHED THEN
        UPDATE SET dest.INSTALL_STATUS = src.install_status,
                   dest.PRODUCT_VERSION = src.product_version,
                   dest.INSTALL_DATE = SYSTIMESTAMP
    WHEN NOT MATCHED THEN
        INSERT (PRODUCT_CODE, PRODUCT_VERSION, INSTALL_DATE, INSTALL_STATUS, PDB_NAME)
        VALUES (src.product_code, src.product_version, SYSTIMESTAMP, src.install_status, src.pdb_name);

    -- 2. Paramètres par défaut
    MERGE INTO ZIRIOAM_OWNER.ZIRIOAM_SETTINGS dest
    USING (
        SELECT 'AUTO_GRANT_SESSION' AS k, 'Y' AS v, 'Octroi automatique du droit session PDB lors de l''affectation' AS d FROM DUAL UNION ALL
        SELECT 'DDL_ENGINE_ENABLED', 'Y', 'Activation du moteur DDL contrôlé dans la PDB' FROM DUAL UNION ALL
        SELECT 'AUDIT_RETENTION_DAYS', '365', 'Durée de rétention des logs d''audit' FROM DUAL
    ) src
    ON (dest.SETTING_KEY = src.k)
    WHEN MATCHED THEN
        UPDATE SET dest.SETTING_VALUE = src.v, dest.DESCRIPTION = src.d, dest.UPDATED_AT = SYSTIMESTAMP
    WHEN NOT MATCHED THEN
        INSERT (SETTING_KEY, SETTING_VALUE, DESCRIPTION, UPDATED_AT)
        VALUES (src.k, src.v, src.d, SYSTIMESTAMP);

    COMMIT;
END;
/

