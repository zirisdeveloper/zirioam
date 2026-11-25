<div class="py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
        <div>
            <h2 class="h3 fw-bold text-dark mb-1">Synchronisation des Privilèges (Privs)</h2>
            <p class="text-muted mb-0">
                Lorsque de nouveaux objets sont créés dans un schéma autorisé, synchronisez vos privilèges pour y accéder immédiatement.
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= zirioam_url('/selfservice/pdbs') ?>" class="btn btn-outline-secondary">
                🌐 Bases PDB
            </a>
            <?php if (!empty($activePdb)): ?>
                <form action="<?= zirioam_url('/selfservice/privs/sync-all') ?>" method="POST" class="d-inline" onsubmit="return confirm('Lancer la synchronisation des privilèges sur toutes vos PDBs accessibles ?');">
                    <?= zirioam_csrf_field() ?>
                    <button type="submit" class="btn btn-outline-success">
                        ⚡ Synchroniser toutes mes PDBs accessibles
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Carte principale de Synchronisation -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-4 d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h5 class="fw-bold text-dark mb-1">
                    🎯 PDB sélectionnée :
                    <?php if (!empty($activePdb)): ?>
                        <span class="badge bg-primary font-monospace fs-6 ms-1"><?= e($activePdb) ?></span>
                    <?php else: ?>
                        <span class="badge bg-warning text-dark ms-1">Aucune</span>
                    <?php endif; ?>
                </h5>
                <p class="small text-muted mb-0">
                    <?php if (!empty($activePdb)): ?>
                        Cliquez sur le bouton ci-contre pour appliquer vos privilèges (et <code>GRANT ALL</code> pour les profils <code>APP_ALL</code>) sur tous les objets de la PDB <strong><?= e($activePdb) ?></strong>.
                    <?php else: ?>
                        Aucune PDB n'est encore sélectionnée dans votre session. Choisissez une PDB dans « Bases PDB » ou synchronisez directement toutes vos PDBs accessibles.
                    <?php endif; ?>
                </p>
            </div>
            <div>
                <?php if (!empty($activePdb)): ?>
                    <form action="<?= zirioam_url('/selfservice/privs/sync-pdb') ?>" method="POST" class="m-0">
                        <?= zirioam_csrf_field() ?>
                        <input type="hidden" name="pdb_name" value="<?= e($activePdb) ?>">
                        <button type="submit" class="btn btn-primary btn-lg fw-bold shadow-sm">
                            🔄 Synchroniser les objets du PDB <?= e($activePdb) ?>
                        </button>
                    </form>
                <?php else: ?>
                    <form action="<?= zirioam_url('/selfservice/privs/sync-all') ?>" method="POST" class="m-0" onsubmit="return confirm('Lancer la synchronisation des privilèges sur toutes vos PDBs accessibles ?');">
                        <?= zirioam_csrf_field() ?>
                        <button type="submit" class="btn btn-success btn-lg fw-bold shadow-sm">
                            ⚡ Synchroniser toutes mes PDBs accessibles
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Détails des autorisations sur la PDB active -->
    <?php if (!empty($activePdb)): ?>
        <?php if (!empty($accessError)): ?>
            <div class="alert alert-danger shadow-sm mb-4">
                <strong>Erreur :</strong> <?= e($accessError) ?>
            </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3">
                <h5 class="card-title mb-0 fw-bold text-dark">
                    Mes habilitations actives dans <?= e($activePdb) ?>
                </h5>
            </div>
            <div class="table-responsive">
                <table class="table table-hover table-zirioam align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Schéma Cible</th>
                            <th>Rôle Fonctionnel</th>
                            <th>Profil</th>
                            <th class="text-center">CREATE / ADD</th>
                            <th class="text-center">ALTER / REPLACE</th>
                            <th class="text-center">DROP</th>
                            <th>Statut</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($accessRows)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-4 text-muted">
                                    Aucune habilitation active enregistrée pour votre compte dans la PDB <?= e($activePdb) ?>.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($accessRows as $row): ?>
                                <tr>
                                    <td><span class="badge bg-secondary font-monospace fs-6"><?= e($row['TARGET_SCHEMA']) ?></span></td>
                                    <td>
                                        <?php if (!empty($row['ROLE_NAME'])): ?>
                                            <span class="badge bg-primary font-monospace"><?= e($row['ROLE_NAME']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted small">Aucun rôle DML</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge bg-light text-dark border"><?= e($row['PROFILE_TYPE'] ?? 'N/A') ?></span></td>
                                    <td class="text-center">
                                        <?= ((int)($row['CAN_DDL_CREATE'] ?? 0) === 1) ? '<span class="badge bg-success">OUI</span>' : '<span class="badge bg-light text-muted border">NON</span>' ?>
                                    </td>
                                    <td class="text-center">
                                        <?= ((int)($row['CAN_DDL_ALTER'] ?? 0) === 1) ? '<span class="badge bg-success">OUI</span>' : '<span class="badge bg-light text-muted border">NON</span>' ?>
                                    </td>
                                    <td class="text-center">
                                        <?= ((int)($row['CAN_DDL_DROP'] ?? 0) === 1) ? '<span class="badge bg-danger">OUI</span>' : '<span class="badge bg-light text-muted border">NON</span>' ?>
                                    </td>
                                    <td><span class="badge bg-success"><?= e($row['STATUS']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>
