<div class="py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
        <div>
            <h2 class="h3 fw-bold text-dark mb-1">Synchronisation de mes Privilèges (Privs)</h2>
            <p class="text-muted mb-0">
                Lorsque de nouveaux objets sont créés dans un schéma autorisé, synchronisez vos droits pour obtenir immédiatement les privilèges associés.
            </p>
        </div>
        <a href="<?= zirioam_url('/selfservice/pdbs') ?>" class="btn btn-outline-secondary">
            🌐 Changer de PDB
        </a>
    </div>

    <!-- Cartes d'Action de Synchronisation -->
    <div class="row g-4 mb-4">
        <!-- Action 1 : PDB sélectionnée -->
        <div class="col-md-6">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-body p-4 d-flex flex-column justify-content-between">
                    <div>
                        <h5 class="fw-bold text-dark mb-2">
                            🎯 PDB sélectionnée :
                            <?php if (!empty($activePdb)): ?>
                                <span class="badge bg-primary font-monospace"><?= e($activePdb) ?></span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark">Aucune</span>
                            <?php endif; ?>
                        </h5>
                        <p class="small text-muted mb-3">
                            Applique les privilèges de vos rôles fonctionnels (et <code>GRANT ALL</code> pour les profils <code>APP_ALL</code>) sur tous les objets de vos schémas dans cette PDB uniquement.
                        </p>
                    </div>
                    <?php if (!empty($activePdb)): ?>
                        <form action="<?= zirioam_url('/selfservice/privs/sync-pdb') ?>" method="POST">
                            <?= zirioam_csrf_field() ?>
                            <input type="hidden" name="pdb_name" value="<?= e($activePdb) ?>">
                            <button type="submit" class="btn btn-primary btn-lg w-100 fw-bold shadow-sm">
                                🔄 Synchroniser les privilèges sur <?= e($activePdb) ?>
                            </button>
                        </form>
                    <?php else: ?>
                        <a href="<?= zirioam_url('/selfservice/pdbs') ?>" class="btn btn-outline-primary w-100 fw-semibold">
                            Choisir une PDB dans « Bases PDB » &rarr;
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Action 2 : Toutes les PDBs accessibles -->
        <div class="col-md-6">
            <div class="card h-100 border-0 shadow-sm border-start border-4 border-success">
                <div class="card-body p-4 d-flex flex-column justify-content-between">
                    <div>
                        <h5 class="fw-bold text-dark mb-2">
                            🌐 Toutes mes PDBs accessibles
                        </h5>
                        <p class="small text-muted mb-3">
                            Parcourt automatiquement toutes les bases PDB auxquelles votre compte a accès sur ce serveur et met à jour l'ensemble de vos privilèges d'objets en une seule opération.
                        </p>
                    </div>
                    <form action="<?= zirioam_url('/selfservice/privs/sync-all') ?>" method="POST" onsubmit="return confirm('Lancer la synchronisation des privilèges sur toutes vos PDBs accessibles ?');">
                        <?= zirioam_csrf_field() ?>
                        <button type="submit" class="btn btn-success btn-lg w-100 fw-bold shadow-sm">
                            ⚡ Synchroniser toutes mes PDBs accessibles
                        </button>
                    </form>
                </div>
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
