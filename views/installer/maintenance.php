<div class="container py-4">
    <!-- En-tête -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h2 class="h3 fw-bold text-dark mb-0">🛠️ Maintenance & Réexécution des Composants ZIRIOAM</h2>
            <p class="text-muted small mb-0">
                Serveur : <strong><?= e($server['server_name'] ?? 'N/A') ?></strong> &bull; 
                Recompilation, mise à jour des packages et réapplication des autorisations
            </p>
        </div>
        <div>
            <a href="<?= zirioam_url('/installer/status?view=1') ?>" class="btn btn-outline-secondary btn-sm me-2">
                📋 Voir l'état des composants
            </a>
            <a href="<?= zirioam_url('/dashboard') ?>" class="btn btn-outline-primary btn-sm">
                &larr; Retour au tableau de bord
            </a>
        </div>
    </div>

    <!-- Affichage des résultats d'exécution si soumis -->
    <?php if (!empty($results)): ?>
        <div class="card border-0 shadow-sm rounded-3 mb-4">
            <div class="card-header bg-dark text-white py-3">
                <h5 class="card-title mb-0 fw-bold">Rapport d'Exécution de la Réinitialisation / Mise à jour</h5>
            </div>
            <div class="card-body p-4">
                <div class="table-responsive">
                    <table class="table table-bordered align-middle mb-0">
                        <thead class="table-light small">
                            <tr>
                                <th style="width: 70px;">Étape</th>
                                <th>Action / Composant</th>
                                <th style="width: 120px;" class="text-center">Statut</th>
                                <th>Message / Détails</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results as $res): ?>
                                <tr>
                                    <td class="text-center font-monospace fw-bold"><?= (int)$res['step'] ?></td>
                                    <td><strong><?= e($res['name']) ?></strong></td>
                                    <td class="text-center">
                                        <?php if ($res['status'] === 'SUCCESS'): ?>
                                            <span class="badge bg-success">SUCCESS</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">FAILED</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small">
                                        <?php if (!empty($res['error'])): ?>
                                            <span class="text-danger fw-semibold"><?= e($res['error']) ?></span>
                                        <?php else: ?>
                                            <span class="text-success">&check; Ordre exécuté avec succès</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Formulaire de réexécution / mise à niveau -->
    <div class="row g-4">
        <div class="col-12 col-lg-8">
            <div class="card border-0 shadow-sm rounded-3">
                <div class="card-header bg-primary text-white p-4">
                    <h5 class="mb-1 fw-bold">Options de Réexécution et Recompilation</h5>
                    <p class="mb-0 text-white-50 small">Sélectionnez les ordres et conteneurs à rafraîchir</p>
                </div>
                <div class="card-body p-4">
                    <form action="<?= zirioam_url('/installer/maintenance/redeploy') ?>" method="POST">
                        <?= zirioam_csrf_field() ?>

                        <!-- 1. Sélection du conteneur cible -->
                        <div class="mb-4">
                            <label for="target_container" class="form-label fw-bold text-dark">Conteneur Oracle Cible :</label>
                            <select name="target_container" id="target_container" class="form-select font-monospace" required>
                                <option value="CDB$ROOT" selected>CDB$ROOT (Racine commune — Schéma C##ZIRIOAM_ADMIN)</option>
                                <?php if (!empty($pdbs)): ?>
                                    <optgroup label="Pluggable Databases (PDBs)">
                                        <?php foreach ($pdbs as $p): ?>
                                            <option value="<?= e($p['PDB_NAME']) ?>">
                                                <?= e($p['PDB_NAME']) ?> (PDB Locale — Schéma ZIRIOAM_OWNER)
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endif; ?>
                            </select>
                            <div class="form-text">
                                Choisissez <code>CDB$ROOT</code> pour les packages communs d'orchestration, ou une PDB pour les rôles et le moteur DDL local.
                            </div>
                        </div>

                        <!-- 2. Options d'exécution -->
                        <div class="mb-4 p-3 bg-light rounded border">
                            <h6 class="fw-bold text-dark mb-3">Actions à réexécuter :</h6>

                            <!-- Option A : CREATE OR REPLACE -->
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" name="replace_objects" id="replace_objects" value="1" checked>
                                <label class="form-check-label" for="replace_objects">
                                    <strong class="text-primary">Recompiler et remplacer tous les objets (CREATE OR REPLACE)</strong>
                                    <span class="d-block small text-muted">
                                        Réexécute les DDL des packages (<code>PKG_ZIRIOAM_COMMON_OPS</code>, <code>PKG_ZIRIOAM_PDB_ACCESS</code>, <code>PKG_ZIRIOAM_DDL_ENGINE</code>) et des vues de synthèse.
                                    </span>
                                </label>
                            </div>

                            <!-- Option B : Tous les GRANTs -->
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" name="reapply_grants" id="reapply_grants" value="1" checked>
                                <label class="form-check-label" for="reapply_grants">
                                    <strong class="text-success">Réappliquer tous les privilèges et autorisations (GRANT)</strong>
                                    <span class="d-block small text-muted">
                                        Réexécute l'attribution des droits systèmes, quotas, droits d'exécution (<code>GRANT EXECUTE ON ... TO PUBLIC / DBA</code>), et rôles techniques.
                                    </span>
                                </label>
                            </div>

                            <!-- Option C : Database Vault C##VOLTMANAGER -->
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="reapply_vault" id="reapply_vault" value="1" 
                                       <?= !empty($sessVaultUser) ? 'checked' : '' ?> onchange="document.getElementById('maint-vault-wrap').classList.toggle('d-none', !this.checked)">
                                <label class="form-check-label" for="reapply_vault">
                                    <strong class="text-warning text-dark">Habiliter via Vault Manager (C##VOLTMANAGER)</strong>
                                    <span class="d-block small text-muted">
                                        Reconnexion temporaire sous le compte Vault Manager pour ré-accorder <code>ALTER USER</code>, <code>CREATE USER</code> et <code>DV_ACCTMGR</code> à <code>C##ZIRIOAM_ADMIN</code>.
                                    </span>
                                </label>
                            </div>

                            <div id="maint-vault-wrap" class="mt-3 p-3 bg-white rounded border <?= empty($sessVaultUser) ? 'd-none' : '' ?>">
                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <label for="vault_user" class="form-label small fw-semibold">Compte Vault Manager :</label>
                                        <input type="text" name="vault_user" id="vault_user" class="form-control form-control-sm font-monospace text-uppercase" 
                                               value="<?= e($sessVaultUser ?: 'C##VOLTMANAGER') ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="vault_password" class="form-label small fw-semibold">Mot de passe :</label>
                                        <input type="password" name="vault_password" id="vault_password" class="form-control form-control-sm" 
                                               autocomplete="off" placeholder="Mot de passe C##VOLTMANAGER">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center">
                            <a href="<?= zirioam_url('/dashboard') ?>" class="btn btn-outline-secondary">
                                Annuler
                            </a>
                            <button type="submit" class="btn btn-primary px-4 py-2 fw-bold" data-confirm="Exécuter la réexécution des composants sur le conteneur sélectionné ?">
                                ⚡ EXÉCUTER LA MISE À JOUR DES COMPOSANTS &rarr;
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-4">
            <div class="card border-0 shadow-sm rounded-3 bg-light">
                <div class="card-body p-4">
                    <h6 class="fw-bold text-dark mb-2">ℹ️ À quoi sert cet écran ?</h6>
                    <p class="small text-muted">
                        Cet outil de maintenance permet d'appliquer immédiatement les modifications des fichiers SQL du référentiel ZIRIOAM :
                    </p>
                    <ul class="small text-muted ps-3 mb-3">
                        <li>Rafraîchir les packages sans réinstaller toute la base.</li>
                        <li>Corriger les privilèges en cas d'intervention DBA ou d'activation de Database Vault.</li>
                        <li>Valider l'intégrité sans perte de données sur le référentiel des développeurs.</li>
                    </ul>
                    <hr>
                    <h6 class="fw-bold text-dark mb-1">Idempotence garantie :</h6>
                    <p class="small text-muted mb-0">
                        Les tables existantes et l'historique d'audit ne sont pas altérés lors d'une réexécution. Seuls les corps des packages et les privilèges sont réappliqués.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
