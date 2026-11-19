-- =====================================================================
-- ZIRI OAM — Oracle Developer Access Manager
-- Script : upgrades/1.0.1.sql
-- Objet  : Script de mise à jour incrémentale v1.0.1
-- =====================================================================

-- Exemple d'ajout d'index de performance sur l'audit
BEGIN
    EXECUTE IMMEDIATE 'CREATE INDEX ZIRIOAM_OWNER.IDX_ZIRIOAM_HIST_OP ON ZIRIOAM_OWNER.ZIRIOAM_EXECUTION_HISTORY(DDL_OPERATION)';
EXCEPTION
    WHEN OTHERS THEN
        NULL; -- Idempotent
END;
/

