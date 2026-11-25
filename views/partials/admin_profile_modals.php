<?php if (!empty($currentAdminUser)): ?>
<?php
    $redirectUri = (string)($_SERVER['REQUEST_URI'] ?? '/');
    $adminList = $adminUsernames ?? [];
?>

<!-- Modal 1 : Modifier mon mot de passe administrateur -->
<div class="modal fade" id="modalAdminChangePassword" tabindex="-1" aria-labelledby="modalAdminChangePasswordLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form action="<?= zirioam_url('/admin/password') ?>" method="POST" onsubmit="return zirioamValidatePasswordModal('own');">
                <?= zirioam_csrf_field() ?>
                <input type="hidden" name="redirect_to" value="<?= e($redirectUri) ?>">

                <div class="modal-header bg-primary text-white py-3">
                    <h5 class="modal-title fw-bold" id="modalAdminChangePasswordLabel">
                        🔑 Modifier mon mot de passe (<?= e($currentAdminUser) ?>)
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fermer"></button>
                </div>

                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label for="own_current_password" class="form-label fw-bold small">Mot de passe actuel <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" name="current_password" id="own_current_password" class="form-control" required autocomplete="current-password">
                            <button type="button" class="btn btn-outline-secondary" onclick="zirioamToggleModalPwd('own_current_password', this)">👁️</button>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="own_new_password" class="form-label fw-bold small">Nouveau mot de passe <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" name="new_password" id="own_new_password" class="form-control" minlength="6" placeholder="Min. 6 caractères" required autocomplete="new-password">
                            <button type="button" class="btn btn-outline-secondary" onclick="zirioamToggleModalPwd('own_new_password', this)">👁️</button>
                        </div>
                    </div>

                    <div class="mb-2">
                        <label for="own_confirm_password" class="form-label fw-bold small">Confirmer le nouveau mot de passe <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" name="confirm_password" id="own_confirm_password" class="form-control" minlength="6" required autocomplete="new-password">
                            <button type="button" class="btn btn-outline-secondary" onclick="zirioamToggleModalPwd('own_confirm_password', this)">👁️</button>
                        </div>
                    </div>
                </div>

                <div class="modal-footer bg-light py-2 px-4">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary fw-bold">Enregistrer le nouveau mot de passe</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal 2 : Créer un nouvel administrateur -->
<div class="modal fade" id="modalAdminCreate" tabindex="-1" aria-labelledby="modalAdminCreateLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form action="<?= zirioam_url('/admin/users/create') ?>" method="POST" onsubmit="return zirioamValidatePasswordModal('create');">
                <?= zirioam_csrf_field() ?>
                <input type="hidden" name="redirect_to" value="<?= e($redirectUri) ?>">

                <div class="modal-header bg-success text-white py-3">
                    <h5 class="modal-title fw-bold" id="modalAdminCreateLabel">
                        ➕ Créer un nouvel administrateur
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fermer"></button>
                </div>

                <div class="modal-body p-4">
                    <?php if (!empty($adminList)): ?>
                        <div class="alert alert-light border small py-2 mb-3">
                            <span class="text-muted">Administrateurs existants :</span>
                            <div class="mt-1 d-flex flex-wrap gap-1">
                                <?php foreach ($adminList as $adm): ?>
                                    <span class="badge bg-secondary font-monospace"><?= e($adm) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <label for="create_admin_username" class="form-label fw-bold small">Nom d'utilisateur <span class="text-danger">*</span></label>
                        <input type="text" name="new_username" id="create_admin_username" class="form-control font-monospace text-uppercase" placeholder="ex: ADMIN02" pattern="[A-Za-z0-9_.\-]{3,50}" required autocomplete="off">
                        <div class="form-text small">3 à 50 caractères (lettres, chiffres, _, ., -).</div>
                    </div>

                    <div class="mb-3">
                        <label for="create_new_password" class="form-label fw-bold small">Mot de passe initial <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" name="new_password" id="create_new_password" class="form-control" minlength="6" placeholder="Min. 6 caractères" required autocomplete="new-password">
                            <button type="button" class="btn btn-outline-secondary" onclick="zirioamToggleModalPwd('create_new_password', this)">👁️</button>
                        </div>
                    </div>

                    <div class="mb-2">
                        <label for="create_confirm_password" class="form-label fw-bold small">Confirmer le mot de passe <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" name="confirm_password" id="create_confirm_password" class="form-control" minlength="6" required autocomplete="new-password">
                            <button type="button" class="btn btn-outline-secondary" onclick="zirioamToggleModalPwd('create_confirm_password', this)">👁️</button>
                        </div>
                    </div>
                </div>

                <div class="modal-footer bg-light py-2 px-4">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-success fw-bold">Créer l'administrateur</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal 3 : Initialiser le mot de passe d'un autre administrateur -->
<div class="modal fade" id="modalAdminResetPassword" tabindex="-1" aria-labelledby="modalAdminResetPasswordLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form action="<?= zirioam_url('/admin/users/reset-password') ?>" method="POST" onsubmit="return zirioamValidatePasswordModal('reset');">
                <?= zirioam_csrf_field() ?>
                <input type="hidden" name="redirect_to" value="<?= e($redirectUri) ?>">

                <div class="modal-header bg-warning text-dark py-3">
                    <h5 class="modal-title fw-bold" id="modalAdminResetPasswordLabel">
                        🔄 Initialiser le mot de passe d'un administrateur
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                </div>

                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label for="reset_target_username" class="form-label fw-bold small">Administrateur cible <span class="text-danger">*</span></label>
                        <select name="target_username" id="reset_target_username" class="form-select font-monospace" required>
                            <option value="" disabled selected>-- Sélectionnez un administrateur --</option>
                            <?php foreach ($adminList as $adm): ?>
                                <option value="<?= e($adm) ?>">
                                    <?= e($adm) ?><?= $adm === strtoupper((string)$currentAdminUser) ? ' (vous)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="reset_new_password" class="form-label fw-bold small">Nouveau mot de passe <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" name="new_password" id="reset_new_password" class="form-control" minlength="6" placeholder="Min. 6 caractères" required autocomplete="new-password">
                            <button type="button" class="btn btn-outline-secondary" onclick="zirioamToggleModalPwd('reset_new_password', this)">👁️</button>
                        </div>
                    </div>

                    <div class="mb-2">
                        <label for="reset_confirm_password" class="form-label fw-bold small">Confirmer le nouveau mot de passe <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" name="confirm_password" id="reset_confirm_password" class="form-control" minlength="6" required autocomplete="new-password">
                            <button type="button" class="btn btn-outline-secondary" onclick="zirioamToggleModalPwd('reset_confirm_password', this)">👁️</button>
                        </div>
                    </div>
                </div>

                <div class="modal-footer bg-light py-2 px-4">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-warning fw-bold text-dark">Réinitialiser le mot de passe</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function zirioamToggleModalPwd(inputId, btn) {
    const el = document.getElementById(inputId);
    if (!el) return;
    if (el.type === 'password') {
        el.type = 'text';
        btn.innerText = '🔒';
    } else {
        el.type = 'password';
        btn.innerText = '👁️';
    }
}

function zirioamValidatePasswordModal(prefix) {
    const newEl = document.getElementById(prefix + '_new_password');
    const confirmEl = document.getElementById(prefix + '_confirm_password');
    if (!newEl || !confirmEl) return true;

    if (newEl.value.length < 6) {
        alert("Le mot de passe doit contenir au moins 6 caractères.");
        newEl.focus();
        return false;
    }

    if (newEl.value !== confirmEl.value) {
        alert("Le mot de passe et sa confirmation ne correspondent pas.");
        confirmEl.focus();
        return false;
    }

    if (prefix === 'own') {
        const curEl = document.getElementById('own_current_password');
        if (curEl && curEl.value === newEl.value) {
            alert("Le nouveau mot de passe doit être différent du mot de passe actuel.");
            newEl.focus();
            return false;
        }
    }

    if (prefix === 'reset') {
        const target = document.getElementById('reset_target_username');
        if (!target || !target.value) {
            alert("Veuillez sélectionner un administrateur.");
            return false;
        }
        return confirm("Confirmer la réinitialisation du mot de passe pour l'administrateur " + target.value + " ?");
    }

    return true;
}
</script>
<?php endif; ?>
