-- =====================================================================
-- ZIRI OAM — Oracle Developer Access Manager
-- Script : 004_common_privileges.sql
-- Cible  : CDB$ROOT
-- Objet  : Enregistrement de l'installation et finalisation des droits communs
-- =====================================================================

BEGIN
    -- Droits d'exécution sur le package d'orchestration commune (uniquement DBA en CDB$ROOT)
    BEGIN
        EXECUTE IMMEDIATE 'GRANT EXECUTE ON C##ZIRIOAM_ADMIN.PKG_ZIRIOAM_COMMON_OPS TO DBA';
    EXCEPTION WHEN OTHERS THEN NULL;
    END;
    BEGIN
        EXECUTE IMMEDIATE 'REVOKE EXECUTE ON C##ZIRIOAM_ADMIN.PKG_ZIRIOAM_COMMON_OPS FROM PUBLIC';
    EXCEPTION WHEN OTHERS THEN NULL;
    END;

    -- Droits d'exécution en lecture seule sur le package de découverte PDB (/selfservice)
    -- Remarque Oracle 19c : pas de clause CONTAINER=ALL sur un objet schéma personnalisé (sinon ORA-65040)
    EXECUTE IMMEDIATE 'GRANT EXECUTE ON C##ZIRIOAM_ADMIN.PKG_ZIRIOAM_DEV_DISCOVERY TO PUBLIC';

    -- Insertion ou mise à jour de l'état d'installation CDB
    MERGE INTO C##ZIRIOAM_ADMIN.ZIRIOAM_INSTALLATION dest
    USING (
        SELECT 'ZIRIOAM_DEVELOPER_MANAGER' AS product_code,
               '1.0.0' AS product_version,
               'INSTALLED' AS install_status,
               'CDB$ROOT' AS container_name
        FROM DUAL
    ) src
    ON (dest.PRODUCT_CODE = src.product_code)
    WHEN MATCHED THEN
        UPDATE SET dest.INSTALL_STATUS = src.install_status,
                   dest.PRODUCT_VERSION = src.product_version,
                   dest.INSTALL_DATE = SYSTIMESTAMP
    WHEN NOT MATCHED THEN
        INSERT (PRODUCT_CODE, PRODUCT_VERSION, INSTALL_DATE, INSTALL_STATUS, CONTAINER_NAME)
        VALUES (src.product_code, src.product_version, SYSTIMESTAMP, src.install_status, src.container_name);

    COMMIT;
END;
/

