-- =====================================================================
-- ZIRI OAM — Oracle Developer Access Manager
-- Script : 003_create_sequences.sql
-- Cible  : PDB Locale (Schéma ZIRIOAM_OWNER)
-- Objet  : Séquences applicatives pour la PDB
-- Idempotent : Oui
-- =====================================================================

DECLARE
    v_count NUMBER;
BEGIN
    -- Séquence pour numérotation des tickets d'exécution DDL
    SELECT COUNT(*) INTO v_count FROM ALL_SEQUENCES 
    WHERE SEQUENCE_OWNER = 'ZIRIOAM_OWNER' AND SEQUENCE_NAME = 'SEQ_ZIRIOAM_DDL_REQ';
    
    IF v_count = 0 THEN
        EXECUTE IMMEDIATE 'CREATE SEQUENCE ZIRIOAM_OWNER.SEQ_ZIRIOAM_DDL_REQ START WITH 1 INCREMENT BY 1 NOCACHE NOCYCLE';
    END IF;

    -- Séquence pour génération des identifiants d'audit local
    SELECT COUNT(*) INTO v_count FROM ALL_SEQUENCES 
    WHERE SEQUENCE_OWNER = 'ZIRIOAM_OWNER' AND SEQUENCE_NAME = 'SEQ_ZIRIOAM_AUDIT';
    
    IF v_count = 0 THEN
        EXECUTE IMMEDIATE 'CREATE SEQUENCE ZIRIOAM_OWNER.SEQ_ZIRIOAM_AUDIT START WITH 1000 INCREMENT BY 1 NOCACHE NOCYCLE';
    END IF;
END;
/

