# ZIRI OAM — Oracle Developer Access Manager

[![Oracle 19c](https://img.shields.io/badge/Oracle-19c%20Multitenant-C73826?logo=oracle)](https://www.oracle.com/database/)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php)](https://www.php.net)
[![OCI8](https://img.shields.io/badge/Extension-OCI8-blue)](https://pecl.php.net/package/oci8)
[![Bootstrap](https://img.shields.io/badge/Frontend-Bootstrap%205-7952B3?logo=bootstrap)](https://getbootstrap.com)
[![License](https://img.shields.io/badge/License-Proprietary-darkgreen)]()

**ZIRI OAM** (*Oracle Developer Access Manager*) est une plateforme Web d'entreprise conçue pour les Administrateurs de Bases de Données (DBA) afin d'administrer de manière centralisée, sécurisée et traçable les comptes développeurs, leurs droits et leurs privilèges sur plusieurs serveurs **Oracle Database 19c**, dans une architecture multitenant (**CDB/PDB**).

---

## 🌟 Points Forts & Garanties d'Architecture

1. **Aucune Dépendance envers une Base Centrale**
   * L'application démarre immédiatement à partir d'un fichier de référentiel JSON externe (`/etc/zirioam/servers.json`) placé strictement hors de la racine Web.
   * Protection anti-SSRF : seules les connexions vers les serveurs explicitement déclarés sont permises.
2. **Ségrégation Stricte des Conteneurs Oracle 19c**
   * **Niveau CDB$ROOT (`C##ZIRIOAM_ADMIN`)** : Référentiel des identités (`ZIRIOAM_DEVELOPERS`), inventaire des installations, gestion des comptes communs et réinitialisation des mots de passe.
   * **Niveau PDB Locale (`ZIRIOAM_OWNER`)** : Gestion locale des rôles fonctionnels, matrices d'accès aux schémas applicatifs (`GESTION`, `FINANCE`), moteur DDL contrôlé et audit local.
3. **Sécurité Hermétique des Comptes Développeurs**
   * Les comptes communs (`C##...`) sont créés avec `CONTAINER = ALL` dans la CDB.
   * **Règle absolue** : Aucun privilège `CREATE SESSION` n'est accordé avec `CONTAINER = ALL`. Le droit de connexion est accordé **exclusivement dans les PDBs autorisées**, interdisant tout accès au `CDB$ROOT` ou aux PDBs non habilitées.
4. **Séparation Physique Totale DDL / DML**
   * Un développeur peut avoir le droit de créer des tables dans un schéma partagé sans avoir le moindre droit de lecture ou de modification sur les données.
   * **Zéro privilège `ANY`** : L'accès DDL sur les schémas d'autres utilisateurs s'exécute via le package PL/SQL `PKG_ZIRIOAM_DDL_ENGINE` (`AUTHID DEFINER`), avec contrôle d'identité, validation syntaxique stricte et journalisation préalable.
5. **Gestion Éphémère et Sécurisée des Mots de Passe**
   * Génération aléatoire cryptographique (`random_bytes`).
   * Ordre Oracle : `ALTER USER C##... IDENTIFIED BY "..." PASSWORD EXPIRE CONTAINER = ALL;`.
   * **Révélation unique** dans l'interface Web du DBA (flash message éphémère). Aucun mot de passe n'est jamais enregistré en clair dans la base, les logs ou les URL.
6. **Importation JSON Règle Absolue ADD ONLY**
   * L'importation ne modifie, n'écrase, ne désactive ni ne supprime **JAMAIS** un développeur déjà présent sur le serveur cible.
   * L'import n'ajoute que les nouveaux développeurs, ne crée aucun compte Oracle automatique et n'attribue aucun privilège implicite.
   * Idempotent : l'import répété du même fichier donne un résultat identique sans doublon.
7. **Assistant d'Installation Idempotent et Réparable**
   * Détecteur d'état automatique (`NOT_INSTALLED`, `INSTALLED`, `INCOMPLETE`, `INVALID`, `OUTDATED`).
   * Support des commits implicites DDL Oracle avec journalisation étape par étape et reprise après incident.

---

## 📂 Structure du Projet

```
zirioam/
├── app/
│   ├── Controllers/          # Contrôleurs MVC (Auth, Dashboard, Développeurs, Rôles, DDL, Audit)
│   ├── Core/                 # Noyau applicatif léger (App, Router, Request, Response, Session, View)
│   ├── Database/             # Abstraction Oracle OCI8 et exceptions ORA-xxxxx
│   ├── Helpers/              # Helpers d'URL, de sécurité XSS et de validation Oracle
│   ├── Middleware/           # Protection CSRF et vérification de session Oracle
│   ├── Security/             # Chiffrement AES-256-GCM, validation JSON et tokens
│   └── Services/             # Logique métier pure (Registry, Discovery, Installer, DDL Engine, Audit)
├── config/
│   ├── app.php               # Configuration générale et chemin de déploiement (dep_path)
│   ├── servers.json          # Référentiel actif des serveurs (hors webroot)
│   └── servers.example.json  # Modèle d'exemple pour /etc/zirioam/servers.json
├── installer/
│   ├── manifest.json         # Manifeste avec empreintes SHA-256 des scripts
│   ├── common/               # Scripts SQL CDB$ROOT (C##ZIRIOAM_ADMIN)
│   ├── pdb/                  # Scripts SQL PDB (ZIRIOAM_OWNER)
│   ├── upgrades/             # Scripts de mise à jour incrémentale
│   └── checks/               # Scripts de diagnostic et vérification des privilèges
├── public/
│   ├── index.php             # Contrôleur frontal avec autoloader PSR-4 natif
│   ├── .htaccess             # Réécriture d'URL Apache mod_rewrite et en-têtes de sécurité
│   └── assets/               # CSS Bootstrap 5, JS natif et thème professionnel
├── tests/                    # Suite de tests unitaires et d'intégration
├── views/                    # Vues PHP Bootstrap 5 et layouts responsive
├── composer.json             # Définition du paquet et métadonnées
├── INSTALL.md                # Guide de déploiement pas-à-pas sous Linux Rocky/RHEL
└── README.md                 # Documentation générale
```

---

## 🚀 Démarrage Rapide

### 1. Prérequis
* PHP 8.2 ou supérieur avec les extensions `oci8`, `openssl`, `mbstring`, `json`.
* Oracle Database 19c (Enterprise ou Standard Edition 2) avec architecture multitenant.
* Oracle Instant Client 19c installé sur le serveur Web.
* Apache 2.4 avec `mod_rewrite` et `mod_headers`.

### 2. Configuration du Référentiel des Serveurs
Copiez le modèle vers l'emplacement sécurisé de votre choix (ex: `/etc/zirioam/servers.json`) :
```bash
sudo mkdir -p /etc/zirioam
sudo cp config/servers.example.json /etc/zirioam/servers.json
sudo chmod 640 /etc/zirioam/servers.json
sudo chown root:apache /etc/zirioam/servers.json
```

### 3. Exécution de la Suite de Tests
```bash
php tests/run_tests.php
```

---

## 🛡️ Sécurité & Conformité

* **Sessions chiffrées** : Les identifiants administratifs de session sont protégés en mémoire par chiffrement symétrique OpenSSL AES-256-GCM.
* **Protection CSRF** : Tous les formulaires `POST` exigent un jeton cryptographique validé en temps constant (`hash_equals`).
* **Protection XSS** : Toutes les sorties HTML sont échappées via `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.
* **Traçabilité** : Chaque opération administrative (connexion, provisionnement, réinitialisation de mot de passe, exécution DDL, attribution de rôles) est inscrite dans `ZIRIOAM_CDB_AUDIT` et `ZIRIOAM_AUDIT_LOG`.

---

## 👥 Licence & Droits
Tous droits réservés &bull; Développé par Mahfoud Bouziri pour les environnements de production d'entreprise Oracle Database 19c.
