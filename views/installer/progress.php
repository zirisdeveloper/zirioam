<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-12 col-lg-8">
            <div class="card border-0 shadow-sm rounded-3">
                <div class="card-header bg-dark text-white p-4">
                    <h4 class="mb-1 fw-bold">Rapport d'Exécution de l'Installation ZIRIOAM</h4>
                    <p class="mb-0 text-white-50 small">
                        Cible : <strong><?= e($target) ?></strong> &bull; Progression pas-à-pas
                    </p>
                </div>
                <div class="card-body p-4">
                    <!-- Liste des étapes exécutées -->
                    <h5 class="fw-bold text-dark mb-3">Étapes d'Initialisation :</h5>
                    <div class="list-group mb-4">
                        <?php foreach ($stepResults as $res): ?>
                            <?php $isOk = ($res['status'] === 'SUCCESS'); ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center p-3 <?= $isOk ? '' : 'list-group-item-danger' ?>">
                                <div class="d-flex align-items-center gap-3">
                                    <span class="badge <?= $isOk ? 'bg-success' : 'bg-danger' ?> px-2 py-1 font-monospace">
                                        <?= $isOk ? '[OK]' : '[ERR]' ?>
                                    </span>
                                    <div>
                                        <strong class="d-block text-dark small"><?= e($res['name']) ?></strong>
                                        <?php if (!empty($res['error'])): ?>
                                            <span class="text-danger small font-monospace d-block mt-1">
                                                <?= e($res['error']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <span class="badge <?= $isOk ? 'bg-light text-success border border-success' : 'bg-danger text-white' ?>">
                                    <?= e($res['status']) ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- En cas d'échec : Bouton de reprise / réparation -->
                    <?php if ($hasFailed): ?>
                        <div class="alert alert-danger p-3 mb-4">
                            <h6 class="fw-bold mb-1">⚠️ Interruption de l'installation</h6>
                            <p class="mb-0 small">
                                En raison des validations implicites des ordres DDL Oracle, les objets créés lors des étapes précédentes sont conservés.
                                Vous pouvez relancer la procédure : les scripts idempotents reprendront les étapes manquantes sans doublon.
                            </p>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <a href="<?= zirioam_url('/installer/status') ?>" class="btn btn-outline-secondary">
                                &larr; Retour à l'état
                            </a>
                            <form action="<?= zirioam_url('/installer/repair') ?>" method="POST" class="d-inline">
                                <?= zirioam_csrf_field() ?>
                                <button type="submit" class="btn btn-warning fw-bold px-4">
                                    RÉPARER / REPRENDRE &rarr;
                                </button>
                            </form>
                        </div>
                    <?php else: ?>
                        <!-- Succès : Redirection vers tableau de bord -->
                        <div class="alert alert-success p-3 mb-4 text-center">
                            <h5 class="fw-bold mb-1">✓ Installation Réussie avec Succès !</h5>
                            <p class="mb-0 small">Le référentiel ZIRIOAM est maintenant entièrement initialisé et validé.</p>
                        </div>
                        <div class="text-center">
                            <a href="<?= zirioam_url('/dashboard') ?>" class="btn btn-success btn-lg px-5 fw-bold">
                                ACCÉDER AU TABLEAU DE BORD &rarr;
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

