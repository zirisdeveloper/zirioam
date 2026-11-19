<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-12 col-md-8 col-lg-6">
            <div class="card border-0 shadow-sm rounded-3">
                <div class="card-header bg-primary text-white p-4">
                    <h4 class="mb-1 fw-bold">Nouveau Développeur</h4>
                    <p class="mb-0 text-white-50 small">Enregistrement d'une identité dans le référentiel ZIRIOAM</p>
                </div>
                <div class="card-body p-4">
                    <form action="<?= zirioam_url('/developers') ?>" method="POST">
                        <?= zirioam_csrf_field() ?>

                        <div class="mb-3">
                            <label for="username" class="form-label fw-semibold">Identifiant développeur (Username)</label>
                            <div class="input-group">
                                <span class="input-group-text font-monospace bg-light">C##</span>
                                <input type="text" name="username" id="username" class="form-control font-monospace text-uppercase" 
                                       placeholder="ex: ALI, KARIM, A1" required autofocus pattern="[A-Za-z0-9_$#]+" maxlength="120">
                            </div>
                            <div class="form-text">Le préfixe commun <code>C##</code> sera automatiquement associé au compte Oracle.</div>
                        </div>

                        <div class="mb-3">
                            <label for="full_name" class="form-label fw-semibold">Nom complet</label>
                            <input type="text" name="full_name" id="full_name" class="form-control" placeholder="ex: Ali Benali" required>
                        </div>

                        <div class="mb-3">
                            <label for="email" class="form-label fw-semibold">Adresse email</label>
                            <input type="email" name="email" id="email" class="form-control" placeholder="ex: ali@company.com" required>
                        </div>

                        <div class="mb-4">
                            <label for="status" class="form-label fw-semibold">Statut initial de l'identité</label>
                            <select name="status" id="status" class="form-select">
                                <option value="ACTIVE" selected>ACTIVE (Actif)</option>
                                <option value="INACTIVE">INACTIVE (Inactif)</option>
                            </select>
                        </div>

                        <div class="alert alert-info small border mb-4">
                            ℹ️ <strong>Règle de sécurité :</strong> L'enregistrement de l'identité n'ouvre aucun compte ni droit Oracle automatiquement. Vous pourrez provisionner le compte commun dans la fiche détaillée.
                        </div>

                        <div class="d-flex justify-content-between align-items-center">
                            <a href="<?= zirioam_url('/developers') ?>" class="btn btn-outline-secondary">
                                &larr; Annuler
                            </a>
                            <button type="submit" class="btn btn-primary px-4 fw-bold">
                                Créer le développeur &rarr;
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

