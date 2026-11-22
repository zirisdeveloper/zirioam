<?php
    $isInstalled = ($cdbStatus['status'] === \App\Services\InstallationDetectorService::INSTALLED);
    $isPartial = in_array($cdbStatus['status'], [
        \App\Services\InstallationDetectorService::INCOMPLETE,
        \App\Services\InstallationDetectorService::PARTIAL,
        \App\Services\InstallationDetectorService::OUTDATED,
        \App\Services\InstallationDetectorService::INVALID,
        \App\Services\InstallationDetectorService::INSTALLATION_FAILED
    ]);
    $isNotInstalled = ($cdbStatus['status'] === \App\Services\InstallationDetectorService::NOT_INSTALLED);
?>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-12 col-md-10 col-lg-8">
            <div class="card border-0 shadow rounded-3">
                <div class="card-header bg-dark text-white p-4 d-flex justify-content-between align-items-center">
                    <div>
                        <h4 class="mb-1 fw-bold">ÉTAT DES COMPOSANTS ZIRIOAM</h4>
                        <div class="text-white-50 small">
                            Serveur : <strong><?= e($server['server_name'] ?? 'N/A') ?></strong> &bull; CDB : <strong><?= e($currentCdb ?? 'CDB$ROOT') ?></strong>
                        </div>
                    </div>
                    <?php if ($isInstalled): ?>
                        <span class="badge bg-success px-3 py-2 fw-bold fs-6">INITIALISÉ & VALIDÉ</span>
                    <?php elseif ($isPartial): ?>
                        <span class="badge bg-warning text-dark px-3 py-2 fw-bold fs-6">INCOMPLET / EN COURS</span>
                    <?php else: ?>
                        <span class="badge bg-warning text-dark px-3 py-2 fw-bold">NON INITIALISÉ</span>
                    <?php endif; ?>
                </div>
                <div class="card-body p-4 p-md-5">

                    <?php if ($isNotInstalled): ?>
                        <div class="text-center mb-4">
                            <div class="display-3 text-warning mb-3">⚠️</div>
                            <h3 class="fw-bold text-dark">NOUVEAU SERVEUR ORACLE</h3>
                            <p class="lead text-secondary my-3">
                                Ce serveur n'est pas encore configuré pour la gestion des développeurs.
                            </p>
                            <p class="text-muted">
                                Veuillez cliquer sur le bouton <strong>CONFIGURER</strong> pour l'initialisation du système de gestion des développeurs.
                            </p>
                        </div>

                        <!-- Message d'état technique -->
                        <div class="alert alert-light border text-start p-3 mb-4 small">
                            <strong>Diagnostic du détecteur :</strong> <?= e($cdbStatus['message']) ?><br>
                            <strong>Conteneur analysé :</strong> <code><?= e($cdbStatus['container']) ?></code><br>
                            <strong>Propriétaire cible :</strong> <code><?= e($cdbStatus['owner']) ?></code>
                        </div>

                        <!-- Bouton CONFIGURER obligatoire -->
                        <div class="d-grid gap-2 col-md-8 mx-auto">
                            <a href="<?= zirioam_url('/installer/wizard') ?>" class="btn btn-warning btn-lg fw-bold py-3 shadow-sm text-dark">
                                CONFIGURER &rarr;
                            </a>
                            <a href="<?= zirioam_url('/servers') ?>" class="btn btn-outline-secondary mt-2">
                                Changer de serveur Oracle
                            </a>
                        </div>

                    <?php else: ?>
                        <div class="text-center mb-4">
                            <div class="display-4 text-success mb-2">&check;</div>
                            <h3 class="fw-bold text-dark">Composants ZIRIOAM Déployés</h3>
                            <p class="text-muted mb-0">
                                <?= e($cdbStatus['message']) ?>
                            </p>
                        </div>

                        <div class="row g-3 mb-4">
                            <div class="col-md-4">
                                <div class="p-3 bg-light rounded border text-center">
                                    <span class="text-muted small d-block">Version Produit</span>
                                    <strong class="fs-5 text-dark"><?= e($cdbStatus['version'] ?? '1.0.0') ?></strong>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="p-3 bg-light rounded border text-center">
                                    <span class="text-muted small d-block">Schéma Propriétaire</span>
                                    <strong class="fs-5 font-monospace text-dark"><?= e($cdbStatus['owner']) ?></strong>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="p-3 bg-light rounded border text-center">
                                    <span class="text-muted small d-block">Objets Invalides</span>
                                    <?php $invCount = count($invalidObjects ?? []); ?>
                                    <strong class="fs-5 <?= $invCount > 0 ? 'text-danger' : 'text-success' ?>">
                                        <?= $invCount ?>
                                    </strong>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap gap-2 justify-content-center pt-2">
                            <a href="<?= zirioam_url('/dashboard') ?>" class="btn btn-primary px-4 py-2 fw-bold">
                                Accéder au Tableau de bord &rarr;
                            </a>
                            <a href="<?= zirioam_url('/installer/maintenance') ?>" class="btn btn-outline-dark px-4 py-2 fw-semibold">
                                🛠️ Maintenance & Réexécution des Composants
                            </a>
                            <form action="<?= zirioam_url('/installer/repair') ?>" method="POST" class="d-inline">
                                <?= zirioam_csrf_field() ?>
                                <button type="submit" class="btn btn-outline-secondary py-2">
                                    Recompiler les objets invalides
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>

                </div>
            </div>
        </div>
    </div>
</div>

