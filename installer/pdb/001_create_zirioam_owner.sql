-- =====================================================================
-- ZIRI OAM — Oracle Developer Access Manager
-- Script : 001_create_zirioam_owner.sql
-- Cible  : PDB Locale (ex: PDB_GESTION, PDB_FINANCE)
-- Objet  : Création de l'utilisateur propriétaire local ZIRIOAM_OWNER
-- Idempotent : Oui
-- =====================================================================

DECLARE
    v_user_count NUMBER;
    v_default_ts VARCHAR2(30) := 'USERS';
    v_temp_ts    VARCHAR2(30) := 'TEMP';
    v_tablespace_exists NUMBER;
BEGIN
    -- 1. Détection du tablespace par défaut
    SELECT COUNT(*) INTO v_tablespace_exists FROM USER_TABLESPACES WHERE TABLESPACE_NAME = 'USERS';
    IF v_tablespace_exists = 0 THEN
        SELECT TABLESPACE_NAME INTO v_default_ts FROM (
            SELECT TABLESPACE_NAME FROM USER_TABLESPACES 
            WHERE CONTENTS = 'PERMANENT' AND TABLESPACE_NAME NOT IN ('SYSTEM', 'SYSAUX')
            ORDER BY TABLESPACE_NAME
        ) WHERE ROWNUM = 1;
    END IF;

    -- 2. Création ou mise à jour de l'utilisateur local ZIRIOAM_OWNER
    SELECT COUNT(*) INTO v_user_count FROM ALL_USERS WHERE USERNAME = 'ZIRIOAM_OWNER';

    IF v_user_count = 0 THEN
        EXECUTE IMMEDIATE 'CREATE USER ZIRIOAM_OWNER IDENTIFIED BY "ZiriOam_LocalOwner#2026" ' ||
                          'DEFAULT TABLESPACE ' || v_default_ts || ' ' ||
                          'TEMPORARY TABLESPACE ' || v_temp_ts || ' ' ||
                          'QUOTA UNLIMITED ON ' || v_default_ts;
    ELSE
        EXECUTE IMMEDIATE 'ALTER USER ZIRIOAM_OWNER QUOTA UNLIMITED ON ' || v_default_ts;
    END IF;

    -- 3. Privilèges locaux nécessaires pour gérer les tables, vues, packages et rôles
    EXECUTE IMMEDIATE 'GRANT CREATE SESSION TO ZIRIOAM_OWNER';
    EXECUTE IMMEDIATE 'GRANT CREATE TABLE TO ZIRIOAM_OWNER';
    EXECUTE IMMEDIATE 'GRANT CREATE VIEW TO ZIRIOAM_OWNER';
    EXECUTE IMMEDIATE 'GRANT CREATE SEQUENCE TO ZIRIOAM_OWNER';
    EXECUTE IMMEDIATE 'GRANT CREATE PROCEDURE TO ZIRIOAM_OWNER';
    EXECUTE IMMEDIATE 'GRANT CREATE ROLE TO ZIRIOAM_OWNER';
    EXECUTE IMMEDIATE 'GRANT GRANT ANY ROLE TO ZIRIOAM_OWNER';
    
    -- Droits pour le moteur DDL contrôlé (gestion des objets de schémas cibles)
    EXECUTE IMMEDIATE 'GRANT CREATE ANY TABLE TO ZIRIOAM_OWNER';
    EXECUTE IMMEDIATE 'GRANT ALTER ANY TABLE TO ZIRIOAM_OWNER';
    EXECUTE IMMEDIATE 'GRANT DROP ANY TABLE TO ZIRIOAM_OWNER';
    EXECUTE IMMEDIATE 'GRANT CREATE ANY VIEW TO ZIRIOAM_OWNER';
    EXECUTE IMMEDIATE 'GRANT DROP ANY VIEW TO ZIRIOAM_OWNER';
    EXECUTE IMMEDIATE 'GRANT CREATE ANY SEQUENCE TO ZIRIOAM_OWNER';
    EXECUTE IMMEDIATE 'GRANT DROP ANY SEQUENCE TO ZIRIOAM_OWNER';
    EXECUTE IMMEDIATE 'GRANT CREATE ANY PROCEDURE TO ZIRIOAM_OWNER';
    EXECUTE IMMEDIATE 'GRANT DROP ANY PROCEDURE TO ZIRIOAM_OWNER';

END;
/

