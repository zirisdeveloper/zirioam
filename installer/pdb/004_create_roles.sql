-- =====================================================================
-- ZIRI OAM — Oracle Developer Access Manager
-- Script : 004_create_roles.sql
-- Cible  : PDB Locale
-- Objet  : Création des rôles techniques et de base dans la PDB
-- Idempotent : Oui
-- =====================================================================

DECLARE
    v_count NUMBER;
BEGIN
    -- 1. Rôle de base pour développeur habilité sur la PDB
    SELECT COUNT(*) INTO v_count FROM DBA_ROLES WHERE ROLE = 'ZIRIOAM_DEV_BASE_ROLE';
    IF v_count = 0 THEN
        EXECUTE IMMEDIATE 'CREATE ROLE ZIRIOAM_DEV_BASE_ROLE';
        -- Droit d'accéder au package d'exécution DDL contrôlé
        EXECUTE IMMEDIATE 'GRANT CREATE SESSION TO ZIRIOAM_DEV_BASE_ROLE';
    END IF;

    -- 2. Rôle d'auditeur local de la PDB
    SELECT COUNT(*) INTO v_count FROM DBA_ROLES WHERE ROLE = 'ZIRIOAM_AUDITOR_ROLE';
    IF v_count = 0 THEN
        EXECUTE IMMEDIATE 'CREATE ROLE ZIRIOAM_AUDITOR_ROLE';
        EXECUTE IMMEDIATE 'GRANT CREATE SESSION TO ZIRIOAM_AUDITOR_ROLE';
    END IF;
END;
/

