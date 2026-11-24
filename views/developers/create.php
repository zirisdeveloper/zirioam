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

                        <!-- Option de provisionnement immédiat et mot de passe -->
                        <div class="card border bg-light mb-4 p-3 rounded-2">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="provision_oracle" id="provision_oracle" value="1" checked>
                                <label class="form-check-label fw-bold text-dark" for="provision_oracle">
                                    Créer le compte Oracle commun immédiatement (C##...)
                                </label>
                            </div>
                            <div class="form-text mb-3">
                                Si activé, l'ordre <code>CREATE USER C##... CONTAINER = ALL</code> est exécuté en base avec expiration obligatoire du mot de passe.
                            </div>

                            <div id="password-options" class="ps-2 border-start border-3 border-primary">
                                <label class="form-label fw-semibold text-dark small mb-2">Attribution du mot de passe initial :</label>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="radio" name="password_mode" id="pwd_mode_auto" value="auto" checked>
                                    <label class="form-check-label" for="pwd_mode_auto">
                                        <strong>Génération automatique sécurisée</strong> (Recommandé : 14 caractères alphanumériques + symboles)
                                    </label>
                                </div>
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="radio" name="password_mode" id="pwd_mode_manual" value="manual">
                                    <label class="form-check-label" for="pwd_mode_manual">
                                        <strong>Saisie manuelle</strong> par le DBA
                                    </label>
                                </div>

                                <div id="manual-password-block" class="mb-2 d-none">
                                    <label for="custom_password" class="form-label small fw-semibold">Mot de passe temporaire souhaité</label>
                                    <input type="password" name="custom_password" id="custom_password" class="form-control" 
                                           placeholder="Min. 8 car. (Majuscule, minuscule, chiffre)" autocomplete="new-password">
                                    <div class="form-text small">Doit respecter les critères de complexité Oracle 19c.</div>
                                </div>

                                <div class="alert alert-info py-2 px-3 small mb-0 mt-2">
                                    🔒 Le mot de passe initial sera immédiatement actif et vous sera révélé <strong>une seule fois</strong> sur l'écran suivant avec un bouton <strong>COPIER</strong>.
                                </div>

                                <?php $sessVault = (string)\App\Core\App::getInstance()->getSession()->get('vault_user', ''); ?>
                                <?php if (!empty($sessVault)): ?>
                                    <div class="mt-3 p-2 bg-white rounded border small d-flex align-items-center">
                                        <span class="badge bg-success me-2">🛡️ Database Vault</span>
                                        <span>Création sécurisée via <strong><?= e($sessVault) ?></strong></span>
                                    </div>
                                <?php else: ?>
                                    <div class="mt-3 p-2 bg-white rounded border">
                                        <span class="badge bg-secondary mb-1">🛡️ Option Database Vault</span>
                                        <div class="text-muted extra-small mb-2" style="font-size:0.8rem;">
                                            Renseignez ces identifiants uniquement si Oracle Database Vault est actif sur la base.
                                        </div>
                                        <div class="row g-2">
                                            <div class="col-sm-6">
                                                <input type="text" name="vault_user" class="form-control form-control-sm font-monospace text-uppercase" placeholder="C##VOLTMANAGER" value="C##VOLTMANAGER">
                                            </div>
                                            <div class="col-sm-6">
                                                <input type="password" name="vault_password" class="form-control form-control-sm" placeholder="Mot de passe C##VOLTMANAGER">
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
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

<script>
document.addEventListener('DOMContentLoaded', function() {
    const chkProvision = document.getElementById('provision_oracle');
    const pwdOptions = document.getElementById('password-options');
    const radAuto = document.getElementById('pwd_mode_auto');
    const radManual = document.getElementById('pwd_mode_manual');
    const manualBlock = document.getElementById('manual-password-block');
    const customPwd = document.getElementById('custom_password');

    function syncProvision() {
        if (chkProvision.checked) {
            pwdOptions.classList.remove('d-none');
        } else {
            pwdOptions.classList.add('d-none');
        }
    }

    function syncPasswordMode() {
        if (radManual.checked) {
            manualBlock.classList.remove('d-none');
            customPwd.required = true;
        } else {
            manualBlock.classList.add('d-none');
            customPwd.required = false;
        }
    }

    chkProvision.addEventListener('change', syncProvision);
    radAuto.addEventListener('change', syncPasswordMode);
    radManual.addEventListener('change', syncPasswordMode);

    syncProvision();
    syncPasswordMode();
});
</script>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

