<div class="py-2">
    <!-- En-tête -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h2 class="h3 fw-bold text-dark mb-1">Moteur DDL Contrôlé & Scripts Oracle</h2>
            <p class="text-muted mb-0">
                PDB Active : <strong class="text-primary font-monospace"><?= e($activePdb ?: 'Aucune PDB sélectionnée') ?></strong> &bull;
                Exécution sécurisée d'instructions DDL sur schémas partagés sans privilèges <code>ANY</code>.
            </p>
        </div>
        <button type="button" class="btn btn-primary fw-semibold" data-bs-toggle="modal" data-bs-target="#executeDdlModal">
            ▶ Exécuter un Ordre DDL Contrôlé
        </button>
    </div>

    <!-- Historique des Exécutions DDL (Section 18) -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
            <h5 class="card-title mb-0 fw-bold text-dark">Journal des Exécutions DDL dans la PDB</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-zirioam align-middle mb-0 small">
                <thead>
                    <tr>
                        <th style="width: 170px;">Date & Heure</th>
                        <th>Développeur</th>
                        <th>Schéma Cible</th>
                        <th>Opération</th>
                        <th>Type & Objet</th>
                        <th>Statut</th>
                        <th>Détails Erreur</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($history)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-4 text-muted">
                                Aucun ordre DDL exécuté dans cette PDB.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($history as $h): ?>
                            <?php 
                                $stat = $h['EXECUTION_STATUS'];
                                $badgeClass = match ($stat) {
                                    'SUCCESS' => 'bg-success',
                                    'REJECTED' => 'bg-warning text-dark',
                                    default => 'bg-danger',
                                };
                            ?>
                            <tr>
                                <td class="font-monospace text-muted"><?= e($h['EXECUTED_AT']) ?></td>
                                <td><strong class="font-monospace text-dark"><?= e($h['USERNAME']) ?></strong></td>
                                <td><span class="badge bg-secondary font-monospace"><?= e($h['TARGET_SCHEMA']) ?></span></td>
                                <td><span class="badge bg-light text-dark border"><?= e($h['DDL_OPERATION']) ?></span></td>
                                <td>
                                    <code><?= e($h['OBJECT_TYPE']) ?> <?= e($h['OBJECT_NAME']) ?></code>
                                </td>
                                <td>
                                    <span class="badge <?= $badgeClass ?>"><?= e($stat) ?></span>
                                </td>
                                <td class="text-muted">
                                    <?= e($h['ORACLE_ERROR_MESSAGE'] ?: 'Exécution conforme') ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal d'Exécution DDL Contrôlée -->
<div class="modal fade" id="executeDdlModal" tabindex="-1" aria-labelledby="executeDdlModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form action="<?= zirioam_url('/scripts/execute') ?>" method="POST">
                <?= zirioam_csrf_field() ?>
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title" id="executeDdlModalLabel">Exécution DDL Contrôlée (PDB: <?= e($activePdb) ?>)</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="developer_username" class="form-label fw-semibold">Développeur demandeur :</label>
                            <select name="developer_username" id="developer_username" class="form-select font-monospace text-uppercase" required>
                                <option value="">-- Choisir un développeur --</option>
                                <?php foreach ($developers as $dev): ?>
                                    <option value="<?= e($dev['ORACLE_USER']) ?>">
                                        <?= e($dev['ORACLE_USER']) ?> (<?= e($dev['FULL_NAME']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="target_schema" class="form-label fw-semibold">Schéma cible :</label>
                            <?php if (!empty($schemas)): ?>
                                <select name="target_schema" id="target_schema" class="form-select font-monospace text-uppercase" required>
                                    <option value="">-- Choisir un schéma --</option>
                                    <?php foreach ($schemas as $s): ?>
                                        <option value="<?= e($s['USERNAME']) ?>"><?= e($s['USERNAME']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <input type="text" name="target_schema" id="target_schema" class="form-control font-monospace text-uppercase" placeholder="ex: GESTION" required>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label for="operation" class="form-label fw-semibold">Opération DDL :</label>
                            <select name="operation" id="operation" class="form-select" required>
                                <option value="CREATE">CREATE</option>
                                <option value="ALTER">ALTER</option>
                                <option value="DROP">DROP</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="object_type" class="form-label fw-semibold">Type d'objet :</label>
                            <select name="object_type" id="object_type" class="form-select" required>
                                <option value="TABLE">TABLE</option>
                                <option value="VIEW">VIEW</option>
                                <option value="INDEX">INDEX</option>
                                <option value="SEQUENCE">SEQUENCE</option>
                                <option value="PROCEDURE">PROCEDURE</option>
                                <option value="FUNCTION">FUNCTION</option>
                                <option value="PACKAGE">PACKAGE</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="object_name" class="form-label fw-semibold">Nom de l'objet :</label>
                            <input type="text" name="object_name" id="object_name" class="form-control text-uppercase font-monospace" placeholder="ex: CLIENTS" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="ddl_statement" class="form-label fw-semibold">Instruction DDL complète :</label>
                        <textarea name="ddl_statement" id="ddl_statement" class="form-control font-monospace" rows="5" 
                                  placeholder="CREATE TABLE GESTION.CLIENTS (ID NUMBER PRIMARY KEY, NOM VARCHAR2(100))" required></textarea>
                    </div>

                    <div class="alert alert-warning small mb-0">
                        🛡️ <strong>Contrôle de sécurité :</strong> Le moteur vérifie que le développeur possède le droit DDL correspondant sur le schéma dans la matrice. Les clauses malveillantes (triggers autonomes, external tables exécutables) sont formellement rejetées.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary fw-bold">Valider et Exécuter via le Moteur DDL</button>
                </div>
            </form>
        </div>
    </div>
</div>

