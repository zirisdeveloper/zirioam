-- =====================================================================
-- ZIRI OAM — Oracle Developer Access Manager
-- Script : 001_create_common_components.sql
-- Cible  : CDB$ROOT
-- Objet  : Création du compte propriétaire commun et attribution des droits de base
-- Idempotent : Oui
-- =====================================================================

DECLARE
    v_user_count NUMBER;
    v_default_ts VARCHAR2(30) := 'USERS';
    v_temp_ts    VARCHAR2(30) := 'TEMP';
    v_tablespace_exists NUMBER;
BEGIN
    -- 1. Vérification de l'existence du tablespace USERS, fallback sur SYSTEM si USERS absent
    SELECT COUNT(*) INTO v_tablespace_exists 
    FROM V$TABLESPACE WHERE NAME = 'USERS';
    
    IF v_tablespace_exists = 0 THEN
        v_default_ts := 'SYSTEM';
    END IF;

    -- 2. Vérification de l'existence du compte commun C##ZIRIOAM_ADMIN
    SELECT COUNT(*) INTO v_user_count 
    FROM CDB_USERS 
    WHERE USERNAME = 'C##ZIRIOAM_ADMIN';

    IF v_user_count = 0 THEN
        -- Création de l'utilisateur commun avec CONTAINER = ALL
        -- Le mot de passe par défaut généré est verrouillé ou initialisé
        EXECUTE IMMEDIATE 'CREATE USER C##ZIRIOAM_ADMIN IDENTIFIED BY "ZiriOam_Admin2026#Setup" ' ||
                          'DEFAULT TABLESPACE ' || v_default_ts || ' ' ||
                          'TEMPORARY TABLESPACE ' || v_temp_ts || ' ' ||
                          'QUOTA UNLIMITED ON ' || v_default_ts || ' ' ||
                          'CONTAINER = ALL';
    ELSE
        -- S'il existe déjà, s'assurer des quotas
        EXECUTE IMMEDIATE 'ALTER USER C##ZIRIOAM_ADMIN QUOTA UNLIMITED ON ' || v_default_ts || ' CONTAINER = ALL';
    END IF;

    -- 3. Privilèges système nécessaires pour l'administration commune
    EXECUTE IMMEDIATE 'GRANT CREATE SESSION TO C##ZIRIOAM_ADMIN CONTAINER = ALL';
    EXECUTE IMMEDIATE 'GRANT CREATE TABLE TO C##ZIRIOAM_ADMIN CONTAINER = ALL';
    EXECUTE IMMEDIATE 'GRANT CREATE VIEW TO C##ZIRIOAM_ADMIN CONTAINER = ALL';
    EXECUTE IMMEDIATE 'GRANT CREATE SEQUENCE TO C##ZIRIOAM_ADMIN CONTAINER = ALL';
    EXECUTE IMMEDIATE 'GRANT CREATE PROCEDURE TO C##ZIRIOAM_ADMIN CONTAINER = ALL';
    EXECUTE IMMEDIATE 'GRANT CREATE USER TO C##ZIRIOAM_ADMIN CONTAINER = ALL';
    EXECUTE IMMEDIATE 'GRANT ALTER USER TO C##ZIRIOAM_ADMIN CONTAINER = ALL';
    EXECUTE IMMEDIATE 'GRANT DROP USER TO C##ZIRIOAM_ADMIN CONTAINER = ALL';
    
    -- Droits de lecture du dictionnaire pour la découverte
    EXECUTE IMMEDIATE 'GRANT SELECT_CATALOG_ROLE TO C##ZIRIOAM_ADMIN CONTAINER = ALL';

    -- S'assurer que le rôle DBA et SYSTEM disposent également des droits CONTAINER = ALL
    BEGIN
        EXECUTE IMMEDIATE 'GRANT ALTER USER TO DBA CONTAINER = ALL';
    EXCEPTION WHEN OTHERS THEN NULL;
    END;
    BEGIN
        EXECUTE IMMEDIATE 'GRANT CREATE USER TO DBA CONTAINER = ALL';
    EXCEPTION WHEN OTHERS THEN NULL;
    END;
    BEGIN
        EXECUTE IMMEDIATE 'GRANT ALTER USER TO SYSTEM CONTAINER = ALL';
    EXCEPTION WHEN OTHERS THEN NULL;
    END;
    BEGIN
        EXECUTE IMMEDIATE 'GRANT CREATE USER TO SYSTEM CONTAINER = ALL';
    EXCEPTION WHEN OTHERS THEN NULL;
    END;

END;
/

