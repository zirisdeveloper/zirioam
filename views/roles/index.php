<div class="py-2">
    <!-- En-tête -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h2 class="h3 fw-bold text-dark mb-1">Gestion des Rôles Fonctionnels Oracle</h2>
            <p class="text-muted mb-0">
                PDB Active : <strong class="text-primary font-monospace"><?= e($activePdb ?: 'Aucune PDB sélectionnée') ?></strong>
            </p>
        </div>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createRoleModal">
            + Créer un Rôle Fonctionnel
        </button>
    </div>

    <!-- Tableau des Rôles -->
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover table-zirioam align-middle mb-0">
                <thead>
                    <tr>
                        <th>Nom du Rôle Oracle</th>
                        <th>Schéma Cible</th>
                        <th>Profil Fonctionnel</th>
                        <th>Description</th>
                        <th>Membres Actifs</th>
                        <th>Date Création</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($roles)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-4 text-muted">
                                Aucun rôle fonctionnel ZIRIOAM n'est encore configuré dans cette PDB.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($roles as $r): ?>
                            <tr>
                                <td>
                                    <strong class="font-monospace text-dark fs-6"><?= e($r['ROLE_NAME']) ?></strong>
                                </td>
                                <td>
                                    <span class="badge bg-secondary font-monospace"><?= e($r['TARGET_SCHEMA']) ?></span>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border"><?= e($r['PROFILE_TYPE']) ?></span>
                                </td>
                                <td><?= e($r['DESCRIPTION'] ?? 'N/A') ?></td>
                                <td>
                                    <span class="badge bg-primary"><?= (int)$r['MEMBER_COUNT'] ?> développeur(s)</span>
                                </td>
                                <td class="text-muted small"><?= e($r['CREATED_AT']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal de Création de Rôle Fonctionnel -->
<div class="modal fade" id="createRoleModal" tabindex="-1" aria-labelledby="createRoleModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="<?= zirioam_url('/roles') ?>" method="POST">
                <?= zirioam_csrf_field() ?>
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title" id="createRoleModalLabel">Nouveau Rôle Fonctionnel (PDB: <?= e($activePdb) ?>)</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label for="target_schema" class="form-label fw-semibold">Schéma applicatif cible :</label>
                        <?php if (!empty($schemas)): ?>
                            <select name="target_schema" id="target_schema" class="form-select font-monospace text-uppercase" required>
                                <?php foreach ($schemas as $s): ?>
                                    <option value="<?= e($s['USERNAME']) ?>"><?= e($s['USERNAME']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <input type="text" name="target_schema" id="target_schema" class="form-control text-uppercase font-monospace" placeholder="ex: GESTION" required>
                        <?php endif; ?>
                        <div class="form-text">Schéma hébergeant les tables applicatives cibles.</div>
                    </div>

                    <div class="mb-3">
                        <label for="profile_type" class="form-label fw-semibold">Type de profil fonctionnel :</label>
                        <select name="profile_type" id="profile_type" class="form-select" required>
                            <option value="APP_READ">APP_READ (Lecture seule : SELECT)</option>
                            <option value="APP_WRITE">APP_WRITE (Écriture : INSERT, UPDATE, DELETE)</option>
                            <option value="APP_READ_WRITE">APP_READ_WRITE (Lecture et Écriture complète)</option>
                            <option value="APP_EXECUTE">APP_EXECUTE (Exécution packages/procédures)</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label fw-semibold">Description :</label>
                        <textarea name="description" id="description" class="form-control" rows="2" placeholder="Description de l'usage du rôle..."></textarea>
                    </div>

                    <div class="alert alert-info small mb-0">
                        ℹ️ Le nom du rôle généré sera automatiquement normalisé sous la forme <code>R_&lt;SCHEMA&gt;_&lt;PROFIL&gt;</code>.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary fw-bold">Créer le rôle Oracle</button>
                </div>
            </form>
        </div>
    </div>
</div>

