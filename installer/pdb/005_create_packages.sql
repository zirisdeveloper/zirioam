-- =====================================================================
-- ZIRI OAM — Oracle Developer Access Manager
-- Script : 005_create_packages.sql
-- Cible  : PDB Locale (Schéma ZIRIOAM_OWNER)
-- Objet  : Packages PL/SQL de gestion des accès et Moteur DDL Contrôlé
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. PACKAGE : PKG_ZIRIOAM_PDB_ACCESS (Gestion des Accès et des Rôles)
-- ---------------------------------------------------------------------
CREATE OR REPLACE PACKAGE ZIRIOAM_OWNER.PKG_ZIRIOAM_PDB_ACCESS AUTHID DEFINER AS

    -- Création d'un rôle fonctionnel Oracle pour un schéma cible
    FUNCTION create_schema_role (
        p_target_schema IN VARCHAR2,
        p_profile_type  IN VARCHAR2, -- APP_READ, APP_WRITE, APP_READ_WRITE, APP_EXECUTE
        p_description   IN VARCHAR2 DEFAULT NULL,
        p_created_by    IN VARCHAR2 DEFAULT USER
    ) RETURN NUMBER;

    -- Attribution de la session locale dans la PDB au développeur
    PROCEDURE grant_session_access (
        p_username   IN VARCHAR2,
        p_granted_by IN VARCHAR2
    );

    -- Révocation de la session locale dans la PDB
    PROCEDURE revoke_session_access (
        p_username   IN VARCHAR2,
        p_revoked_by IN VARCHAR2
    );

    -- Attribution d'un rôle fonctionnel au développeur
    PROCEDURE grant_role_to_developer (
        p_username   IN VARCHAR2,
        p_role_name  IN VARCHAR2,
        p_granted_by IN VARCHAR2
    );

    -- Révocation d'un rôle fonctionnel
    PROCEDURE revoke_role_from_developer (
        p_username   IN VARCHAR2,
        p_role_name  IN VARCHAR2,
        p_revoked_by IN VARCHAR2
    );

    -- Configuration de la matrice d'accès développeur / schéma (Séparation DDL / DML)
    PROCEDURE set_developer_access (
        p_username       IN VARCHAR2,
        p_target_schema  IN VARCHAR2,
        p_role_id        IN NUMBER,
        p_can_ddl_create IN NUMBER,
        p_can_ddl_alter  IN NUMBER,
        p_can_ddl_drop   IN NUMBER,
        p_granted_by     IN VARCHAR2
    );

    -- Journalisation de l'audit local
    PROCEDURE log_audit (
        p_operation        IN VARCHAR2,
        p_target_developer IN VARCHAR2,
        p_target_schema    IN VARCHAR2,
        p_target_role      IN VARCHAR2,
        p_result           IN VARCHAR2,
        p_details          IN VARCHAR2,
        p_dba_user         IN VARCHAR2,
        p_client_ip        IN VARCHAR2 DEFAULT NULL
    );

END PKG_ZIRIOAM_PDB_ACCESS;
/

CREATE OR REPLACE PACKAGE BODY ZIRIOAM_OWNER.PKG_ZIRIOAM_PDB_ACCESS AS

    FUNCTION create_schema_role (
        p_target_schema IN VARCHAR2,
        p_profile_type  IN VARCHAR2,
        p_description   IN VARCHAR2 DEFAULT NULL,
        p_created_by    IN VARCHAR2 DEFAULT USER
    ) RETURN NUMBER IS
        v_role_name  VARCHAR2(128);
        v_role_id    NUMBER;
        v_role_count NUMBER;
    BEGIN
        -- Format normalisé du nom de rôle Oracle : R_<SCHEMA>_<TYPE_ACCES>
        -- Exemple : R_GESTION_READ, R_GESTION_WRITE
        v_role_name := 'R_' || UPPER(TRIM(p_target_schema)) || '_' || 
                       CASE UPPER(TRIM(p_profile_type))
                           WHEN 'APP_READ' THEN 'READ'
                           WHEN 'APP_WRITE' THEN 'WRITE'
                           WHEN 'APP_READ_WRITE' THEN 'RW'
                           WHEN 'APP_EXECUTE' THEN 'EXEC'
                           ELSE SUBSTR(UPPER(TRIM(p_profile_type)), 1, 10)
                       END;

        -- Création physique du rôle dans la PDB
        SELECT COUNT(*) INTO v_role_count FROM DBA_ROLES WHERE ROLE = v_role_name;
        IF v_role_count = 0 THEN
            EXECUTE IMMEDIATE 'CREATE ROLE ' || DBMS_ASSERT.ENQUOTE_NAME(v_role_name, FALSE);
        END IF;

        -- Enregistrement dans le catalogue ZIRIOAM_ACCESS_ROLES
        MERGE INTO ZIRIOAM_OWNER.ZIRIOAM_ACCESS_ROLES dest
        USING (SELECT v_role_name AS rname FROM DUAL) src
        ON (dest.ROLE_NAME = src.rname)
        WHEN MATCHED THEN
            UPDATE SET dest.TARGET_SCHEMA = UPPER(TRIM(p_target_schema)),
                       dest.PROFILE_TYPE = UPPER(TRIM(p_profile_type)),
                       dest.DESCRIPTION = p_description,
                       dest.IS_ACTIVE = 'Y'
        WHEN NOT MATCHED THEN
            INSERT (ROLE_NAME, TARGET_SCHEMA, PROFILE_TYPE, DESCRIPTION, IS_ACTIVE, CREATED_BY, CREATED_AT)
            VALUES (v_role_name, UPPER(TRIM(p_target_schema)), UPPER(TRIM(p_profile_type)), p_description, 'Y', p_created_by, SYSTIMESTAMP);

        SELECT ROLE_ID INTO v_role_id FROM ZIRIOAM_OWNER.ZIRIOAM_ACCESS_ROLES WHERE ROLE_NAME = v_role_name;

        log_audit('CREATE_ROLE', NULL, p_target_schema, v_role_name, 'SUCCESS', 'Création rôle fonctionnel', p_created_by);
        RETURN v_role_id;
    END create_schema_role;

    PROCEDURE grant_session_access (
        p_username   IN VARCHAR2,
        p_granted_by IN VARCHAR2
    ) IS
        v_user VARCHAR2(128);
    BEGIN
        v_user := UPPER(TRIM(p_username));
        -- Droit de connexion STRICTEMENT LOCAL à la PDB courante
        EXECUTE IMMEDIATE 'GRANT CREATE SESSION TO ' || DBMS_ASSERT.ENQUOTE_NAME(v_user, FALSE);
        EXECUTE IMMEDIATE 'GRANT ZIRIOAM_DEV_BASE_ROLE TO ' || DBMS_ASSERT.ENQUOTE_NAME(v_user, FALSE);
        log_audit('GRANT_SESSION', v_user, NULL, 'CREATE SESSION', 'SUCCESS', 'Accès session accordé sur PDB', p_granted_by);
    EXCEPTION
        WHEN OTHERS THEN
            log_audit('GRANT_SESSION', v_user, NULL, 'CREATE SESSION', 'FAILED', SQLERRM, p_granted_by);
            RAISE;
    END grant_session_access;

    PROCEDURE revoke_session_access (
        p_username   IN VARCHAR2,
        p_revoked_by IN VARCHAR2
    ) IS
        v_user VARCHAR2(128);
    BEGIN
        v_user := UPPER(TRIM(p_username));
        EXECUTE IMMEDIATE 'REVOKE CREATE SESSION FROM ' || DBMS_ASSERT.ENQUOTE_NAME(v_user, FALSE);
        EXECUTE IMMEDIATE 'REVOKE ZIRIOAM_DEV_BASE_ROLE FROM ' || DBMS_ASSERT.ENQUOTE_NAME(v_user, FALSE);
        log_audit('REVOKE_SESSION', v_user, NULL, 'CREATE SESSION', 'SUCCESS', 'Accès session révoqué sur PDB', p_revoked_by);
    EXCEPTION
        WHEN OTHERS THEN
            log_audit('REVOKE_SESSION', v_user, NULL, 'CREATE SESSION', 'FAILED', SQLERRM, p_revoked_by);
            RAISE;
    END revoke_session_access;

    PROCEDURE grant_role_to_developer (
        p_username   IN VARCHAR2,
        p_role_name  IN VARCHAR2,
        p_granted_by IN VARCHAR2
    ) IS
        v_user VARCHAR2(128);
        v_role VARCHAR2(128);
    BEGIN
        v_user := UPPER(TRIM(p_username));
        v_role := UPPER(TRIM(p_role_name));

        EXECUTE IMMEDIATE 'GRANT ' || DBMS_ASSERT.ENQUOTE_NAME(v_role, FALSE) || ' TO ' || DBMS_ASSERT.ENQUOTE_NAME(v_user, FALSE);
        log_audit('GRANT_ROLE', v_user, NULL, v_role, 'SUCCESS', 'Rôle accordé', p_granted_by);
    EXCEPTION
        WHEN OTHERS THEN
            log_audit('GRANT_ROLE', v_user, NULL, v_role, 'FAILED', SQLERRM, p_granted_by);
            RAISE;
    END grant_role_to_developer;

    PROCEDURE revoke_role_from_developer (
        p_username   IN VARCHAR2,
        p_role_name  IN VARCHAR2,
        p_revoked_by IN VARCHAR2
    ) IS
        v_user VARCHAR2(128);
        v_role VARCHAR2(128);
    BEGIN
        v_user := UPPER(TRIM(p_username));
        v_role := UPPER(TRIM(p_role_name));

        EXECUTE IMMEDIATE 'REVOKE ' || DBMS_ASSERT.ENQUOTE_NAME(v_role, FALSE) || ' FROM ' || DBMS_ASSERT.ENQUOTE_NAME(v_user, FALSE);
        log_audit('REVOKE_ROLE', v_user, NULL, v_role, 'SUCCESS', 'Rôle révoqué', p_revoked_by);
    EXCEPTION
        WHEN OTHERS THEN
            log_audit('REVOKE_ROLE', v_user, NULL, v_role, 'FAILED', SQLERRM, p_revoked_by);
            RAISE;
    END revoke_role_from_developer;

    PROCEDURE set_developer_access (
        p_username       IN VARCHAR2,
        p_target_schema  IN VARCHAR2,
        p_role_id        IN NUMBER,
        p_can_ddl_create IN NUMBER,
        p_can_ddl_alter  IN NUMBER,
        p_can_ddl_drop   IN NUMBER,
        p_granted_by     IN VARCHAR2
    ) IS
        v_user   VARCHAR2(128);
        v_schema VARCHAR2(128);
    BEGIN
        v_user := UPPER(TRIM(p_username));
        v_schema := UPPER(TRIM(p_target_schema));

        MERGE INTO ZIRIOAM_OWNER.ZIRIOAM_DEVELOPER_ACCESS dest
        USING (SELECT v_user AS u, v_schema AS s FROM DUAL) src
        ON (dest.USERNAME = src.u AND dest.TARGET_SCHEMA = src.s)
        WHEN MATCHED THEN
            UPDATE SET dest.ROLE_ID = p_role_id,
                       dest.CAN_DDL_CREATE = p_can_ddl_create,
                       dest.CAN_DDL_ALTER = p_can_ddl_alter,
                       dest.CAN_DDL_DROP = p_can_ddl_drop,
                       dest.STATUS = 'ACTIVE',
                       dest.UPDATED_AT = SYSTIMESTAMP
        WHEN NOT MATCHED THEN
            INSERT (USERNAME, TARGET_SCHEMA, ROLE_ID, CAN_DDL_CREATE, CAN_DDL_ALTER, CAN_DDL_DROP, STATUS, GRANTED_BY, GRANTED_AT, UPDATED_AT)
            VALUES (v_user, v_schema, p_role_id, p_can_ddl_create, p_can_ddl_alter, p_can_ddl_drop, 'ACTIVE', p_granted_by, SYSTIMESTAMP, SYSTIMESTAMP);

        -- Assurer le droit de connexion minimal dans la PDB
        grant_session_access(v_user, p_granted_by);

        -- Si un rôle DML est rattaché, l'accorder
        IF p_role_id IS NOT NULL THEN
            FOR r IN (SELECT ROLE_NAME FROM ZIRIOAM_OWNER.ZIRIOAM_ACCESS_ROLES WHERE ROLE_ID = p_role_id) LOOP
                grant_role_to_developer(v_user, r.ROLE_NAME, p_granted_by);
            END LOOP;
        END IF;

        log_audit('SET_ACCESS_MATRIX', v_user, v_schema, NULL, 'SUCCESS', 
                  'DDL C=' || p_can_ddl_create || ' A=' || p_can_ddl_alter || ' D=' || p_can_ddl_drop, p_granted_by);
    END set_developer_access;

    PROCEDURE log_audit (
        p_operation        IN VARCHAR2,
        p_target_developer IN VARCHAR2,
        p_target_schema    IN VARCHAR2,
        p_target_role      IN VARCHAR2,
        p_result           IN VARCHAR2,
        p_details          IN VARCHAR2,
        p_dba_user         IN VARCHAR2,
        p_client_ip        IN VARCHAR2 DEFAULT NULL
    ) IS
        PRAGMA AUTONOMOUS_TRANSACTION;
    BEGIN
        INSERT INTO ZIRIOAM_OWNER.ZIRIOAM_AUDIT_LOG
            (EVENT_TIME, DBA_USER, OPERATION, TARGET_DEVELOPER, TARGET_SCHEMA, TARGET_ROLE, RESULT, DETAILS, CLIENT_IP)
        VALUES
            (SYSTIMESTAMP, p_dba_user, p_operation, p_target_developer, p_target_schema, p_target_role, p_result, p_details, p_client_ip);
        COMMIT;
    EXCEPTION
        WHEN OTHERS THEN
            ROLLBACK;
    END log_audit;

END PKG_ZIRIOAM_PDB_ACCESS;
/

-- ---------------------------------------------------------------------
-- 2. PACKAGE : PKG_ZIRIOAM_DDL_ENGINE (Moteur DDL Contrôlé)
-- ---------------------------------------------------------------------
CREATE OR REPLACE PACKAGE ZIRIOAM_OWNER.PKG_ZIRIOAM_DDL_ENGINE AUTHID DEFINER AS

    -- Exécute un ordre DDL validé sans accorder de privilège ANY au développeur
    PROCEDURE execute_controlled_ddl (
        p_target_schema IN VARCHAR2,
        p_operation     IN VARCHAR2, -- CREATE, ALTER, DROP
        p_object_type   IN VARCHAR2, -- TABLE, VIEW, INDEX, SEQUENCE, PROCEDURE, FUNCTION, PACKAGE
        p_object_name   IN VARCHAR2,
        p_ddl_statement IN CLOB,
        p_out_status    OUT VARCHAR2,
        p_out_error     OUT VARCHAR2
    );

END PKG_ZIRIOAM_DDL_ENGINE;
/

CREATE OR REPLACE PACKAGE BODY ZIRIOAM_OWNER.PKG_ZIRIOAM_DDL_ENGINE AS

    PROCEDURE execute_controlled_ddl (
        p_target_schema IN VARCHAR2,
        p_operation     IN VARCHAR2,
        p_object_type   IN VARCHAR2,
        p_object_name   IN VARCHAR2,
        p_ddl_statement IN CLOB,
        p_out_status    OUT VARCHAR2,
        p_out_error     OUT VARCHAR2
    ) IS
        v_caller      VARCHAR2(128);
        v_schema      VARCHAR2(128);
        v_op          VARCHAR2(30);
        v_obj_type    VARCHAR2(30);
        v_obj_name    VARCHAR2(128);
        v_has_access  NUMBER := 0;
        v_stmt_clean  VARCHAR2(32767);
        v_exec_id     NUMBER;
    BEGIN
        -- 1. Récupération de l'identité réelle de la session appelante
        v_caller   := SYS_CONTEXT('USERENV', 'SESSION_USER');
        v_schema   := UPPER(TRIM(p_target_schema));
        v_op       := UPPER(TRIM(p_operation));
        v_obj_type := UPPER(TRIM(p_object_type));
        v_obj_name := UPPER(TRIM(p_object_name));

        -- 2. Validation des types d'objets autorisés
        IF v_obj_type NOT IN ('TABLE', 'VIEW', 'INDEX', 'SEQUENCE', 'PROCEDURE', 'FUNCTION', 'PACKAGE') THEN
            p_out_status := 'REJECTED';
            p_out_error  := 'Type d''objet non autorisé : ' || v_obj_type;
            RETURN;
        END IF;

        -- 3. Vérification des autorisations dans la matrice ZIRIOAM_DEVELOPER_ACCESS
        IF v_op = 'CREATE' THEN
            SELECT COUNT(*) INTO v_has_access FROM ZIRIOAM_OWNER.ZIRIOAM_DEVELOPER_ACCESS
            WHERE USERNAME = v_caller AND TARGET_SCHEMA = v_schema AND CAN_DDL_CREATE = 1 AND STATUS = 'ACTIVE';
        ELSIF v_op = 'ALTER' THEN
            SELECT COUNT(*) INTO v_has_access FROM ZIRIOAM_OWNER.ZIRIOAM_DEVELOPER_ACCESS
            WHERE USERNAME = v_caller AND TARGET_SCHEMA = v_schema AND CAN_DDL_ALTER = 1 AND STATUS = 'ACTIVE';
        ELSIF v_op = 'DROP' THEN
            SELECT COUNT(*) INTO v_has_access FROM ZIRIOAM_OWNER.ZIRIOAM_DEVELOPER_ACCESS
            WHERE USERNAME = v_caller AND TARGET_SCHEMA = v_schema AND CAN_DDL_DROP = 1 AND STATUS = 'ACTIVE';
        ELSE
            p_out_status := 'REJECTED';
            p_out_error  := 'Opération DDL non reconnue : ' || v_op;
            RETURN;
        END IF;

        IF v_has_access = 0 THEN
            p_out_status := 'REJECTED';
            p_out_error  := 'Accès refusé : Le développeur ' || v_caller || ' ne possède pas le droit DDL ' || v_op || ' sur le schéma ' || v_schema;
            
            -- Trace du refus
            INSERT INTO ZIRIOAM_OWNER.ZIRIOAM_EXECUTION_HISTORY
                (USERNAME, TARGET_SCHEMA, DDL_OPERATION, OBJECT_TYPE, OBJECT_NAME, DDL_STATEMENT, EXECUTION_STATUS, ORACLE_ERROR_MESSAGE, EXECUTED_AT)
            VALUES
                (v_caller, v_schema, v_op, v_obj_type, v_obj_name, p_ddl_statement, 'REJECTED', p_out_error, SYSTIMESTAMP);
            RETURN;
        END IF;

        -- 4. Analyse de sécurité syntaxique du DDL (anti-élévation de privilèges)
        v_stmt_clean := UPPER(SUBSTR(p_ddl_statement, 1, 4000));
        IF INSTR(v_stmt_clean, 'ORGANIZATION EXTERNAL') > 0 OR 
           INSTR(v_stmt_clean, 'EXECUTE IMMEDIATE') > 0 OR
           INSTR(v_stmt_clean, 'DBMS_SYS_SQL') > 0 OR
           INSTR(v_stmt_clean, 'ALTER SYSTEM') > 0 OR
           INSTR(v_stmt_clean, 'ALTER DATABASE') > 0 THEN
            p_out_status := 'REJECTED';
            p_out_error  := 'Clause interdite détectée dans l''instruction DDL.';
            
            INSERT INTO ZIRIOAM_OWNER.ZIRIOAM_EXECUTION_HISTORY
                (USERNAME, TARGET_SCHEMA, DDL_OPERATION, OBJECT_TYPE, OBJECT_NAME, DDL_STATEMENT, EXECUTION_STATUS, ORACLE_ERROR_MESSAGE, EXECUTED_AT)
            VALUES
                (v_caller, v_schema, v_op, v_obj_type, v_obj_name, p_ddl_statement, 'REJECTED', p_out_error, SYSTIMESTAMP);
            RETURN;
        END IF;

        -- 5. Exécution sécurisée de l'ordre DDL dans le schéma cible
        BEGIN
            EXECUTE IMMEDIATE p_ddl_statement;
            
            p_out_status := 'SUCCESS';
            p_out_error  := NULL;

            INSERT INTO ZIRIOAM_OWNER.ZIRIOAM_EXECUTION_HISTORY
                (USERNAME, TARGET_SCHEMA, DDL_OPERATION, OBJECT_TYPE, OBJECT_NAME, DDL_STATEMENT, EXECUTION_STATUS, EXECUTED_AT)
            VALUES
                (v_caller, v_schema, v_op, v_obj_type, v_obj_name, p_ddl_statement, 'SUCCESS', SYSTIMESTAMP);

        EXCEPTION
            WHEN OTHERS THEN
                p_out_status := 'FAILED';
                p_out_error  := SQLERRM;

                INSERT INTO ZIRIOAM_OWNER.ZIRIOAM_EXECUTION_HISTORY
                    (USERNAME, TARGET_SCHEMA, DDL_OPERATION, OBJECT_TYPE, OBJECT_NAME, DDL_STATEMENT, EXECUTION_STATUS, ORACLE_ERROR_CODE, ORACLE_ERROR_MESSAGE, EXECUTED_AT)
                VALUES
                    (v_caller, v_schema, v_op, v_obj_type, v_obj_name, p_ddl_statement, 'FAILED', SQLCODE, SQLERRM, SYSTIMESTAMP);
        END;

    END execute_controlled_ddl;

END PKG_ZIRIOAM_DDL_ENGINE;
/

