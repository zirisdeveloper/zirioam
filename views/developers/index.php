<div class="py-2">
    <!-- En-tête et Boutons d'Action Obligatoires -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
        <div>
            <h2 class="h3 fw-bold text-dark mb-1">Gestion des Développeurs</h2>
            <p class="text-muted mb-0">Référentiel des identités développeurs et comptes Oracle communs (CDB$ROOT).</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="<?= zirioam_url('/developers/create') ?>" class="btn btn-primary fw-semibold">
                + Nouveau Développeur
            </a>
            <form action="<?= zirioam_url('/developers/grant-all-cdb') ?>" method="POST" class="d-inline" onsubmit="return confirm('Accorder le privilège CREATE SESSION dans CDB$ROOT aux rôles communs (C##RL_*) de tous les développeurs ? Cela leur permettra de renouveler leur mot de passe en autonomie via /UpdPwd.');">
                <?= zirioam_csrf_field() ?>
                <button type="submit" class="btn btn-outline-success fw-semibold" title="Activer l'accès CDB$ROOT aux rôles communs développeurs pour permettre le renouvellement autonome de mot de passe">
                    ⚡ Activer Session CDB$ROOT pour Tous
                </button>
            </form>
            <a href="<?= zirioam_url('/developers/export') ?>" class="btn btn-outline-dark fw-semibold">
                📥 EXPORTER JSON
            </a>
            <a href="<?= zirioam_url('/developers/import') ?>" class="btn btn-outline-primary fw-semibold">
                📤 IMPORTER JSON
            </a>
            <a href="<?= zirioam_url('/developers') ?>" class="btn btn-outline-secondary">
                🔄 Actualiser
            </a>
        </div>
    </div>

    <!-- Filtres de recherche -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-3">
            <form action="<?= zirioam_url('/developers') ?>" method="GET" class="row g-2 align-items-center">
                <div class="col-md-6 col-lg-5">
                    <div class="input-group">
                        <span class="input-group-text bg-white">🔍</span>
                        <input type="text" name="q" class="form-control" placeholder="Rechercher par identifiant, nom ou email..." value="<?= e($search) ?>">
                    </div>
                </div>
                <div class="col-md-3 col-lg-3">
                    <select name="status" class="form-select">
                        <option value="">Tous les statuts d'identité</option>
                        <option value="ACTIVE" <?= $status === 'ACTIVE' ? 'selected' : '' ?>>Actif</option>
                        <option value="INACTIVE" <?= $status === 'INACTIVE' ? 'selected' : '' ?>>Inactif</option>
                    </select>
                </div>
                <div class="col-md-3 col-lg-2">
                    <button type="submit" class="btn btn-secondary w-100">Filtrer</button>
                </div>
                <?php if (!empty($search) || !empty($status)): ?>
                    <div class="col-auto">
                        <a href="<?= zirioam_url('/developers') ?>" class="text-decoration-none small text-muted">Réinitialiser</a>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Tableau des Développeurs (Section 18) -->
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover table-zirioam align-middle mb-0">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Nom Complet</th>
                        <th>Email</th>
                        <th>Statut Identité</th>
                        <th>Compte Oracle CDB</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($developers)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-5 text-muted">
                                <div class="mb-2">Aucun développeur trouvé dans le référentiel.</div>
                                <a href="<?= zirioam_url('/developers/create') ?>" class="btn btn-sm btn-primary">
                                    Créer le premier développeur
                                </a>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($developers as $dev): ?>
                            <?php 
                                $hasAccount = ((int)($dev['ORACLE_ACCOUNT_EXISTS'] ?? 0)) > 0;
                                $oraStatus = $dev['ORACLE_ACCOUNT_STATUS'] ?? '';
                                $isLocked = str_contains($oraStatus, 'LOCKED');
                                $isExpired = str_contains($oraStatus, 'EXPIRED');
                            ?>
                            <tr>
                                <td>
                                    <strong class="font-monospace text-dark fs-6"><?= e($dev['USERNAME']) ?></strong>
                                    <span class="d-block text-muted small font-monospace"><?= e($dev['ORACLE_USER']) ?></span>
                                </td>
                                <td><?= e($dev['FULL_NAME']) ?></td>
                                <td>
                                    <a href="mailto:<?= e($dev['EMAIL']) ?>" class="text-decoration-none text-secondary">
                                        <?= e($dev['EMAIL']) ?>
                                    </a>
                                </td>
                                <td>
                                    <?php if ($dev['STATUS'] === 'ACTIVE'): ?>
                                        <span class="badge bg-success">Actif</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inactif</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!$hasAccount): ?>
                                        <span class="badge bg-light text-danger border border-danger">Non créé</span>
                                    <?php elseif ($isLocked): ?>
                                        <span class="badge bg-danger">Verrouillé</span>
                                    <?php elseif ($isExpired): ?>
                                        <span class="badge bg-warning text-dark">Expiré (À changer)</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">Ouvert</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group">
                                        <a href="<?= zirioam_url('/developers/' . (int)$dev['DEVELOPER_ID']) ?>" class="btn btn-sm btn-outline-primary">
                                            Détails &rarr;
                                        </a>
                                        <a href="<?= zirioam_url('/developers/' . (int)$dev['DEVELOPER_ID'] . '/edit') ?>" class="btn btn-sm btn-outline-secondary" title="Modifier l'identité">
                                            ✏️
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

