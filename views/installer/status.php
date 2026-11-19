<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-12 col-md-9 col-lg-7">
            <div class="card border-0 shadow rounded-3">
                <div class="card-header bg-dark text-white p-4 d-flex justify-content-between align-items-center">
                    <div>
                        <h4 class="mb-1 fw-bold">INITIALISATION DU SERVEUR</h4>
                        <div class="text-white-50 small">
                            Serveur : <strong><?= e($server['server_name'] ?? 'N/A') ?></strong> &bull; CDB : <strong><?= e($currentCdb ?? 'CDB$ROOT') ?></strong>
                        </div>
                    </div>
                    <span class="badge bg-warning text-dark px-3 py-2 fw-bold">NON INITIALISÉ</span>
                </div>
                <div class="card-body p-4 p-md-5 text-center">
                    <div class="mb-4">
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
                </div>
            </div>
        </div>
    </div>
</div>

