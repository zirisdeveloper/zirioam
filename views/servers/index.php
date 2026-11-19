<div class="container py-4">
    <!-- En-tête -->
    <div class="text-center mb-5">
        <h1 class="display-6 fw-bold text-dark mb-2">ZIRI OAM — Oracle Developer Access Manager</h1>
        <p class="lead text-muted mb-0">Administration centralisée des comptes développeurs et des privilèges Oracle 19c.</p>
        <div class="mt-2 text-muted small">
            Source du référentiel : <code><?= e($filePath) ?></code>
        </div>
    </div>

    <?php if (!empty($errorMessage)): ?>
        <div class="alert alert-danger shadow-sm mb-4" role="alert">
            <h5 class="alert-heading fw-bold">⚠️ Erreur de configuration des serveurs</h5>
            <p class="mb-0"><?= e($errorMessage) ?></p>
        </div>
    <?php endif; ?>

    <!-- Liste des Cartes de Serveurs -->
    <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4">
        <?php if (empty($servers)): ?>
            <div class="col-12">
                <div class="card border-0 shadow-sm p-4 text-center">
                    <div class="text-muted mb-3">Aucun serveur Oracle actif n'est actuellement configuré dans le référentiel.</div>
                    <div>
                        <span class="badge bg-secondary">Vérifiez le fichier <?= e($filePath) ?></span>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($servers as $srv): ?>
                <?php
                    $env = strtoupper($srv['environment'] ?? 'DEV');
                    $badgeClass = match ($env) {
                        'PRODUCTION', 'PROD' => 'badge-env-prod',
                        'TEST', 'RECETTE' => 'badge-env-test',
                        default => 'badge-env-dev',
                    };
                ?>
                <div class="col">
                    <div class="card h-100 server-card shadow-sm">
                        <div class="card-header d-flex justify-content-between align-items-center py-3">
                            <h5 class="card-title mb-0 fw-bold text-dark">
                                <?= e($srv['server_name']) ?>
                            </h5>
                            <span class="badge <?= $badgeClass ?> px-2 py-1">
                                <?= e($env) ?>
                            </span>
                        </div>
                        <div class="card-body">
                            <div class="mb-2">
                                <span class="text-muted small">Adresse IP :</span>
                                <strong class="text-dark font-monospace"><?= e($srv['ip_address']) ?></strong>
                            </div>
                            <div class="mb-3">
                                <span class="text-muted small">Port Listener :</span>
                                <strong class="text-dark font-monospace"><?= (int)$srv['port'] ?></strong>
                            </div>

                            <!-- Services CDB associés -->
                            <div class="mb-3">
                                <span class="text-muted small d-block mb-1">Services racine CDB :</span>
                                <?php if (!empty($srv['cdb_services'])): ?>
                                    <ul class="list-unstyled mb-0 small font-monospace">
                                        <?php foreach ($srv['cdb_services'] as $svc): ?>
                                            <li class="bg-light p-1 rounded mb-1 border-start border-3 border-primary">
                                                <strong><?= e($svc['cdb_name']) ?></strong>: <?= e($svc['root_service']) ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <span class="text-muted small fst-italic">Service par défaut (cdb.company.local)</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-footer bg-white border-0 pt-0 pb-3">
                            <a href="<?= zirioam_url('/servers/' . rawurlencode($srv['server_id']) . '/connect') ?>" 
                               class="btn btn-primary w-100 py-2 fw-semibold">
                                SE CONNECTER &rarr;
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

