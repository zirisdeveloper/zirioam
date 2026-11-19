<div class="py-2">
    <!-- En-tête -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h2 class="h3 fw-bold text-dark mb-1">Audit & Traçabilité des Opérations</h2>
            <p class="text-muted mb-0">
                Journalisation exhaustive de toutes les actions administratives exécutées sur les serveurs Oracle.
            </p>
        </div>
        <div class="btn-group">
            <a href="<?= zirioam_url('/audit?scope=CDB') ?>" class="btn btn-sm <?= ($scope ?? 'CDB') === 'CDB' ? 'btn-primary' : 'btn-outline-primary' ?>">
                🌐 Audit CDB$ROOT (Commune)
            </a>
            <a href="<?= zirioam_url('/audit?scope=PDB') ?>" class="btn btn-sm <?= ($scope ?? '') === 'PDB' ? 'btn-primary' : 'btn-outline-primary' ?>">
                🗄️ Audit PDB (<?= e($activePdb ?: 'PDB Locale') ?>)
            </a>
        </div>
    </div>

    <!-- Tableau d'Audit -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0 fw-bold text-dark">
                Événements d'Audit (<?= e($scope) ?>)
                <span class="badge bg-secondary ms-2"><?= count($auditLogs) ?> événement(s)</span>
            </h5>
            <input type="text" class="form-control form-control-sm w-auto" placeholder="Filtrer l'audit..." data-table-filter="table-audit">
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-zirioam align-middle mb-0 small" id="table-audit">
                <thead>
                    <tr>
                        <th style="width: 170px;">Date & Heure</th>
                        <th>DBA User</th>
                        <th>Type d'Action</th>
                        <th>Cible</th>
                        <th>Détails & Paramètres</th>
                        <th>Adresse IP</th>
                        <th class="text-end">Résultat</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($auditLogs)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-4 text-muted">
                                Aucun événement d'audit enregistré dans ce périmètre.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($auditLogs as $log): ?>
                            <tr>
                                <td class="font-monospace text-muted"><?= e($log['EVENT_TIME']) ?></td>
                                <td><span class="badge bg-light text-dark border"><?= e($log['DBA_USER']) ?></span></td>
                                <td><strong><?= e($log['ACTION_TYPE']) ?></strong></td>
                                <td class="font-monospace"><?= e($log['TARGET_USER'] ?: 'N/A') ?></td>
                                <td><?= e($log['DETAILS']) ?></td>
                                <td class="font-monospace text-muted"><?= e($log['CLIENT_IP'] ?? '127.0.0.1') ?></td>
                                <td class="text-end">
                                    <span class="badge <?= $log['STATUS'] === 'SUCCESS' ? 'bg-success' : 'bg-danger' ?>">
                                        <?= e($log['STATUS']) ?>
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

