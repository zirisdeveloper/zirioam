# Guide de Déploiement et d'Installation de ZIRI OAM
## Environnement Cible : Linux (Rocky Linux 8/9, RHEL 8/9, AlmaLinux)

Ce document décrit pas à pas la procédure de déploiement en production de **ZIRI OAM — Oracle Developer Access Manager**.

---

## 1. Prérequis Système

* **Système d'exploitation** : Rocky Linux 8 ou 9 / RHEL 8 ou 9 / AlmaLinux 8 ou 9.
* **Serveur Web** : Apache 2.4 (`httpd`) avec `mod_rewrite` et `mod_headers`.
* **PHP** : Version 8.2 ou supérieure.
* **Extension Oracle** : `php-oci8` (ou compilation via PECL).
* **Client Oracle** : Oracle Instant Client 19c (Basic + Devel).
* **SGBD Cible** : Oracle Database 19c Multitenant (CDB/PDB).

---

## 2. Installation d'Oracle Instant Client 19c

Téléchargez et installez les paquets RPM officiels d'Oracle Instant Client 19c :

```bash
# Téléchargement depuis Oracle Technology Network
sudo dnf install -y libaio

# Installation des RPMs Oracle Instant Client 19c
sudo rpm -ivh oracle-instantclient19.19-basic-19.19.0.0.0-1.x86_64.rpm
sudo rpm -ivh oracle-instantclient19.19-devel-19.19.0.0.0-1.x86_64.rpm

# Configuration des variables d'environnement système
sudo sh -c "echo /usr/lib/oracle/19.19/client64/lib > /etc/ld.so.conf.d/oracle-instantclient.conf"
sudo ldconfig
```

---

## 3. Installation de PHP 8.2 et de l'Extension OCI8

Activez le dépôt EPEL et Remi pour installer PHP 8.2 :

```bash
# Installation des dépôts
sudo dnf install -y epel-release
sudo dnf install -y https://rpms.remirepo.net/enterprise/remi-release-$(rpm -E %rhel).rpm

# Activation du module PHP 8.2
sudo dnf module reset php -y
sudo dnf module enable php:remi-8.2 -y

# Installation de PHP et des dépendances de base
sudo dnf install -y php php-cli php-fpm php-common php-mbstring php-json php-xml php-opcache php-pear php-devel

# Installation de l'extension OCI8 pour Oracle Instant Client 19c
sudo dnf install -y php-oci8-19c
# Ou via PECL si compilation manuelle :
# echo "instantclient,/usr/lib/oracle/19.19/client64/lib" | sudo pecl install oci8
```

Vérifiez le bon chargement de l'extension OCI8 :
```bash
php -m | grep -i oci8
# Doit retourner : oci8
```

---

## 4. Déploiement du Code ZIRI OAM

Déployez l'application dans le répertoire web d'Apache :

```bash
# Création du répertoire d'application
sudo mkdir -p /var/www/html/zirioam
sudo cp -r . /var/www/html/zirioam/

# Attribution des droits à l'utilisateur Apache
sudo chown -R apache:apache /var/www/html/zirioam
sudo chmod -R 750 /var/www/html/zirioam
sudo chmod -R 770 /var/www/html/zirioam/storage
```

---

## 5. Configuration du Référentiel des Serveurs JSON

Le référentiel des serveurs doit obligatoirement être situé **hors du répertoire Web** public :

```bash
# Création du dossier sécurisé
sudo mkdir -p /etc/zirioam

# Copie du modèle de configuration
sudo cp /var/www/html/zirioam/config/servers.example.json /etc/zirioam/servers.json

# Permissions restrictives : lecture seule par Apache
sudo chown root:apache /etc/zirioam/servers.json
sudo chmod 640 /etc/zirioam/servers.json
```

Éditez le fichier `/etc/zirioam/servers.json` pour déclarer vos serveurs Oracle de production, de test et de développement :
```json
{
  "servers": [
    {
      "server_id": "ORA_PROD_01",
      "server_name": "ORACLE-PROD-01",
      "ip_address": "192.168.10.11",
      "port": 1521,
      "environment": "PRODUCTION",
      "active": true,
      "cdb_services": [
        {
          "cdb_name": "CDB_PROD",
          "root_service": "cdbprod.company.local"
        }
      ]
    }
  ]
}
```

---

## 6. Configuration du Serveur Apache

### Option A : Déploiement en Sous-Chemin (`/zirioam`)
Si l'application est servie sous un chemin personnalisé tel que `http://serveur/zirioam/` :

Éditez `config/app.php` (ou définissez la variable d'environnement `ZIRIOAM_DEP_PATH=/zirioam`).

Créez le fichier de configuration Apache `/etc/httpd/conf.d/zirioam.conf` :
```apache
Alias /zirioam /var/www/html/zirioam/public

<Directory /var/www/html/zirioam/public>
    Options -Indexes +FollowSymLinks
    AllowOverride All
    Require all granted

    # En-têtes de sécurité
    Header always set X-Frame-Options "DENY"
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-XSS-Protection "1; mode=block"

    SetEnv ZIRIOAM_SERVERS_JSON "/etc/zirioam/servers.json"
    SetEnv ZIRIOAM_DEP_PATH "/zirioam"
</Directory>

# Protection stricte des répertoires sensibles
<Directory /var/www/html/zirioam/config>
    Require all denied
</Directory>
<Directory /var/www/html/zirioam/storage>
    Require all denied
</Directory>
<Directory /var/www/html/zirioam/installer>
    Require all denied
</Directory>
```

### Option B : Déploiement en VirtualHost Dédié (Racine `/`)
```apache
<VirtualHost *:80>
    ServerName zirioam.company.local
    DocumentRoot /var/www/html/zirioam/public

    <Directory /var/www/html/zirioam/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        SetEnv ZIRIOAM_SERVERS_JSON "/etc/zirioam/servers.json"
        SetEnv ZIRIOAM_DEP_PATH ""
    </Directory>

    ErrorLog logs/zirioam_error.log
    CustomLog logs/zirioam_access.log combined
</VirtualHost>
```

---

## 7. Configuration de SELinux et du Pare-feu

Sur Rocky Linux / RHEL, autorisez le serveur Web Apache à initier des connexions réseau vers les serveurs Oracle distants (port 1521) :

```bash
# Autoriser les connexions réseau sortantes pour Apache
sudo setsebool -P httpd_can_network_connect 1
sudo setsebool -P httpd_can_network_connect_db 1

# Si SELinux est actif, appliquer les contextes adéquats
sudo semanage fcontext -a -t httpd_sys_content_t "/var/www/html/zirioam(/.*)?"
sudo semanage fcontext -a -t httpd_sys_rw_content_t "/var/www/html/zirioam/storage(/.*)?"
sudo restorecon -Rv /var/www/html/zirioam

# Autoriser le trafic Web sur le pare-feu
sudo firewall-cmd --permanent --add-service=http
sudo firewall-cmd --permanent --add-service=https
sudo firewall-cmd --reload
```

Redémarrez Apache et PHP-FPM :
```bash
sudo systemctl restart php-fpm
sudo systemctl restart httpd
sudo systemctl enable httpd php-fpm
```

---

## 8. Validation Initiale

1. Ouvrez votre navigateur Web sur : `http://votre-serveur/zirioam/`
2. La page d'accueil affiche les serveurs configurés dans `/etc/zirioam/servers.json`.
3. Cliquez sur **SE CONNECTER** sur le premier serveur.
4. Saisissez vos identifiants DBA :
   * Pour la première installation : utilisateur `SYS` avec le mode **SYSDBA**.
5. L'application détecte automatiquement que le serveur est nouveau et affiche :
   > *"Ceci est un nouveau serveur, veuillez cliquer sur le bouton CONFIGURER pour l'initialisation du système de gestion des développeurs."*
6. Cliquez sur **CONFIGURER**, validez les prérequis et lancez l'initialisation.
7. Dès l'initialisation terminée, accédez au tableau de bord complet pour gérer vos développeurs, créer les rôles et administrer les accès DDL/DML.

