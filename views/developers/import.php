<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-12 col-lg-10">
            <!-- En-tête -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2 class="h3 fw-bold text-dark mb-1">Importation JSON des Développeurs</h2>
                    <p class="text-muted mb-0">Règle absolue : Comportement strictement <strong>ADD ONLY</strong> (Aucun écrasement).</p>
                </div>
                <a href="<?= zirioam_url('/developers') ?>" class="btn btn-outline-secondary btn-sm">
                    &larr; Retour aux Développeurs
                </a>
            </div>

            <?php if (empty($analysis)): ?>
                <!-- Étape 1 : Téléversement du Fichier JSON -->
                <div class="card border-0 shadow-sm rounded-3">
                    <div class="card-header bg-primary text-white p-4">
                        <h5 class="mb-1 fw-bold">Sélection du Fichier JSON à Importer</h5>
                        <p class="mb-0 text-white-50 small">Format supporté : <code>ZIRIOAM_DEVELOPERS</code> (v1.0)</p>
                    </div>
                    <div class="card-body p-4">
                        <form action="<?= zirioam_url('/developers/import/preview') ?>" method="POST" enctype="multipart/form-data">
                            <?= zirioam_csrf_field() ?>

                            <div class="mb-4">
                                <label for="import_file" class="form-label fw-semibold">Fichier JSON d'exportation :</label>
                                <input type="file" name="import_file" id="import_file" class="form-control" accept=".json,application/json" required>
                                <div class="form-text">Taille maximale : 5 Mo. Seules les informations d'identité sont prises en compte.</div>
                            </div>

                            <div class="alert alert-warning border p-3 mb-4 small">
                                <h6 class="fw-bold text-dark mb-1">🛡️ RÈGLES DE SÉCURITÉ ABSOLUES :</h6>
                                <ul class="mb-0 ps-3">
                                    <li>L'importation ne doit <strong>JAMAIS</strong> écraser, modifier, désactiver ou supprimer un développeur déjà présent sur le serveur cible.</li>
                                    <li>Aucun compte Oracle n'est créé automatiquement lors de l'import.</li>
                                    <li>Aucun privilège ni rôle n'est attribué automatiquement.</li>
                                </ul>
                            </div>

                            <button type="submit" class="btn btn-primary px-4 fw-bold">
                                ANALYSER LE FICHIER ET AFFICHER L'APERÇU &rarr;
                            </button>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <!-- Étape 2 : Analyse et Aperçu Avant Confirmation (Section 14.2) -->
                <div class="card border-0 shadow-sm rounded-3 mb-4">
                    <div class="card-header bg-dark text-white p-4 d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="mb-1 fw-bold">Résultat de l'Analyse du Fichier</h5>
                            <span class="text-white-50 small">Fichier : <code><?= e($fileName) ?></code> &bull; Source : <?= e($analysis['source_server']) ?></span>
                        </div>
                        <span class="badge bg-light text-dark px-3 py-2 fw-bold">
                            Total : <?= (int)$analysis['total_in_file'] ?> développeur(s)
                        </span>
                    </div>
                    <div class="card-body p-4">
                        <!-- Message d'avertissement obligatoire -->
                        <?php if (!empty($analysis['warning_message'])): ?>
                            <div class="alert alert-warning border-warning shadow-sm p-3 mb-4">
                                <h5 class="alert-heading fw-bold text-dark mb-1">⚠️ Développeurs déjà existants</h5>
                                <p class="mb-0 fw-semibold text-dark">
                                    <?= e($analysis['warning_message']) ?>
                                </p>
                            </div>
                        <?php endif; ?>

                        <!-- Synthèse chiffrée -->
                        <div class="row g-3 mb-4 text-center">
                            <div class="col-md-4">
                                <div class="p-3 bg-light rounded border">
                                    <span class="text-muted small d-block">Développeurs dans le fichier</span>
                                    <strong class="fs-4 text-dark"><?= (int)$analysis['total_in_file'] ?></strong>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="p-3 bg-light rounded border border-warning">
                                    <span class="text-muted small d-block">Déjà existants (Ignorés)</span>
                                    <strong class="fs-4 text-warning"><?= (int)$analysis['existing_count'] ?></strong>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="p-3 bg-light rounded border border-success">
                                    <span class="text-muted small d-block">Nouveaux (À importer)</span>
                                    <strong class="fs-4 text-success">+<?= (int)$analysis['new_count'] ?></strong>
                                </div>
                            </div>
                        </div>

                        <!-- Tableau des Nouveaux Développeurs à Ajouter -->
                        <?php if (!empty($analysis['new_developers'])): ?>
                            <h6 class="fw-bold text-success mb-2">✓ Nouveaux Développeurs qui seront ajoutés :</h6>
                            <div class="table-responsive mb-4">
                                <table class="table table-sm table-bordered align-middle small mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Username</th>
                                            <th>Nom complet</th>
                                            <th>Email</th>
                                            <th>Statut</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($analysis['new_developers'] as $d): ?>
                                            <tr class="table-success">
                                                <td class="font-monospace fw-bold"><?= e($d['username']) ?></td>
                                                <td><?= e($d['full_name']) ?></td>
                                                <td><?= e($d['email']) ?></td>
                                                <td><span class="badge bg-success"><?= e($d['status']) ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info small mb-4">
                                Tous les développeurs de ce fichier sont déjà présents sur ce serveur. Aucun ajout à effectuer.
                            </div>
                        <?php endif; ?>

                        <!-- Tableau des Développeurs Existants Strictement Conservés -->
                        <?php if (!empty($analysis['existing_developers'])): ?>
                            <h6 class="fw-bold text-muted mb-2">Développeurs déjà présents sur le serveur (Strictement inchangés) :</h6>
                            <div class="table-responsive mb-4">
                                <table class="table table-sm table-bordered align-middle small mb-0 text-muted">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Username</th>
                                            <th>Nom complet</th>
                                            <th>Email</th>
                                            <th>État</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($analysis['existing_developers'] as $d): ?>
                                            <tr class="bg-light">
                                                <td class="font-monospace"><?= e($d['username']) ?></td>
                                                <td><?= e($d['full_name']) ?></td>
                                                <td><?= e($d['email']) ?></td>
                                                <td><span class="badge bg-secondary">Inchangé</span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>

                        <!-- Formulaire de confirmation ADD ONLY -->
                        <div class="d-flex justify-content-between align-items-center pt-3 border-top">
                            <a href="<?= zirioam_url('/developers/import') ?>" class="btn btn-outline-secondary">
                                &larr; Annuler / Autre fichier
                            </a>
                            <?php if ($analysis['new_count'] > 0): ?>
                                <form action="<?= zirioam_url('/developers/import/confirm') ?>" method="POST">
                                    <?= zirioam_csrf_field() ?>
                                    <button type="submit" class="btn btn-success px-4 py-2 fw-bold">
                                        IMPORTER LES <?= (int)$analysis['new_count'] ?> NOUVEAUX DÉVELOPPEURS &rarr;
                                    </button>
                                </form>
                            <?php else: ?>
                                <a href="<?= zirioam_url('/developers') ?>" class="btn btn-primary px-4">
                                    Retour aux développeurs
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

