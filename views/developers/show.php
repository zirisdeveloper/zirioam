<?php
    $hasAccount = ((int)($developer['ORACLE_ACCOUNT_EXISTS'] ?? 0)) > 0;
    $oraStatus = $developer['ORACLE_ACCOUNT_STATUS'] ?? 'NON CRÉÉ';
    $isLocked = str_contains($oraStatus, 'LOCKED');
?>
<div class="py-2">
    <!-- En-tête -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="<?= zirioam_url('/developers') ?>">Développeurs</a></li>
                    <li class="breadcrumb-item active font-monospace fw-bold"><?= e($developer['USERNAME']) ?></li>
                </ol>
            </nav>
            <h2 class="h3 fw-bold text-dark mb-0">
                <?= e($developer['FULL_NAME']) ?>
                <span class="badge bg-secondary font-monospace ms-2"><?= e($developer['ORACLE_USER']) ?></span>
            </h2>
        </div>
        <div>
            <a href="<?= zirioam_url('/developers/' . (int)$developer['DEVELOPER_ID'] . '/edit') ?>" class="btn btn-outline-secondary btn-sm me-2">
                ✏️ Modifier l'identité
            </a>
            <a href="<?= zirioam_url('/developers') ?>" class="btn btn-outline-primary btn-sm">
                &larr; Retour à la liste
            </a>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <!-- 1. Fiche Identité ZIRIOAM -->
        <div class="col-12 col-lg-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3">
                    <h5 class="card-title mb-0 fw-bold text-dark">Informations d'Identité ZIRIOAM</h5>
                </div>
                <div class="card-body">
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item d-flex justify-content-between px-0 py-2">
                            <span class="text-muted">Identifiant :</span>
                            <strong class="font-monospace text-dark"><?= e($developer['USERNAME']) ?></strong>
                        </li>
                        <li class="list-group-item d-flex justify-content-between px-0 py-2">
                            <span class="text-muted">Compte Oracle Commun :</span>
                            <strong class="font-monospace text-dark"><?= e($developer['ORACLE_USER']) ?></strong>
                        </li>
                        <li class="list-group-item d-flex justify-content-between px-0 py-2">
                            <span class="text-muted">Nom complet :</span>
                            <span class="text-dark fw-semibold"><?= e($developer['FULL_NAME']) ?></span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between px-0 py-2">
                            <span class="text-muted">Email :</span>
                            <a href="mailto:<?= e($developer['EMAIL']) ?>"><?= e($developer['EMAIL']) ?></a>
                        </li>
                        <li class="list-group-item d-flex justify-content-between px-0 py-2">
                            <span class="text-muted">Statut Identité :</span>
                            <?php if ($developer['STATUS'] === 'ACTIVE'): ?>
                                <span class="badge bg-success">Actif</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Inactif</span>
                            <?php endif; ?>
                        </li>
                        <li class="list-group-item d-flex justify-content-between px-0 py-2">
                            <span class="text-muted">Créé le :</span>
                            <span class="text-muted small"><?= e($developer['CREATED_AT']) ?></span>
                        </li>
                    </ul>

                    <!-- Action d'activation / désactivation de l'identité -->
                    <div class="mt-3 pt-3 border-top">
                        <form action="<?= zirioam_url('/developers/' . (int)$developer['DEVELOPER_ID'] . '/status') ?>" method="POST" class="d-inline">
                            <?= zirioam_csrf_field() ?>
                            <?php if ($developer['STATUS'] === 'ACTIVE'): ?>
                                <input type="hidden" name="status" value="INACTIVE">
                                <button type="submit" class="btn btn-outline-warning btn-sm w-100" data-confirm="Désactiver cette identité développeur ?">
                                    Désactiver l'identité ZIRIOAM
                                </button>
                            <?php else: ?>
                                <input type="hidden" name="status" value="ACTIVE">
                                <button type="submit" class="btn btn-outline-success btn-sm w-100">
                                    Réactiver l'identité ZIRIOAM
                                </button>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. Gestion du Compte Oracle Commun CDB (Section 10 & 11) -->
        <div class="col-12 col-lg-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0 fw-bold text-dark">Compte Oracle Commun (CDB$ROOT)</h5>
                    <span class="badge <?= $hasAccount ? ($isLocked ? 'bg-danger' : 'bg-success') : 'bg-secondary' ?> px-2 py-1">
                        <?= e($oraStatus) ?>
                    </span>
                </div>
                <div class="card-body">
                    <?php if (!$hasAccount): ?>
                        <div class="alert alert-warning border-warning p-3 mb-3">
                            <h6 class="fw-bold text-dark mb-1">Compte Oracle non encore provisionné</h6>
                            <p class="small mb-0 text-muted">
                                Ce développeur est enregistré dans le référentiel ZIRIOAM mais ne possède pas encore de compte utilisateur physique dans Oracle 19c.
                            </p>
                        </div>
                        <form action="<?= zirioam_url('/developers/' . (int)$developer['DEVELOPER_ID'] . '/provision') ?>" method="POST" class="p-3 border rounded bg-light mb-4">
                            <?= zirioam_csrf_field() ?>
                            <div class="mb-3">
                                <label class="form-label fw-bold small text-dark mb-2">Choix du mot de passe initial :</label>
                                <div class="form-check mb-1">
                                    <input class="form-check-input" type="radio" name="password_mode" id="prov_auto" value="auto" checked onchange="document.getElementById('prov_manual_wrap').classList.add('d-none'); document.getElementById('prov_custom_pwd').required = false;">
                                    <label class="form-check-label small" for="prov_auto">Génération automatique sécurisée (14 car., recommandé)</label>
                                </div>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="radio" name="password_mode" id="prov_manual" value="manual" onchange="document.getElementById('prov_manual_wrap').classList.remove('d-none'); document.getElementById('prov_custom_pwd').required = true;">
                                    <label class="form-check-label small" for="prov_manual">Saisie manuelle d'un mot de passe</label>
                                </div>
                                <div id="prov_manual_wrap" class="d-none mt-2">
                                    <input type="password" name="custom_password" id="prov_custom_pwd" class="form-control form-control-sm" placeholder="Min. 8 car. (Majuscule, minuscule, chiffre)">
                                </div>
                            </div>

                            <?php 
                            $sessVaultUser = (string)\App\Core\App::getInstance()->getSession()->get('vault_user', ''); 
                            $sessVaultPass = (string)\App\Core\App::getInstance()->getSession()->get('vault_password', '');
                            $hasActiveVault = !empty($sessVaultUser) && !empty($sessVaultPass);
                            $sessVault = $sessVaultUser;
                            ?>
                            <?php if ($hasActiveVault): ?>
                                <div class="alert alert-light border small py-2 mb-3 d-flex align-items-center">
                                    <span class="badge bg-success me-2">🛡️ Database Vault</span>
                                    <span>Session active via <strong><?= e($sessVaultUser) ?></strong></span>
                                </div>
                            <?php else: ?>
                                <div class="border rounded p-2 mb-3 bg-white">
                                    <span class="badge bg-secondary mb-1">🛡️ Option Database Vault</span>
                                    <div class="text-muted extra-small mb-2" style="font-size:0.8rem;">
                                        Requis si Database Vault protège la création d'utilisateurs.
                                    </div>
                                    <div class="row g-2">
                                        <div class="col-sm-6">
                                            <input type="text" name="vault_user" class="form-control form-control-sm font-monospace text-uppercase" placeholder="C##VOLTMANAGER" value="<?= e($sessVaultUser ?: 'C##VOLTMANAGER') ?>">
                                        </div>
                                        <div class="col-sm-6">
                                            <input type="password" name="vault_password" class="form-control form-control-sm" placeholder="Mot de passe Vault">
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <button type="submit" class="btn btn-primary py-2 w-100 fw-bold" data-confirm="Créer le compte Oracle commun C##<?= e($developer['USERNAME']) ?> avec CONTAINER = ALL ?">
                                ➕ Créer le Compte Oracle Commun (CDB$ROOT) &rarr;
                            </button>
                        </form>
                    <?php else: ?>
                        <div class="p-3 bg-light rounded border mb-3">
                            <div class="row g-2">
                                <div class="col-sm-6">
                                    <span class="text-muted small d-block">Utilisateur Oracle :</span>
                                    <strong class="font-monospace fs-5 text-dark"><?= e($developer['ORACLE_USER']) ?></strong>
                                </div>
                                <div class="col-sm-6">
                                    <span class="text-muted small d-block">Statut du Compte :</span>
                                    <span class="badge <?= $isLocked ? 'bg-danger' : 'bg-success' ?> fs-6"><?= e($oraStatus) ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Boutons d'Action sur le Compte Oracle -->
                        <div class="d-flex flex-wrap gap-2 mb-4">
                            <!-- Bouton ouvrant le modal de Réinitialisation -->
                            <button type="button" class="btn btn-danger fw-bold" data-bs-toggle="modal" data-bs-target="#resetPasswordModal">
                                🔑 Réinitialiser le Mot de Passe
                            </button>

                            <!-- Verrouillage / Déverrouillage Oracle -->
                            <?php if ($isLocked): ?>
                                <button type="button" class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#unlockModal">
                                    🔓 Déverrouiller le compte
                                </button>
                            <?php else: ?>
                                <button type="button" class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#lockModal">
                                    🔒 Verrouiller le compte
                                </button>
                            <?php endif; ?>

                            <!-- Lien vers l'attribution de droits sur PDB -->
                            <a href="<?= zirioam_url('/access') ?>" class="btn btn-outline-primary">
                                Gérer les droits sur les PDB &rarr;
                            </a>

                            <!-- Blocage Connexion CDB$ROOT (Isolation stricte PDB) -->
                            <form action="<?= zirioam_url('/developers/' . (int)$developer['DEVELOPER_ID'] . '/strip-cdb') ?>" method="POST" class="d-inline" onsubmit="return confirm('Confirmer la révocation du privilège CREATE SESSION dans CDB$ROOT ? Le développeur ne pourra se connecter qu\'aux PDBs autorisées.');">
                                <?= zirioam_csrf_field() ?>
                                <button type="submit" class="btn btn-outline-secondary" title="Révoquer CREATE SESSION dans CDB$ROOT (isolation stricte PDB)">
                                    🛡️ Bloquer Accès CDB$ROOT
                                </button>
                            </form>
                        </div>

                        <div class="alert alert-secondary small mb-0 border">
                            <h6 class="fw-bold mb-1">Règles fondamentales Oracle :</h6>
                            <ul class="mb-0 ps-3">
                                <li><strong>Isolation stricte PDB :</strong> Le développeur ne dispose pas du privilège <code>CREATE SESSION</code> dans <code>CDB$ROOT</code>. La connexion n'est autorisée que localement dans les PDBs où un profil d'accès lui est explicitement attribué.</li>
                                <li>La réinitialisation s'applique avec <code>PASSWORD EXPIRE CONTAINER = ALL</code>.</li>
                                <li>Le développeur devra changer son mot de passe dès sa première connexion (SQL Developer / SQL*Plus).</li>
                                <li>Aucun mot de passe n'est enregistré dans les bases ZIRIOAM.</li>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- 3. Historique d'Audit du Développeur (Section 16) -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
            <h5 class="card-title mb-0 fw-bold text-dark">Historique des Opérations Administratives</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-zirioam align-middle mb-0 small">
                <thead>
                    <tr>
                        <th style="width: 170px;">Date & Heure</th>
                        <th>Opération</th>
                        <th>DBA Exécutant</th>
                        <th>Détails</th>
                        <th class="text-end">Statut</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($history)): ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted py-3">
                                Aucun événement d'audit enregistré pour ce développeur.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($history as $h): ?>
                            <tr>
                                <td class="font-monospace text-muted"><?= e($h['EVENT_TIME']) ?></td>
                                <td><strong><?= e($h['ACTION_TYPE']) ?></strong></td>
                                <td><span class="badge bg-light text-dark border"><?= e($h['DBA_USER']) ?></span></td>
                                <td><?= e($h['DETAILS']) ?></td>
                                <td class="text-end">
                                    <span class="badge <?= $h['STATUS'] === 'SUCCESS' ? 'bg-success' : 'bg-danger' ?>">
                                        <?= e($h['STATUS']) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Réinitialisation Mot de Passe -->
<div class="modal fade" id="resetPasswordModal" tabindex="-1" aria-labelledby="resetPasswordModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="<?= zirioam_url('/developers/' . (int)$developer['DEVELOPER_ID'] . '/password-reset') ?>" method="POST">
                <?= zirioam_csrf_field() ?>
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title fw-bold" id="resetPasswordModalLabel">🔑 Réinitialiser le Mot de Passe</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fermer"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">
                        Utilisateur cible : <strong class="font-monospace text-danger"><?= e($developer['ORACLE_USER']) ?></strong>
                    </p>

                    <div class="mb-3">
                        <label class="form-label fw-bold small text-dark mb-2">Méthode d'attribution :</label>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio" name="password_mode" id="rst_mode_auto" value="auto" checked 
                                   onchange="document.getElementById('rst_manual_wrap').classList.add('d-none'); document.getElementById('rst_custom_pwd').required = false;">
                            <label class="form-check-label" for="rst_mode_auto">
                                <strong>Génération automatique robuste</strong> (14 car., hautement sécurisé)
                            </label>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="radio" name="password_mode" id="rst_mode_manual" value="manual"
                                   onchange="document.getElementById('rst_manual_wrap').classList.remove('d-none'); document.getElementById('rst_custom_pwd').required = true;">
                            <label class="form-check-label" for="rst_mode_manual">
                                <strong>Saisir un mot de passe manuellement</strong>
                            </label>
                        </div>

                        <div id="rst_manual_wrap" class="d-none mb-3">
                            <label for="rst_custom_pwd" class="form-label small fw-semibold">Nouveau mot de passe temporaire</label>
                            <input type="password" name="custom_password" id="rst_custom_pwd" class="form-control" 
                                   placeholder="Min. 8 car. (Majuscule, minuscule, chiffre)" autocomplete="new-password">
                            <div class="form-text small">Conforme aux règles de complexité Oracle.</div>
                        </div>
                    </div>

                    <?php if ($hasActiveVault): ?>
                        <div class="alert alert-light border small py-2 mb-3">
                            <div class="d-flex align-items-center">
                                <span class="badge bg-success me-2">🛡️ Database Vault</span>
                                <span>Session active via <strong><?= e($sessVaultUser) ?></strong></span>
                                <a class="ms-auto text-decoration-none small text-primary" data-bs-toggle="collapse" href="#collapseRstVault" role="button">
                                    Changer &darr;
                                </a>
                            </div>
                            <div class="collapse mt-2 pt-2 border-top" id="collapseRstVault">
                                <div class="row g-2">
                                    <div class="col-sm-6">
                                        <label class="form-label extra-small text-muted mb-0">Utilisateur Vault :</label>
                                        <input type="text" name="vault_user" class="form-control form-control-sm font-monospace text-uppercase" value="<?= e($sessVaultUser) ?>">
                                    </div>
                                    <div class="col-sm-6">
                                        <label class="form-label extra-small text-muted mb-0">Nouveau mot de passe :</label>
                                        <input type="password" name="vault_password" class="form-control form-control-sm" placeholder="Laisser vide si inchangé">
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="border rounded p-3 mb-3 bg-light">
                            <div class="d-flex align-items-center mb-1">
                                <span class="badge bg-secondary me-2">🛡️ Database Vault</span>
                                <span class="small fw-bold text-dark">Identifiants Gestionnaire (Requis sous Database Vault)</span>
                            </div>
                            <div class="text-muted extra-small mb-2" style="font-size: 0.8rem;">
                                Sous Database Vault, le compte SYS n'a pas les droits pour modifier les comptes communs. Renseignez le compte habilité (ex: <code>C##VOLTMANAGER</code>).
                            </div>
                            <div class="row g-2">
                                <div class="col-sm-6">
                                    <label class="form-label small fw-semibold mb-1">Compte Vault Manager :</label>
                                    <input type="text" name="vault_user" class="form-control form-control-sm font-monospace text-uppercase" placeholder="C##VOLTMANAGER" value="<?= e($sessVaultUser ?: 'C##VOLTMANAGER') ?>">
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label small fw-semibold mb-1">Mot de passe Vault :</label>
                                    <input type="password" name="vault_password" class="form-control form-control-sm" placeholder="Mot de passe C##VOLTMANAGER">
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="alert alert-warning small mb-0 py-2">
                        ⚠️ La clause <strong>PASSWORD EXPIRE CONTAINER = ALL</strong> sera appliquée. Le mot de passe vous sera révélé <strong>une seule fois</strong> après validation.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-danger fw-bold">Valider la réinitialisation</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Verrouillage Compte Oracle -->
<div class="modal fade" id="lockModal" tabindex="-1" aria-labelledby="lockModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="<?= zirioam_url('/developers/' . (int)$developer['DEVELOPER_ID'] . '/lock') ?>" method="POST">
                <?= zirioam_csrf_field() ?>
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title fw-bold" id="lockModalLabel">🔒 Verrouiller le Compte Oracle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">
                        Êtes-vous sûr de vouloir verrouiller le compte Oracle commun <strong class="font-monospace text-dark"><?= e($developer['ORACLE_USER']) ?></strong> ?
                    </p>
                    <div class="alert alert-secondary small py-2 mb-3">
                        Le compte sera verrouillé dans <code>CDB$ROOT</code> et le privilège <code>CREATE SESSION</code> sera révoqué dans toutes les PDBs ouvertes pour interdire immédiatement toute connexion.
                    </div>

                    <?php if ($hasActiveVault): ?>
                        <div class="alert alert-light border small py-2 mb-3">
                            <div class="d-flex align-items-center">
                                <span class="badge bg-success me-2">🛡️ Database Vault</span>
                                <span>Session active via <strong><?= e($sessVaultUser) ?></strong></span>
                                <a class="ms-auto text-decoration-none small text-primary" data-bs-toggle="collapse" href="#collapseLockVault" role="button">
                                    Changer &darr;
                                </a>
                            </div>
                            <div class="collapse mt-2 pt-2 border-top" id="collapseLockVault">
                                <div class="row g-2">
                                    <div class="col-sm-6">
                                        <label class="form-label extra-small text-muted mb-0">Utilisateur Vault :</label>
                                        <input type="text" name="vault_user" class="form-control form-control-sm font-monospace text-uppercase" value="<?= e($sessVaultUser) ?>">
                                    </div>
                                    <div class="col-sm-6">
                                        <label class="form-label extra-small text-muted mb-0">Nouveau mot de passe :</label>
                                        <input type="password" name="vault_password" class="form-control form-control-sm" placeholder="Laisser vide si inchangé">
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="border rounded p-3 mb-3 bg-light">
                            <div class="d-flex align-items-center mb-1">
                                <span class="badge bg-secondary me-2">🛡️ Database Vault</span>
                                <span class="small fw-bold text-dark">Identifiants Gestionnaire (Obligatoire sous Database Vault)</span>
                            </div>
                            <div class="text-muted extra-small mb-2" style="font-size: 0.8rem;">
                                Requis pour verrouiller un compte commun dans toute la CDB (<code>CDB$ROOT</code> et PDBs). Le compte SYS n'a pas les droits sous Database Vault.
                            </div>
                            <div class="row g-2">
                                <div class="col-sm-6">
                                    <label class="form-label small fw-semibold mb-1">Compte Vault Manager :</label>
                                    <input type="text" name="vault_user" class="form-control form-control-sm font-monospace text-uppercase" placeholder="C##VOLTMANAGER" value="<?= e($sessVaultUser ?: 'C##VOLTMANAGER') ?>" required>
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label small fw-semibold mb-1">Mot de passe Vault :</label>
                                    <input type="password" name="vault_password" class="form-control form-control-sm" placeholder="Mot de passe C##VOLTMANAGER" required autocomplete="current-password">
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-warning fw-bold">Confirmer le Verrouillage</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Déverrouillage Compte Oracle -->
<div class="modal fade" id="unlockModal" tabindex="-1" aria-labelledby="unlockModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="<?= zirioam_url('/developers/' . (int)$developer['DEVELOPER_ID'] . '/unlock') ?>" method="POST">
                <?= zirioam_csrf_field() ?>
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title fw-bold" id="unlockModalLabel">🔓 Déverrouiller le Compte Oracle</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fermer"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">
                        Déverrouiller l'accès pour le compte Oracle commun <strong class="font-monospace text-dark"><?= e($developer['ORACLE_USER']) ?></strong> ?
                    </p>
                    <div class="alert alert-secondary small py-2 mb-3">
                        Le compte sera déverrouillé dans <code>CDB$ROOT</code> et le privilège <code>CREATE SESSION</code> sera réactivé dans toutes les PDBs ouvertes.
                    </div>

                    <?php if ($hasActiveVault): ?>
                        <div class="alert alert-light border small py-2 mb-3">
                            <div class="d-flex align-items-center">
                                <span class="badge bg-success me-2">🛡️ Database Vault</span>
                                <span>Session active via <strong><?= e($sessVaultUser) ?></strong></span>
                                <a class="ms-auto text-decoration-none small text-primary" data-bs-toggle="collapse" href="#collapseUnlockVault" role="button">
                                    Changer &darr;
                                </a>
                            </div>
                            <div class="collapse mt-2 pt-2 border-top" id="collapseUnlockVault">
                                <div class="row g-2">
                                    <div class="col-sm-6">
                                        <label class="form-label extra-small text-muted mb-0">Utilisateur Vault :</label>
                                        <input type="text" name="vault_user" class="form-control form-control-sm font-monospace text-uppercase" value="<?= e($sessVaultUser) ?>">
                                    </div>
                                    <div class="col-sm-6">
                                        <label class="form-label extra-small text-muted mb-0">Nouveau mot de passe :</label>
                                        <input type="password" name="vault_password" class="form-control form-control-sm" placeholder="Laisser vide si inchangé">
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="border rounded p-3 mb-3 bg-light">
                            <div class="d-flex align-items-center mb-1">
                                <span class="badge bg-secondary me-2">🛡️ Option Database Vault</span>
                                <span class="small fw-bold text-dark">Identifiants Gestionnaire (Obligatoire sous Database Vault)</span>
                            </div>
                            <div class="text-muted extra-small mb-2" style="font-size: 0.8rem;">
                                Requis pour déverrouiller un compte commun dans toute la CDB (<code>CDB$ROOT</code> et PDBs). Le compte SYS n'a pas les droits sous Database Vault.
                            </div>
                            <div class="row g-2">
                                <div class="col-sm-6">
                                    <label class="form-label small fw-semibold mb-1">Compte Vault Manager :</label>
                                    <input type="text" name="vault_user" class="form-control form-control-sm font-monospace text-uppercase" placeholder="C##VOLTMANAGER" value="<?= e($sessVaultUser ?: 'C##VOLTMANAGER') ?>" required>
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label small fw-semibold mb-1">Mot de passe Vault :</label>
                                    <input type="password" name="vault_password" class="form-control form-control-sm" placeholder="Mot de passe C##VOLTMANAGER" required autocomplete="current-password">
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-success fw-bold">Confirmer le Déverrouillage</button>
                </div>
            </form>
        </div>
    </div>
</div>

