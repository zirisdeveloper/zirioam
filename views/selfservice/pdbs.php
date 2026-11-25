<div class="py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
        <div>
            <h2 class="h3 fw-bold text-dark mb-1">Bases PDB Accessibles</h2>
            <p class="text-muted mb-0">
                Liste des bases pluggables (PDB) sur lesquelles votre compte dispose de privilèges. Sélectionnez une PDB pour travailler dessus.
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= zirioam_url('/selfservice/pdbs') ?>" class="btn btn-outline-secondary btn-sm">
                🔄 Actualiser
            </a>
            <?php if (!empty($selfServicePdb)): ?>
                <a href="<?= zirioam_url('/selfservice/privs') ?>" class="btn btn-primary btn-sm fw-semibold">
                    Aller à Privs (<?= e($selfServicePdb) ?>) &rarr;
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($discoverError)): ?>
        <div class="alert alert-danger shadow-sm mb-4">
            <strong>Erreur de découverte PDB :</strong> <?= e($discoverError) ?>
        </div>
    <?php endif; ?>

    <?php if (empty($pdbs) && !empty($diagnosticPdbs)): ?>
        <div class="alert alert-warning shadow-sm mb-4">
            <strong>⚠️ Diagnostic de découverte PDB :</strong> <?= (int)($totalOpenPdbs ?? count($diagnosticPdbs)) ?> PDB(s) ouverte(s) vérifiée(s), mais aucune n'a retourné d'accès actif pour votre compte (<code><?= e($selfServiceSession['username'] ?? '') ?></code>).
            <div class="table-responsive mt-2">
                <table class="table table-sm table-bordered bg-white mb-0 small">
                    <thead>
                        <tr>
                            <th>PDB</th>
                            <th>Service testé</th>
                            <th>Statut / Détail Oracle</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($diagnosticPdbs as $dp): ?>
                            <tr class="<?= !empty($dp['ZIRIOAM_DEPLOYED']) ? 'table-info' : '' ?>">
                                <td class="font-monospace fw-bold"><?= e($dp['PDB_NAME']) ?></td>
                                <td class="font-monospace"><?= e($dp['SERVICE_NAME']) ?></td>
                                <td><?= e($dp['STATUS_NOTE'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- Tableau des PDBs autorisées (Format identique à la vue Bases CDB / PDB) -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
            <h5 class="card-title mb-0 fw-bold text-dark">
                Bases Pluggables Découvertes (PDB)
                <span class="badge bg-secondary ms-2"><?= count($pdbs) ?> PDB<?= count($pdbs) > 1 ? 's' : '' ?></span>
            </h5>
            <input type="text" class="form-control form-control-sm w-auto" placeholder="Filtrer les PDBs..." data-table-filter="table-ss-pdbs">
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-zirioam align-middle mb-0" id="table-ss-pdbs">
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
                                Aucune base pluggable (PDB) avec des privilèges actifs n'a été trouvée pour votre compte.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($pdbs as $pdb): ?>
                            <?php
                                $openMode = strtoupper($pdb['OPEN_MODE'] ?? 'READ WRITE');
                                $pillClass = 'status-pill-closed';
                                if ($openMode === 'READ WRITE') {
                                    $pillClass = 'status-pill-open';
                                } elseif ($openMode === 'READ ONLY') {
                                    $pillClass = 'status-pill-mounted';
                                }
                                $isCurrent = !empty($selfServicePdb) && strtoupper((string)$selfServicePdb) === strtoupper((string)$pdb['PDB_NAME']);
                                $restricted = strtoupper((string)($pdb['RESTRICTED'] ?? 'NO'));
                            ?>
                            <tr class="<?= $isCurrent ? 'table-primary' : '' ?>">
                                <td class="font-monospace text-muted"><?= (int)($pdb['CON_ID'] ?? 0) ?></td>
                                <td>
                                    <strong class="font-monospace text-dark fs-6"><?= e($pdb['PDB_NAME']) ?></strong>
                                    <?php if ($isCurrent): ?>
                                        <span class="badge bg-primary ms-2">PDB Active</span>
                                    <?php endif; ?>
                                    <?php if (!empty($pdb['SCHEMAS'])): ?>
                                        <div class="mt-1 d-flex flex-wrap gap-1">
                                            <?php foreach ($pdb['SCHEMAS'] as $sch): ?>
                                                <span class="badge bg-light text-secondary border font-monospace" style="font-size: 0.7rem;"><?= e($sch) ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="status-pill <?= $pillClass ?>">
                                        <?= e($openMode) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($restricted === 'YES'): ?>
                                        <span class="badge bg-danger">OUI (Restreint)</span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-secondary border">NON</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <form action="<?= zirioam_url('/selfservice/pdbs/' . rawurlencode($pdb['PDB_NAME']) . '/select') ?>" method="POST" class="d-inline">
                                        <?= zirioam_csrf_field() ?>
                                        <input type="hidden" name="service_name" value="<?= e($pdb['SERVICE_NAME']) ?>">
                                        <button type="submit" class="btn btn-sm <?= $isCurrent ? 'btn-outline-primary' : 'btn-primary' ?>">
                                            <?= $isCurrent ? '✓ PDB Sélectionnée' : 'Sélectionner cette PDB' ?>
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
