<div class="py-2">
    <!-- En-tête -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="h3 fw-bold text-dark mb-1">Architecture Multitenant & Conteneurs</h2>
            <p class="text-muted mb-0">Découverte automatique des CDB et PDB accessibles sur le serveur Oracle.</p>
        </div>
        <a href="<?= zirioam_url('/dashboard') ?>" class="btn btn-outline-primary btn-sm">
            &larr; Retour au Tableau de bord
        </a>
    </div>

    <!-- Informations Générales de la Base de Données -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h5 class="card-title mb-0 fw-bold text-dark">Informations Instance & Conteneur Courant</h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <span class="text-muted small d-block">Nom de la Base (CDB) :</span>
                    <strong class="fs-5 text-dark font-monospace"><?= e($dbInfo['db_name']) ?></strong>
                </div>
                <div class="col-md-3">
                    <span class="text-muted small d-block">Conteneur Actif :</span>
                    <span class="badge bg-primary fs-6 font-monospace"><?= e($dbInfo['current_container']) ?></span>
                </div>
                <div class="col-md-3">
                    <span class="text-muted small d-block">Statut Instance :</span>
                    <span class="badge bg-success fs-6"><?= e($dbInfo['open_mode']) ?></span>
                </div>
                <div class="col-md-3">
                    <span class="text-muted small d-block">Rôle Base :</span>
                    <strong class="text-dark"><?= e($dbInfo['role']) ?></strong>
                </div>
                <div class="col-12 mt-2 pt-2 border-top">
                    <span class="text-muted small d-block">Version de l'Exécutable Oracle :</span>
                    <code class="text-dark small"><?= e($dbInfo['banner']) ?></code>
                </div>
            </div>
        </div>
    </div>

    <!-- Tableau des PDBs Découvertes -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
            <h5 class="card-title mb-0 fw-bold text-dark">
                Bases Pluggables Découvertes (PDB)
                <span class="badge bg-secondary ms-2"><?= count($pdbs) ?> PDBs</span>
            </h5>
            <input type="text" class="form-control form-control-sm w-auto" placeholder="Filtrer les PDBs..." data-table-filter="table-pdbs">
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-zirioam align-middle mb-0" id="table-pdbs">
                <thead>
                    <tr>
                        <th style="width: 80px;">CON_ID</th>
                        <th>Nom de la PDB</th>
                        <th>Mode d'Ouverture (OPEN_MODE)</th>
                        <th>Mode Restreint</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pdbs)): ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">
                                Aucune base pluggable (PDB) n'a été détectée dans cette CDB.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($pdbs as $pdb): ?>
                            <?php
                                $openMode = strtoupper($pdb['OPEN_MODE'] ?? 'UNKNOWN');
                                $pillClass = 'status-pill-closed';
                                if ($openMode === 'READ WRITE') {
                                    $pillClass = 'status-pill-open';
                                } elseif ($openMode === 'READ ONLY') {
                                    $pillClass = 'status-pill-mounted';
                                }
                                $isCurrent = ($currentPdb ?? '') === strtoupper($pdb['NAME']);
                            ?>
                            <tr class="<?= $isCurrent ? 'table-primary' : '' ?>">
                                <td class="font-monospace text-muted"><?= (int)$pdb['CON_ID'] ?></td>
                                <td>
                                    <strong class="font-monospace text-dark fs-6"><?= e($pdb['NAME']) ?></strong>
                                    <?php if ($isCurrent): ?>
                                        <span class="badge bg-primary ms-2">PDB Active</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="status-pill <?= $pillClass ?>">
                                        <?= e($openMode) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (($pdb['RESTRICTED'] ?? 'NO') === 'YES'): ?>
                                        <span class="badge bg-danger">OUI (Restreint)</span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-secondary border">NON</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?php if ($openMode === 'READ WRITE'): ?>
                                        <form action="<?= zirioam_url('/databases/' . rawurlencode($pdb['NAME']) . '/select') ?>" method="POST" class="d-inline">
                                            <?= zirioam_csrf_field() ?>
                                            <button type="submit" class="btn btn-sm <?= $isCurrent ? 'btn-outline-primary' : 'btn-primary' ?>">
                                                <?= $isCurrent ? '✓ PDB Sélectionnée' : 'Sélectionner cette PDB' ?>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-outline-secondary" disabled title="PDB fermée ou en lecture seule">
                                            Non disponible (<?= e($openMode) ?>)
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

