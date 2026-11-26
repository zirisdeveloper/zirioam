<div class="py-2">
    <!-- En-tête -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
        <div>
            <h2 class="h3 fw-bold text-dark mb-1">Matrice d'Accès : Séparation Stricte DDL / DML</h2>
            <div class="d-flex align-items-center gap-2 mt-1">
                <span class="text-muted small">PDB Active :</span>
                <form action="<?= zirioam_url('/access') ?>" method="GET" class="d-inline-flex align-items-center">
                    <select name="pdb" class="form-select form-select-sm font-monospace fw-bold text-primary" onchange="this.form.submit()">
                        <?php foreach ($pdbs as $p): ?>
                            <option value="<?= e($p['NAME']) ?>" <?= ($p['NAME'] === $activePdb) ? 'selected' : '' ?>>
                                <?= e($p['NAME']) ?> (<?= e($p['OPEN_MODE']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= zirioam_url('/databases') ?>" class="btn btn-outline-secondary">
                🌐 Liste des PDBs
            </a>
            <form action="<?= zirioam_url('/access/sync-all') ?>" method="POST" class="d-inline">
                <?= zirioam_csrf_field() ?>
                <input type="hidden" name="pdb" value="<?= e($activePdb) ?>">
                <button type="submit" class="btn btn-outline-primary" title="Synchronise tous les objets, tables, vues et applique automatiquement les quotas tablespace illimités aux développeurs de cette PDB">
                    🔄 Synchroniser Tout (Privilèges & Quotas)
                </button>
            </form>
            <button type="button" class="btn btn-primary fw-semibold" data-bs-toggle="modal" data-bs-target="#grantAccessModal">
                + Attribuer des Droits Développeur
            </button>
        </div>
    </div>

    <?php if (!empty($activePdb) && empty($isPdbInstalled)): ?>
        <div class="alert alert-warning border-warning shadow-sm mb-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div>
                <h6 class="fw-bold text-dark mb-1">⚠️ Composants ZIRIOAM non déployés sur <?= e($activePdb) ?></h6>
                <span class="small text-muted">
                    Le schéma propriétaire <code>ZIRIOAM_OWNER</code> et les packages PDB ne sont pas encore installés sur cette PDB.
                </span>
            </div>
            <div class="d-flex gap-2">
                <a href="<?= zirioam_url('/installer/maintenance') ?>" class="btn btn-sm btn-warning fw-bold">
                    🛠️ Déployer via Maintenance &rarr;
                </a>
                <a href="<?= zirioam_url('/installer/wizard?target=' . urlencode($activePdb)) ?>" class="btn btn-sm btn-outline-dark">
                    Assistant d'Installation
                </a>
            </div>
        </div>
    <?php endif; ?>

    <!-- Tableau de la Matrice d'Accès -->
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover table-zirioam align-middle mb-0">
                <thead>
                    <tr>
                        <th>Développeur</th>
                        <th>Schéma Cible</th>
                        <th>Rôle DML (Données)</th>
                        <th class="text-center">DDL CREATE / ADD</th>
                        <th class="text-center">DDL ALTER / REPLACE</th>
                        <th class="text-center">DDL DROP</th>
                        <th>Statut</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($matrix)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-4 text-muted">
                                Aucune autorisation n'est actuellement configurée dans cette PDB.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($matrix as $m): ?>
                            <tr>
                                <td>
                                    <strong class="font-monospace text-dark fs-6"><?= e($m['USERNAME']) ?></strong>
                                </td>
                                <td>
                                    <span class="badge bg-secondary font-monospace"><?= e($m['TARGET_SCHEMA']) ?></span>
                                </td>
                                <td>
                                    <?php if (!empty($m['ROLE_NAME'])): ?>
                                        <span class="badge bg-primary font-monospace"><?= e($m['ROLE_NAME']) ?></span>
                                        <span class="text-muted small d-block"><?= e($m['PROFILE_TYPE']) ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-muted border">AUCUN DROIT DML</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($m['CAN_DDL_CREATE'] == 1): ?>
                                        <span class="badge bg-success">OUI</span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-muted border">NON</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($m['CAN_DDL_ALTER'] == 1): ?>
                                        <span class="badge bg-success">OUI</span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-muted border">NON</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($m['CAN_DDL_DROP'] == 1): ?>
                                        <span class="badge bg-danger">OUI</span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-muted border">NON</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-success"><?= e($m['STATUS']) ?></span>
                                </td>
                                <td class="text-end">
                                    <form action="<?= zirioam_url('/access/' . (int)$m['ACCESS_ID'] . '/revoke') ?>" method="POST" class="d-inline">
                                        <?= zirioam_csrf_field() ?>
                <input type="hidden" name="pdb" value="<?= e($activePdb) ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Révoquer ces accès pour <?= e($m['USERNAME']) ?> sur <?= e($m['TARGET_SCHEMA']) ?> ?">
                                            Révoquer
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal d'Attribution d'Accès DDL / DML -->
<div class="modal fade" id="grantAccessModal" tabindex="-1" aria-labelledby="grantAccessModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form action="<?= zirioam_url('/access') ?>" method="POST">
                <?= zirioam_csrf_field() ?>
                <input type="hidden" name="pdb" value="<?= e($activePdb) ?>">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title" id="grantAccessModalLabel">Configuration des Accès (PDB: <?= e($activePdb) ?>)</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="alert alert-info py-2 px-3 small mb-3">
                        <strong>Architecture des Rôles ZIRIOAM :</strong> L'attribution configure automatiquement le rôle local <code>RL_&lt;USER&gt;</code> dans cette PDB (CREATE SESSION + Rôles applicatifs) rattaché au rôle commun <code>C##RL_&lt;USER&gt;</code> (CONNECT), et synchronise tous les droits SELECT/DML sur les tables du schéma cible.
                    </div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label for="username" class="form-label fw-semibold">Développeur cible :</label>
                            <select name="username" id="username" class="form-select font-monospace text-uppercase" required>
                                <option value="">-- Choisir un développeur --</option>
                                <?php foreach ($developers as $dev): ?>
                                    <option value="<?= e($dev['ORACLE_USER']) ?>">
                                        <?= e($dev['ORACLE_USER']) ?> (<?= e($dev['FULL_NAME']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="target_schema" class="form-label fw-semibold">Schéma applicatif :</label>
                            <?php if (!empty($schemas)): ?>
                                <select name="target_schema" id="target_schema" class="form-select font-monospace text-uppercase" required>
                                    <option value="">-- Choisir un schéma --</option>
                                    <?php foreach ($schemas as $s): ?>
                                        <option value="<?= e($s['USERNAME']) ?>"><?= e($s['USERNAME']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <input type="text" name="target_schema" id="target_schema" class="form-control text-uppercase font-monospace" placeholder="ex: GESTION" required>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- 1. Profil DML -->
                    <div class="p-3 bg-light rounded border mb-4">
                        <h6 class="fw-bold text-dark mb-2">1. Droits sur les Données (Privilèges DML) :</h6>
                        <div class="mb-2">
                            <label for="role_id" class="form-label small text-muted">Rôle fonctionnel associé :</label>
                            <select name="role_id" id="role_id" class="form-select">
                                <option value="">-- Aucun droit DML (Développeur sans accès aux données) --</option>
                                <?php foreach ($roles as $r): ?>
                                    <option value="<?= (int)$r['ROLE_ID'] ?>">
                                        <?= e($r['ROLE_NAME']) ?> (<?= e($r['PROFILE_TYPE']) ?> sur <?= e($r['TARGET_SCHEMA']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="small text-muted">
                            Si aucun rôle n'est sélectionné, le développeur pourra créer ou modifier des structures sans pouvoir lire ou écrire dans les données.
                        </div>
                    </div>

                    <!-- 2. Profil DDL Contrôlé -->
                    <div class="p-3 bg-light rounded border mb-3">
                        <h6 class="fw-bold text-dark mb-2">2. Droits sur les Structures (Privilèges DDL Contrôlés) :</h6>
                        <div class="row g-2">
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="can_ddl_create" value="1" id="chkCreate">
                                    <label class="form-check-label fw-semibold" for="chkCreate">
                                        CREATE/ADD OBJECTS
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="can_ddl_alter" value="1" id="chkAlter">
                                    <label class="form-check-label fw-semibold" for="chkAlter">
                                        ALTER/REPLACE OBJECTS
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="can_ddl_drop" value="1" id="chkDrop">
                                    <label class="form-check-label fw-semibold" for="chkDrop">
                                        DROP OBJECTS
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="small text-muted mt-2">
                            Ces droits accordent les privilèges système <code>CREATE / ALTER / DROP ANY ...</code> sur <code>RL_&lt;USER&gt;</code> (TABLE, VIEW, INDEX, SEQUENCE, PROCEDURE, FUNCTION, PACKAGE, TRIGGER, TYPE, SYNONYM) tout en restant strictement cloisonnés au schéma cible par le trigger PDB <code>TRG_ZIRIOAM_CONTROLLED_DDL</code> (utilisable directement dans SQL*Plus / SQL Developer ou via <code>PKG_ZIRIOAM_DDL_ENGINE</code>).
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary fw-bold">Valider et Appliquer l'Accès</button>
                </div>
            </form>
        </div>
    </div>
</div>

