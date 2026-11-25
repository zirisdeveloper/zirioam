<div class="py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
        <div>
            <h2 class="h3 fw-bold text-dark mb-1">Bases PDB Accessibles</h2>
            <p class="text-muted mb-0">
                Sélectionnez la base pluggable (PDB) sur laquelle vous souhaitez consulter ou synchroniser vos privilèges.
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= zirioam_url('/selfservice/pdbs') ?>" class="btn btn-outline-secondary">
                🔄 Actualiser la liste
            </a>
            <form action="<?= zirioam_url('/selfservice/privs/sync-all') ?>" method="POST" class="d-inline" onsubmit="return confirm('Synchroniser vos privilèges sur l\'ensemble des PDBs accessibles ?');">
                <?= zirioam_csrf_field() ?>
                <button type="submit" class="btn btn-success fw-semibold">
                    🌐 Synchroniser toutes mes PDBs
                </button>
            </form>
        </div>
    </div>

    <?php if (!empty($discoverError)): ?>
        <div class="alert alert-danger shadow-sm mb-4">
            <strong>Erreur de découverte PDB :</strong> <?= e($discoverError) ?>
        </div>
    <?php endif; ?>

    <?php if (empty($pdbs)): ?>
        <div class="card border-0 shadow-sm p-5 text-center">
            <div class="text-muted mb-2 fs-5">Aucune PDB ouverte n'a été détectée sur ce serveur.</div>
            <p class="small text-muted mb-0">
                Assurez-vous que les composants ZIRIOAM (<code>PKG_ZIRIOAM_COMMON_OPS</code>) sont déployés et à jour sur le serveur CDB.
            </p>
        </div>
    <?php else: ?>
        <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4">
            <?php foreach ($pdbs as $pdb): ?>
                <?php
                    $isAccessible = !empty($pdb['ACCESSIBLE']);
                    $isSelected = !empty($selfServicePdb) && strtoupper((string)$selfServicePdb) === $pdb['PDB_NAME'];
                ?>
                <div class="col">
                    <div class="card h-100 shadow-sm border-0 <?= $isSelected ? 'border border-2 border-primary' : '' ?>">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0 fw-bold font-monospace text-dark">
                                <?= e($pdb['PDB_NAME']) ?>
                            </h5>
                            <?php if ($isSelected): ?>
                                <span class="badge bg-primary">PDB Active</span>
                            <?php elseif ($isAccessible): ?>
                                <span class="badge bg-success">Autorisée</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Non attribuée</span>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <div class="small text-muted mb-2">
                                Mode : <strong class="text-dark"><?= e($pdb['OPEN_MODE']) ?></strong> &bull;
                                Service : <code class="text-primary"><?= e($pdb['SERVICE_NAME']) ?></code>
                            </div>

                            <?php if ($isAccessible && !empty($pdb['SCHEMAS'])): ?>
                                <div class="mt-3">
                                    <div class="small fw-semibold text-muted mb-1">Schémas attribués :</div>
                                    <div class="d-flex flex-wrap gap-1">
                                        <?php foreach ($pdb['SCHEMAS'] as $sch): ?>
                                            <span class="badge bg-light text-dark border font-monospace"><?= e($sch) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-light border small py-2 mb-0 mt-2 text-muted">
                                    <?= e($pdb['STATUS_NOTE'] ?: 'Aucun accès configuré sur cette PDB.') ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="card-footer bg-white border-top-0 pt-0 pb-3">
                            <?php if ($isAccessible): ?>
                                <form action="<?= zirioam_url('/selfservice/pdbs/' . rawurlencode($pdb['PDB_NAME']) . '/select') ?>" method="POST">
                                    <?= zirioam_csrf_field() ?>
                                    <input type="hidden" name="service_name" value="<?= e($pdb['SERVICE_NAME']) ?>">
                                    <button type="submit" class="btn <?= $isSelected ? 'btn-outline-primary' : 'btn-primary' ?> w-100 fw-semibold">
                                        <?= $isSelected ? '✓ Sélectionnée — Gérer les Privs →' : 'Sélectionner cette PDB →' ?>
                                    </button>
                                </form>
                            <?php else: ?>
                                <button type="button" class="btn btn-outline-secondary w-100" disabled>
                                    Accès non attribué
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
