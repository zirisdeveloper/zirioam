-- =====================================================================
-- ZIRI OAM — Oracle Developer Access Manager
-- Script : 003_create_common_packages.sql
-- Cible  : CDB$ROOT (Schéma C##ZIRIOAM_ADMIN)
-- Objet  : Package PL/SQL d'orchestration commune des identités
-- =====================================================================

CREATE OR REPLACE PACKAGE C##ZIRIOAM_ADMIN.PKG_ZIRIOAM_COMMON_OPS AUTHID DEFINER AS

    -- Création d'une identité développeur dans le référentiel ZIRIOAM
    PROCEDURE register_developer (
        p_username    IN VARCHAR2,
        p_full_name   IN VARCHAR2,
        p_email       IN VARCHAR2,
        p_created_by  IN VARCHAR2,
        p_status      IN VARCHAR2 DEFAULT 'ACTIVE'
    );

    -- Provisionnement du compte Oracle commun réel (CREATE USER C##... CONTAINER = ALL)
    -- Attention : Aucun privilège CREATE SESSION n'est accordé à ce niveau !
    PROCEDURE provision_oracle_account (
        p_username     IN VARCHAR2,
        p_temp_password IN VARCHAR2,
        p_dba_user     IN VARCHAR2,
        p_client_ip    IN VARCHAR2 DEFAULT NULL
    );

    -- Réinitialisation du mot de passe avec expiration immédiate
    PROCEDURE reset_password (
        p_username      IN VARCHAR2,
        p_temp_password IN VARCHAR2,
        p_dba_user      IN VARCHAR2,
        p_client_ip     IN VARCHAR2 DEFAULT NULL
    );

    -- Verrouillage du compte Oracle
    PROCEDURE lock_account (
        p_username  IN VARCHAR2,
        p_dba_user  IN VARCHAR2
    );

    -- Déverrouillage du compte Oracle
    PROCEDURE unlock_account (
        p_username  IN VARCHAR2,
        p_dba_user  IN VARCHAR2
    );

    -- Journalisation de l'audit CDB
    PROCEDURE log_audit (
        p_action_type    IN VARCHAR2,
        p_target_user    IN VARCHAR2,
        p_details        IN VARCHAR2,
        p_status         IN VARCHAR2,
        p_dba_user       IN VARCHAR2,
        p_client_ip      IN VARCHAR2 DEFAULT NULL,
        p_correlation_id IN VARCHAR2 DEFAULT NULL
    );

END PKG_ZIRIOAM_COMMON_OPS;
/

CREATE OR REPLACE PACKAGE BODY C##ZIRIOAM_ADMIN.PKG_ZIRIOAM_COMMON_OPS AS

    FUNCTION get_oracle_user(p_username IN VARCHAR2) RETURN VARCHAR2 IS
        v_clean VARCHAR2(128);
    BEGIN
        v_clean := UPPER(TRIM(p_username));
        IF v_clean LIKE 'C##%' THEN
            RETURN v_clean;
        ELSE
            RETURN 'C##' || v_clean;
        END IF;
    END get_oracle_user;

    PROCEDURE register_developer (
        p_username    IN VARCHAR2,
        p_full_name   IN VARCHAR2,
        p_email       IN VARCHAR2,
        p_created_by  IN VARCHAR2,
        p_status      IN VARCHAR2 DEFAULT 'ACTIVE'
    ) IS
        v_norm_user   VARCHAR2(128);
        v_oracle_user VARCHAR2(128);
    BEGIN
        v_norm_user := UPPER(TRIM(p_username));
        IF v_norm_user LIKE 'C##%' THEN
            v_norm_user := SUBSTR(v_norm_user, 4);
        END IF;
        
        v_oracle_user := get_oracle_user(v_norm_user);

        INSERT INTO C##ZIRIOAM_ADMIN.ZIRIOAM_DEVELOPERS 
            (USERNAME, ORACLE_USER, FULL_NAME, EMAIL, STATUS, CREATED_BY, CREATED_AT, UPDATED_AT)
        VALUES 
            (v_norm_user, v_oracle_user, TRIM(p_full_name), LOWER(TRIM(p_email)), UPPER(TRIM(p_status)), p_created_by, SYSTIMESTAMP, SYSTIMESTAMP);
        
        log_audit('REGISTER_DEVELOPER', v_norm_user, 'Enregistrement identité : ' || p_full_name, 'SUCCESS', p_created_by);
    EXCEPTION
        WHEN DUP_VAL_ON_INDEX THEN
            -- ADD ONLY rule : ignorer silencieusement si déjà présent
            NULL;
        WHEN OTHERS THEN
            log_audit('REGISTER_DEVELOPER', v_norm_user, 'Erreur : ' || SQLERRM, 'FAILED', p_created_by);
            RAISE;
    END register_developer;

    PROCEDURE provision_oracle_account (
        p_username      IN VARCHAR2,
        p_temp_password IN VARCHAR2,
        p_dba_user      IN VARCHAR2,
        p_client_ip     IN VARCHAR2 DEFAULT NULL
    ) IS
        v_oracle_user VARCHAR2(128);
        v_sql         VARCHAR2(1000);
    BEGIN
        v_oracle_user := get_oracle_user(p_username);

        -- Création stricte du compte commun avec mot de passe expiré immédiatement
        -- IMPORTANT : AUCUN PRIVILÈGE CREATE SESSION N'EST DONNÉ ICI
        v_sql := 'CREATE USER ' || DBMS_ASSERT.ENQUOTE_NAME(v_oracle_user, FALSE) || 
                 ' IDENTIFIED BY "' || p_temp_password || '" ' ||
                 ' PASSWORD EXPIRE CONTAINER = ALL';
        
        EXECUTE IMMEDIATE v_sql;

        log_audit('PROVISION_ORACLE_ACCOUNT', v_oracle_user, 'Création compte commun sans droit session', 'SUCCESS', p_dba_user, p_client_ip);
    EXCEPTION
        WHEN OTHERS THEN
            log_audit('PROVISION_ORACLE_ACCOUNT', v_oracle_user, 'Erreur création : ' || SQLERRM, 'FAILED', p_dba_user, p_client_ip);
            RAISE;
    END provision_oracle_account;

    PROCEDURE reset_password (
        p_username      IN VARCHAR2,
        p_temp_password IN VARCHAR2,
        p_dba_user      IN VARCHAR2,
        p_client_ip     IN VARCHAR2 DEFAULT NULL
    ) IS
        v_oracle_user VARCHAR2(128);
        v_sql         VARCHAR2(1000);
    BEGIN
        v_oracle_user := get_oracle_user(p_username);

        -- Exécution de l'ordre ALTER USER ... PASSWORD EXPIRE CONTAINER = ALL
        v_sql := 'ALTER USER ' || DBMS_ASSERT.ENQUOTE_NAME(v_oracle_user, FALSE) || 
                 ' IDENTIFIED BY "' || p_temp_password || '" ' ||
                 ' PASSWORD EXPIRE CONTAINER = ALL';
        
        EXECUTE IMMEDIATE v_sql;

        -- Journalisation sans aucun mot de passe
        INSERT INTO C##ZIRIOAM_ADMIN.ZIRIOAM_PWD_RESET_LOG
            (USERNAME, ORACLE_USER, RESET_BY_DBA, CLIENT_IP, STATUS, RESET_AT)
        VALUES
            (p_username, v_oracle_user, p_dba_user, p_client_ip, 'SUCCESS', SYSTIMESTAMP);

        log_audit('PASSWORD_RESET', v_oracle_user, 'Réinitialisation mot de passe temporaire (expiré)', 'SUCCESS', p_dba_user, p_client_ip);
    EXCEPTION
        WHEN OTHERS THEN
            INSERT INTO C##ZIRIOAM_ADMIN.ZIRIOAM_PWD_RESET_LOG
                (USERNAME, ORACLE_USER, RESET_BY_DBA, CLIENT_IP, STATUS, ERROR_MESSAGE, RESET_AT)
            VALUES
                (p_username, v_oracle_user, p_dba_user, p_client_ip, 'FAILED', SQLERRM, SYSTIMESTAMP);

            log_audit('PASSWORD_RESET', v_oracle_user, 'Échec réinitialisation : ' || SQLERRM, 'FAILED', p_dba_user, p_client_ip);
            RAISE;
    END reset_password;

    PROCEDURE lock_account (
        p_username  IN VARCHAR2,
        p_dba_user  IN VARCHAR2
    ) IS
        v_oracle_user VARCHAR2(128);
    BEGIN
        v_oracle_user := get_oracle_user(p_username);
        EXECUTE IMMEDIATE 'ALTER USER ' || DBMS_ASSERT.ENQUOTE_NAME(v_oracle_user, FALSE) || ' ACCOUNT LOCK CONTAINER = ALL';
        log_audit('LOCK_ACCOUNT', v_oracle_user, 'Compte verrouillé dans toute la CDB', 'SUCCESS', p_dba_user);
    EXCEPTION
        WHEN OTHERS THEN
            log_audit('LOCK_ACCOUNT', v_oracle_user, 'Erreur verrouillage : ' || SQLERRM, 'FAILED', p_dba_user);
            RAISE;
    END lock_account;

    PROCEDURE unlock_account (
        p_username  IN VARCHAR2,
        p_dba_user  IN VARCHAR2
    ) IS
        v_oracle_user VARCHAR2(128);
    BEGIN
        v_oracle_user := get_oracle_user(p_username);
        EXECUTE IMMEDIATE 'ALTER USER ' || DBMS_ASSERT.ENQUOTE_NAME(v_oracle_user, FALSE) || ' ACCOUNT UNLOCK CONTAINER = ALL';
        log_audit('UNLOCK_ACCOUNT', v_oracle_user, 'Compte déverrouillé dans toute la CDB', 'SUCCESS', p_dba_user);
    EXCEPTION
        WHEN OTHERS THEN
            log_audit('UNLOCK_ACCOUNT', v_oracle_user, 'Erreur déverrouillage : ' || SQLERRM, 'FAILED', p_dba_user);
            RAISE;
    END unlock_account;

    PROCEDURE log_audit (
        p_action_type    IN VARCHAR2,
        p_target_user    IN VARCHAR2,
        p_details        IN VARCHAR2,
        p_status         IN VARCHAR2,
        p_dba_user       IN VARCHAR2,
        p_client_ip      IN VARCHAR2 DEFAULT NULL,
        p_correlation_id IN VARCHAR2 DEFAULT NULL
    ) IS
        PRAGMA AUTONOMOUS_TRANSACTION;
    BEGIN
        INSERT INTO C##ZIRIOAM_ADMIN.ZIRIOAM_CDB_AUDIT
            (EVENT_TIME, DBA_USER, ACTION_TYPE, TARGET_USER, DETAILS, STATUS, CLIENT_IP, CORRELATION_ID)
        VALUES
            (SYSTIMESTAMP, p_dba_user, p_action_type, p_target_user, p_details, p_status, p_client_ip, p_correlation_id);
        COMMIT;
    EXCEPTION
        WHEN OTHERS THEN
            ROLLBACK;
    END log_audit;

END PKG_ZIRIOAM_COMMON_OPS;
/

