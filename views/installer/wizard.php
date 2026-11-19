<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-12 col-lg-10">
            <div class="card border-0 shadow-sm rounded-3">
                <div class="card-header bg-primary text-white p-4">
                    <h4 class="mb-1 fw-bold">Assistant d'Initialisation ZIRIOAM</h4>
                    <p class="mb-0 text-white-50 small">
                        Vérification des prérequis techniques et confirmation du déploiement
                    </p>
                </div>
                <div class="card-body p-4">
                    <!-- 1. Prérequis techniques -->
                    <h5 class="fw-bold text-dark border-bottom pb-2 mb-3">1. Vérification des Prérequis Oracle</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <div class="p-3 bg-light rounded border h-100">
                                <div class="text-muted small">Version Oracle :</div>
                                <strong class="d-block text-dark small mb-1"><?= e($banner) ?></strong>
                                <span class="badge bg-success">Conforme (>= 19c)</span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-3 bg-light rounded border h-100">
                                <div class="text-muted small">Conteneur d'Initialisation :</div>
                                <strong class="d-block text-dark font-monospace mb-1"><?= e($currentContainer) ?></strong>
                                <span class="badge bg-info text-dark">CDB Racine</span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-3 bg-light rounded border h-100">
                                <div class="text-muted small">Privilèges d'Installation :</div>
                                <strong class="d-block text-dark mb-1">
                                    <?= $hasDbaPrivs ? 'Droits DBA / SYSDBA Détectés' : 'Privilèges Limités' ?>
                                </strong>
                                <span class="badge <?= $hasDbaPrivs ? 'bg-success' : 'bg-warning' ?>">
                                    <?= $hasDbaPrivs ? 'Validé' : 'Vérification requise' ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- 2. Objets qui seront créés -->
                    <h5 class="fw-bold text-dark border-bottom pb-2 mb-3">2. Objets et Composants Déployés</h5>
                    <div class="table-responsive mb-4">
                        <table class="table table-bordered table-sm align-middle small mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 70px;">Étape</th>
                                    <th>Nom du Composant</th>
                                    <th>Fichier Source</th>
                                    <th>Cible</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($manifest['common_scripts'] ?? [] as $script): ?>
                                    <tr>
                                        <td class="text-center font-monospace fw-bold"><?= (int)$script['step'] ?></td>
                                        <td><strong><?= e($script['name']) ?></strong></td>
                                        <td><code><?= e($script['file']) ?></code></td>
                                        <td><span class="badge bg-secondary"><?= e($script['target_container']) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- 3. Formulaire de confirmation explicite -->
                    <div class="alert alert-warning border p-3 mb-4">
                        <h6 class="fw-bold text-dark mb-1">⚠️ Confirmation requise du DBA</h6>
                        <p class="mb-0 small text-muted">
                            L'initialisation va créer le propriétaire technique <code>C##ZIRIOAM_ADMIN</code>, le référentiel des développeurs et les packages d'orchestration dans <code>CDB$ROOT</code>.
                            Aucune table applicative n'est créée dans SYS ou SYSTEM. Les scripts sont idempotents.
                        </p>
                    </div>

                    <form action="<?= zirioam_url('/installer/execute') ?>" method="POST">
                        <?= zirioam_csrf_field() ?>
                        <input type="hidden" name="target" value="CDB">

                        <div class="form-check mb-4">
                            <input class="form-check-input" type="checkbox" id="confirm_install" required>
                            <label class="form-check-label fw-semibold" for="confirm_install">
                                Je confirme l'initialisation du système ZIRIOAM sur ce serveur Oracle.
                            </label>
                        </div>

                        <div class="d-flex justify-content-between align-items-center">
                            <a href="<?= zirioam_url('/installer/status') ?>" class="btn btn-outline-secondary">
                                &larr; Annuler
                            </a>
                            <button type="submit" class="btn btn-primary px-4 py-2 fw-bold">
                                LANCER L'INITIALISATION DU SERVEUR &rarr;
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

