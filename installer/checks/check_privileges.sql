-- =====================================================================
-- ZIRI OAM — Oracle Developer Access Manager
-- Script : check_privileges.sql
-- Objet  : Vérification des privilèges administratifs requis
-- =====================================================================

SELECT PRIVILEGE, ADMIN_OPTION FROM USER_SYS_PRIVS 
WHERE PRIVILEGE IN ('CREATE USER', 'ALTER USER', 'CREATE ROLE', 'GRANT ANY ROLE', 'CREATE TABLE')
UNION ALL
SELECT ROLE, ADMIN_OPTION FROM USER_ROLE_PRIVS 
WHERE ROLE IN ('DBA', 'SYSDBA', 'SELECT_CATALOG_ROLE');

