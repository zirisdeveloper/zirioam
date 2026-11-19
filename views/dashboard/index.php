<div class="py-2">
    <!-- En-tête du Tableau de Bord (Section 17) -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h2 class="h3 fw-bold text-dark mb-1">Tableau de Bord d'Administration</h2>
            <p class="text-muted mb-0">
                Serveur : <strong><?= e($server['server_name'] ?? 'N/A') ?></strong> &bull; 
                CDB : <strong class="font-monospace text-primary"><?= e($currentCdb ?? 'CDB$ROOT') ?></strong>
            </p>
        </div>
        <div>
            <a href="<?= zirioam_url('/databases') ?>" class="btn btn-outline-primary btn-sm me-2">
                🗄️ Explorer les PDBs
            </a>
            <a href="<?= zirioam_url('/developers') ?>" class="btn btn-primary btn-sm">
                👥 Gérer les Développeurs
            </a>
        </div>
    </div>

    <!-- 1. Cartes de Métriques Clés Réelles -->
    <div class="row g-3 mb-4">
        <!-- Bases de données PDB -->
        <div class="col-12 col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3 border-0">
                    <span class="text-muted small text-uppercase fw-bold">Bases de Données (PDB)</span>
                    <h3 class="fw-bold text-dark mb-0 mt-1"><?= (int)$metrics['total_pdbs'] ?></h3>
                </div>
                <div class="card-body pt-0">
                    <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                        <span class="text-muted small">PDB Ouvertes (READ WRITE) :</span>
                        <span class="badge bg-success fs-6"><?= (int)$metrics['open_pdbs'] ?></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center py-2">
                        <span class="text-muted small">PDB Fermées / Montées :</span>
                        <span class="badge bg-secondary fs-6"><?= (int)$metrics['closed_pdbs'] ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Développeurs & Comptes Communs -->
        <div class="col-12 col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3 border-0">
                    <span class="text-muted small text-uppercase fw-bold">Développeurs & Identités</span>
                    <h3 class="fw-bold text-dark mb-0 mt-1"><?= (int)$metrics['registered_devs'] ?></h3>
                </div>
                <div class="card-body pt-0">
                    <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                        <span class="text-muted small">Comptes Oracle créés (C##...) :</span>
                        <span class="badge bg-primary fs-6"><?= (int)$metrics['created_accounts'] ?></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center py-2">
                        <span class="text-muted small">Comptes non encore provisionnés :</span>
                        <span class="badge bg-warning text-dark fs-6"><?= (int)$metrics['pending_accounts'] ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Autorisations DDL / DML Réelles -->
        <div class="col-12 col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3 border-0">
                    <span class="text-muted small text-uppercase fw-bold">Autorisations Granulaires</span>
                    <h3 class="fw-bold text-dark mb-0 mt-1">
                        <?= (int)($metrics['access_read'] + $metrics['access_write'] + $metrics['access_rw'] + $metrics['access_ddl']) ?>
                    </h3>
                </div>
                <div class="card-body pt-0">
                    <div class="d-flex justify-content-between align-items-center py-1 border-bottom small">
                        <span class="text-muted">Accès READ :</span>
                        <span class="badge bg-info text-dark"><?= (int)$metrics['access_read'] ?></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center py-1 border-bottom small">
                        <span class="text-muted">Accès WRITE :</span>
                        <span class="badge bg-warning text-dark"><?= (int)$metrics['access_write'] ?></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center py-1 border-bottom small">
                        <span class="text-muted">Accès READ_WRITE :</span>
                        <span class="badge bg-primary"><?= (int)$metrics['access_rw'] ?></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center py-1 small">
                        <span class="text-muted fw-bold">Accès DDL Contrôlé :</span>
                        <span class="badge bg-danger"><?= (int)$metrics['access_ddl'] ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 2. Activité Récente (Audit Réel) -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0 fw-bold text-dark">Dernières Opérations Récentes sur le Serveur</h5>
            <a href="<?= zirioam_url('/audit') ?>" class="btn btn-outline-secondary btn-sm">
                Voir tout le journal d'audit &rarr;
            </a>
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-zirioam align-middle mb-0 small">
                <thead>
                    <tr>
                        <th style="width: 170px;">Date & Heure</th>
                        <th>DBA User</th>
                        <th>Action</th>
                        <th>Cible</th>
                        <th>Détails</th>
                        <th class="text-end">Statut</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($metrics['recent_activity'])): ?>
                        <tr>
                            <td colspan="6" class="text-center py-4 text-muted">
                                Aucune opération administrative récente n'a été enregistrée.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($metrics['recent_activity'] as $act): ?>
                            <tr>
                                <td class="font-monospace text-muted"><?= e($act['EVENT_TIME']) ?></td>
                                <td><span class="badge bg-light text-dark border"><?= e($act['DBA_USER']) ?></span></td>
                                <td><strong><?= e($act['ACTION_TYPE']) ?></strong></td>
                                <td class="font-monospace"><?= e($act['TARGET_USER']) ?></td>
                                <td><?= e($act['DETAILS']) ?></td>
                                <td class="text-end">
                                    <span class="badge <?= $act['STATUS'] === 'SUCCESS' ? 'bg-success' : 'bg-danger' ?>">
                                        <?= e($act['STATUS']) ?>
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

