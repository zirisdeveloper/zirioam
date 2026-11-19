<?php
    $hasAccount = ((int)($developer['ORACLE_ACCOUNT_EXISTS'] ?? 0)) > 0;
    $oraStatus = $developer['ORACLE_ACCOUNT_STATUS'] ?? 'NON CRÉÉ';
    $isLocked = str_contains($oraStatus, 'LOCKED');
?>
<div class="py-2">
    <!-- En-tête -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="<?= zirioam_url('/developers') ?>">Développeurs</a></li>
                    <li class="breadcrumb-item active font-monospace fw-bold"><?= e($developer['USERNAME']) ?></li>
                </ol>
            </nav>
            <h2 class="h3 fw-bold text-dark mb-0">
                <?= e($developer['FULL_NAME']) ?>
                <span class="badge bg-secondary font-monospace ms-2"><?= e($developer['ORACLE_USER']) ?></span>
            </h2>
        </div>
        <div>
            <a href="<?= zirioam_url('/developers/' . (int)$developer['DEVELOPER_ID'] . '/edit') ?>" class="btn btn-outline-secondary btn-sm me-2">
                ✏️ Modifier l'identité
            </a>
            <a href="<?= zirioam_url('/developers') ?>" class="btn btn-outline-primary btn-sm">
                &larr; Retour à la liste
            </a>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <!-- 1. Fiche Identité ZIRIOAM -->
        <div class="col-12 col-lg-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3">
                    <h5 class="card-title mb-0 fw-bold text-dark">Informations d'Identité ZIRIOAM</h5>
                </div>
                <div class="card-body">
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item d-flex justify-content-between px-0 py-2">
                            <span class="text-muted">Identifiant :</span>
                            <strong class="font-monospace text-dark"><?= e($developer['USERNAME']) ?></strong>
                        </li>
                        <li class="list-group-item d-flex justify-content-between px-0 py-2">
                            <span class="text-muted">Compte Oracle Commun :</span>
                            <strong class="font-monospace text-dark"><?= e($developer['ORACLE_USER']) ?></strong>
                        </li>
                        <li class="list-group-item d-flex justify-content-between px-0 py-2">
                            <span class="text-muted">Nom complet :</span>
                            <span class="text-dark fw-semibold"><?= e($developer['FULL_NAME']) ?></span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between px-0 py-2">
                            <span class="text-muted">Email :</span>
                            <a href="mailto:<?= e($developer['EMAIL']) ?>"><?= e($developer['EMAIL']) ?></a>
                        </li>
                        <li class="list-group-item d-flex justify-content-between px-0 py-2">
                            <span class="text-muted">Statut Identité :</span>
                            <?php if ($developer['STATUS'] === 'ACTIVE'): ?>
                                <span class="badge bg-success">Actif</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Inactif</span>
                            <?php endif; ?>
                        </li>
                        <li class="list-group-item d-flex justify-content-between px-0 py-2">
                            <span class="text-muted">Créé le :</span>
                            <span class="text-muted small"><?= e($developer['CREATED_AT']) ?></span>
                        </li>
                    </ul>

                    <!-- Action d'activation / désactivation de l'identité -->
                    <div class="mt-3 pt-3 border-top">
                        <form action="<?= zirioam_url('/developers/' . (int)$developer['DEVELOPER_ID'] . '/status') ?>" method="POST" class="d-inline">
                            <?= zirioam_csrf_field() ?>
                            <?php if ($developer['STATUS'] === 'ACTIVE'): ?>
                                <input type="hidden" name="status" value="INACTIVE">
                                <button type="submit" class="btn btn-outline-warning btn-sm w-100" data-confirm="Désactiver cette identité développeur ?">
                                    Désactiver l'identité ZIRIOAM
                                </button>
                            <?php else: ?>
                                <input type="hidden" name="status" value="ACTIVE">
                                <button type="submit" class="btn btn-outline-success btn-sm w-100">
                                    Réactiver l'identité ZIRIOAM
                                </button>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. Gestion du Compte Oracle Commun CDB (Section 10 & 11) -->
        <div class="col-12 col-lg-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0 fw-bold text-dark">Compte Oracle Commun (CDB$ROOT)</h5>
                    <span class="badge <?= $hasAccount ? ($isLocked ? 'bg-danger' : 'bg-success') : 'bg-secondary' ?> px-2 py-1">
                        <?= e($oraStatus) ?>
                    </span>
                </div>
                <div class="card-body">
                    <?php if (!$hasAccount): ?>
                        <div class="alert alert-warning border-warning p-3 mb-4">
                            <h6 class="fw-bold text-dark mb-1">Compte Oracle non encore provisionné</h6>
                            <p class="small mb-0 text-muted">
                                Ce développeur est enregistré dans le référentiel ZIRIOAM mais ne possède pas encore de compte utilisateur physique dans Oracle 19c.
                            </p>
                        </div>
                        <form action="<?= zirioam_url('/developers/' . (int)$developer['DEVELOPER_ID'] . '/provision') ?>" method="POST">
                            <?= zirioam_csrf_field() ?>
                            <button type="submit" class="btn btn-primary py-2 w-100 fw-bold" data-confirm="Créer le compte Oracle commun C##<?= e($developer['USERNAME']) ?> avec CONTAINER = ALL ?">
                                ➕ Créer le Compte Oracle Commun (CDB$ROOT) &rarr;
                            </button>
                        </form>
                    <?php else: ?>
                        <div class="p-3 bg-light rounded border mb-3">
                            <div class="row g-2">
                                <div class="col-sm-6">
                                    <span class="text-muted small d-block">Utilisateur Oracle :</span>
                                    <strong class="font-monospace fs-5 text-dark"><?= e($developer['ORACLE_USER']) ?></strong>
                                </div>
                                <div class="col-sm-6">
                                    <span class="text-muted small d-block">Statut du Compte :</span>
                                    <span class="badge <?= $isLocked ? 'bg-danger' : 'bg-success' ?> fs-6"><?= e($oraStatus) ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Boutons d'Action sur le Compte Oracle -->
                        <div class="d-flex flex-wrap gap-2 mb-4">
                            <!-- Réinitialisation du mot de passe (Section 11) -->
                            <form action="<?= zirioam_url('/developers/' . (int)$developer['DEVELOPER_ID'] . '/password-reset') ?>" method="POST" class="d-inline">
                                <?= zirioam_csrf_field() ?>
                                <button type="submit" class="btn btn-danger fw-bold" data-confirm="Générer un nouveau mot de passe temporaire expiré pour <?= e($developer['ORACLE_USER']) ?> ?">
                                    🔑 Réinitialiser le Mot de Passe
                                </button>
                            </form>

                            <!-- Verrouillage / Déverrouillage Oracle -->
                            <?php if ($isLocked): ?>
                                <form action="<?= zirioam_url('/developers/' . (int)$developer['DEVELOPER_ID'] . '/unlock') ?>" method="POST" class="d-inline">
                                    <?= zirioam_csrf_field() ?>
                                    <button type="submit" class="btn btn-outline-success">
                                        🔓 Déverrouiller le compte
                                    </button>
                                </form>
                            <?php else: ?>
                                <form action="<?= zirioam_url('/developers/' . (int)$developer['DEVELOPER_ID'] . '/lock') ?>" method="POST" class="d-inline">
                                    <?= zirioam_csrf_field() ?>
                                    <button type="submit" class="btn btn-outline-warning" data-confirm="Verrouiller le compte Oracle <?= e($developer['ORACLE_USER']) ?> dans toute la CDB ?">
                                        🔒 Verrouiller le compte
                                    </button>
                                </form>
                            <?php endif; ?>

                            <!-- Lien vers l'attribution de droits sur PDB -->
                            <a href="<?= zirioam_url('/access') ?>" class="btn btn-outline-primary">
                                Gérer les droits sur les PDB &rarr;
                            </a>
                        </div>

                        <div class="alert alert-secondary small mb-0 border">
                            <h6 class="fw-bold mb-1">Règles fondamentales Oracle :</h6>
                            <ul class="mb-0 ps-3">
                                <li>La réinitialisation s'applique avec <code>PASSWORD EXPIRE CONTAINER = ALL</code>.</li>
                                <li>Le développeur devra changer son mot de passe dès sa première connexion (SQL Developer / SQL*Plus).</li>
                                <li>Aucun mot de passe n'est enregistré dans les bases ZIRIOAM.</li>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- 3. Historique d'Audit du Développeur (Section 16) -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
            <h5 class="card-title mb-0 fw-bold text-dark">Historique des Opérations Administratives</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-zirioam align-middle mb-0 small">
                <thead>
                    <tr>
                        <th style="width: 170px;">Date & Heure</th>
                        <th>Opération</th>
                        <th>DBA Exécutant</th>
                        <th>Détails</th>
                        <th class="text-end">Statut</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($history)): ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted py-3">
                                Aucun événement d'audit enregistré pour ce développeur.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($history as $h): ?>
                            <tr>
                                <td class="font-monospace text-muted"><?= e($h['EVENT_TIME']) ?></td>
                                <td><strong><?= e($h['ACTION_TYPE']) ?></strong></td>
                                <td><span class="badge bg-light text-dark border"><?= e($h['DBA_USER']) ?></span></td>
                                <td><?= e($h['DETAILS']) ?></td>
                                <td class="text-end">
                                    <span class="badge <?= $h['STATUS'] === 'SUCCESS' ? 'bg-success' : 'bg-danger' ?>">
                                        <?= e($h['STATUS']) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

