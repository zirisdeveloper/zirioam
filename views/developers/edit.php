<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-12 col-md-8 col-lg-6">
            <div class="card border-0 shadow-sm rounded-3">
                <div class="card-header bg-dark text-white p-4">
                    <h4 class="mb-1 fw-bold">Modifier l'Identité Développeur</h4>
                    <p class="mb-0 text-white-50 small">
                        Identifiant : <strong><?= e($developer['USERNAME']) ?></strong> (<?= e($developer['ORACLE_USER']) ?>)
                    </p>
                </div>
                <div class="card-body p-4">
                    <form action="<?= zirioam_url('/developers/' . (int)$developer['DEVELOPER_ID']) ?>" method="POST">
                        <?= zirioam_csrf_field() ?>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Identifiant (Non modifiable)</label>
                            <input type="text" class="form-control font-monospace bg-light" value="<?= e($developer['USERNAME']) ?>" readonly>
                        </div>

                        <div class="mb-3">
                            <label for="full_name" class="form-label fw-semibold">Nom complet</label>
                            <input type="text" name="full_name" id="full_name" class="form-control" value="<?= e($developer['FULL_NAME']) ?>" required>
                        </div>

                        <div class="mb-3">
                            <label for="email" class="form-label fw-semibold">Adresse email</label>
                            <input type="email" name="email" id="email" class="form-control" value="<?= e($developer['EMAIL']) ?>" required>
                        </div>

                        <div class="mb-4">
                            <label for="status" class="form-label fw-semibold">Statut de l'identité</label>
                            <select name="status" id="status" class="form-select">
                                <option value="ACTIVE" <?= $developer['STATUS'] === 'ACTIVE' ? 'selected' : '' ?>>ACTIVE (Actif)</option>
                                <option value="INACTIVE" <?= $developer['STATUS'] === 'INACTIVE' ? 'selected' : '' ?>>INACTIVE (Inactif)</option>
                            </select>
                        </div>

                        <div class="d-flex justify-content-between align-items-center">
                            <a href="<?= zirioam_url('/developers/' . (int)$developer['DEVELOPER_ID']) ?>" class="btn btn-outline-secondary">
                                &larr; Annuler
                            </a>
                            <button type="submit" class="btn btn-primary px-4 fw-bold">
                                Enregistrer les modifications &rarr;
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

