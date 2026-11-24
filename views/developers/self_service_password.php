<div class="row justify-content-center">
    <div class="col-lg-6 col-md-8">
        <div class="card border-0 shadow-lg rounded-3">
            <div class="card-header bg-white border-0 pt-4 pb-0 text-center">
                <div class="avatar-icon mx-auto mb-2 d-inline-flex p-3 rounded-circle bg-primary bg-opacity-10 text-primary">
                    <span class="fs-2">🔐</span>
                </div>
                <h4 class="card-title fw-bold text-dark mb-1">Mise à jour de Mot de Passe Développeur</h4>
                <p class="text-muted small">
                    Sélectionnez la base cible et saisissez vos identifiants pour renouveler votre mot de passe Oracle.
                </p>
            </div>
            <div class="card-body p-4 pt-3">
                <form id="pre-auth-form" onsubmit="event.preventDefault(); handleOpenModal();">
                    <!-- 1. Choix de la base de données (servers.json) -->
                    <div class="mb-3">
                        <label for="sel_server_id" class="form-label fw-bold small text-dark">
                            Base de données Oracle cible <span class="text-danger">*</span>
                        </label>
                        <select name="server_id" id="sel_server_id" class="form-select form-select-lg" required>
                            <option value="" disabled selected>-- Sélectionnez votre base de données --</option>
                            <?php foreach ($servers as $srv): ?>
                                <?php 
                                    $srvService = $srv['cdb_services'][0]['root_service'] ?? $srv['cdb_services'][0]['cdb_name'] ?? 'CDB';
                                ?>
                                <option value="<?= e($srv['server_id']) ?>"
                                        data-name="<?= e($srv['server_name']) ?>"
                                        data-ip="<?= e($srv['ip_address']) ?>"
                                        data-port="<?= (int)$srv['port'] ?>"
                                        data-service="<?= e($srvService) ?>"
                                        data-env="<?= e($srv['environment'] ?? '') ?>">
                                    <?= e($srv['server_name']) ?> &mdash; <?= e($srv['environment'] ?? '') ?> (<?= e($srv['ip_address']) ?>:<?= (int)$srv['port'] ?> / <?= e($srvService) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text extra-small text-muted">
                            Serveurs configurés dans le référentiel d'entreprise ZIRIOAM.
                        </div>
                    </div>

                    <!-- 2. Nom d'utilisateur Développeur -->
                    <div class="mb-3">
                        <label for="input_username" class="form-label fw-bold small text-dark">
                            Identifiant Développeur <span class="text-danger">*</span>
                        </label>
                        <input type="text" name="username" id="input_username" 
                               class="form-control form-control-lg font-monospace text-uppercase" 
                               placeholder="ex: C##SON0196 ou SON0196" required autocomplete="username">
                        <div class="form-text extra-small text-muted">
                            Compte commun Oracle (le préfixe <code>C##</code> sera ajouté automatiquement s'il est omis).
                        </div>
                    </div>

                    <!-- 3. Mot de passe actuel -->
                    <div class="mb-4">
                        <label for="input_current_password" class="form-label fw-bold small text-dark">
                            Mot de passe actuel <span class="text-danger">*</span>
                        </label>
                        <div class="input-group input-group-lg">
                            <input type="password" name="current_password" id="input_current_password" 
                                   class="form-control" placeholder="Votre mot de passe actuel" required autocomplete="current-password">
                            <button class="btn btn-outline-secondary" type="button" onclick="toggleInputVisibility('input_current_password', this)">
                                👁️
                            </button>
                        </div>
                        <div class="form-text extra-small text-muted">
                            Votre mot de passe actuel est requis pour vérifier votre identité.
                        </div>
                    </div>

                    <!-- Bouton Continuer -->
                    <button type="submit" class="btn btn-primary btn-lg w-100 fw-bold shadow-sm">
                        Continuer vers le changement de mot de passe &rarr;
                    </button>
                </form>

                <div class="alert alert-secondary small mb-0 mt-4 py-2 border">
                    <div class="d-flex align-items-center">
                        <span class="me-2 fs-5">ℹ️</span>
                        <div>
                            <strong>Application globale :</strong> Le nouveau mot de passe s'appliquera instantanément sur <strong>tous les conteneurs</strong> (CDB et l'ensemble de vos PDBs autorisées).
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Changement de Mot de Passe -->
<div class="modal fade" id="modalUpdatePassword" tabindex="-1" aria-labelledby="modalUpdatePasswordLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form action="<?= zirioam_url('/UpdPwd') ?>" method="POST" onsubmit="return validateAndConfirmPasswordChange();">
                <?= zirioam_csrf_field() ?>
                
                <!-- Champs cachés transmis au serveur -->
                <input type="hidden" name="server_id" id="modal_hidden_server_id">
                <input type="hidden" name="username" id="modal_hidden_username">

                <div class="modal-header bg-primary text-white py-3">
                    <h5 class="modal-title fw-bold" id="modalUpdatePasswordLabel">
                        🔑 Modification du Mot de Passe Oracle
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fermer"></button>
                </div>

                <div class="modal-body p-4">
                    <!-- Présentation de la base choisie et du développeur -->
                    <div class="card bg-light border-0 mb-3">
                        <div class="card-body p-3">
                            <h6 class="fw-bold text-dark border-bottom pb-2 mb-2">Contexte de la modification :</h6>
                            <div class="row g-2 small">
                                <div class="col-sm-5 text-muted">Base de données :</div>
                                <div class="col-sm-7 fw-bold text-dark" id="modal_display_server_name">&mdash;</div>

                                <div class="col-sm-5 text-muted">Hôte / Port :</div>
                                <div class="col-sm-7 font-monospace" id="modal_display_server_endpoint">&mdash;</div>

                                <div class="col-sm-5 text-muted">Service CDB :</div>
                                <div class="col-sm-7 font-monospace" id="modal_display_server_service">&mdash;</div>

                                <div class="col-sm-5 text-muted">Compte Développeur :</div>
                                <div class="col-sm-7 font-monospace fw-bold text-primary" id="modal_display_user">&mdash;</div>
                            </div>
                        </div>
                    </div>

                    <!-- Ancien mot de passe -->
                    <div class="mb-3">
                        <label for="modal_old_password" class="form-label small fw-bold text-dark">
                            Ancien mot de passe <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <input type="password" name="old_password" id="modal_old_password" 
                                   class="form-control" required autocomplete="current-password">
                            <button class="btn btn-outline-secondary" type="button" onclick="toggleInputVisibility('modal_old_password', this)">
                                👁️
                            </button>
                        </div>
                    </div>

                    <!-- Nouveau mot de passe -->
                    <div class="mb-3">
                        <label for="modal_new_password" class="form-label small fw-bold text-dark">
                            Nouveau mot de passe <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <input type="password" name="new_password" id="modal_new_password" 
                                   class="form-control" placeholder="Min. 8 caractères" required autocomplete="new-password">
                            <button class="btn btn-outline-secondary" type="button" onclick="toggleInputVisibility('modal_new_password', this)">
                                👁️
                            </button>
                        </div>
                        <div class="form-text extra-small text-muted">
                            Critères Oracle : Minimum 8 car., majuscule, minuscule, chiffre, différent de l'ancien.
                        </div>
                    </div>

                    <!-- Confirmer nouveau mot de passe -->
                    <div class="mb-2">
                        <label for="modal_confirm_password" class="form-label small fw-bold text-dark">
                            Confirmer le nouveau mot de passe <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <input type="password" name="confirm_password" id="modal_confirm_password" 
                                   class="form-control" placeholder="Retapez le nouveau mot de passe" required autocomplete="new-password">
                            <button class="btn btn-outline-secondary" type="button" onclick="toggleInputVisibility('modal_confirm_password', this)">
                                👁️
                            </button>
                        </div>
                    </div>
                </div>

                <div class="modal-footer bg-light py-2 px-4 d-flex justify-content-between">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        Annuler
                    </button>
                    <button type="submit" class="btn btn-success fw-bold px-4" id="btn-submit-pwd">
                        Valider la modification
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleInputVisibility(inputId, btn) {
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

function handleOpenModal() {
    const selServer = document.getElementById('sel_server_id');
    const inputUser = document.getElementById('input_username');
    const inputPwd = document.getElementById('input_current_password');

    if (!selServer.value) {
        alert("Veuillez sélectionner une base de données dans la liste.");
        selServer.focus();
        return;
    }

    let userVal = inputUser.value.trim().toUpperCase();
    if (!userVal) {
        alert("Veuillez saisir votre nom d'utilisateur développeur.");
        inputUser.focus();
        return;
    }

    if (!userVal.startsWith('C##')) {
        userVal = 'C##' + userVal;
    }

    if (!inputPwd.value) {
        alert("Veuillez saisir votre mot de passe actuel.");
        inputPwd.focus();
        return;
    }

    // Extraction des attributs du serveur sélectionné
    const selectedOption = selServer.options[selServer.selectedIndex];
    const srvName = selectedOption.getAttribute('data-name') || selServer.value;
    const srvIp = selectedOption.getAttribute('data-ip') || '';
    const srvPort = selectedOption.getAttribute('data-port') || '1521';
    const srvService = selectedOption.getAttribute('data-service') || '';
    const srvEnv = selectedOption.getAttribute('data-env') || '';

    // Mise à jour de l'affichage dans le modal
    document.getElementById('modal_display_server_name').innerText = srvName + (srvEnv ? ' (' + srvEnv + ')' : '');
    document.getElementById('modal_display_server_endpoint').innerText = srvIp + ':' + srvPort;
    document.getElementById('modal_display_server_service').innerText = srvService;
    document.getElementById('modal_display_user').innerText = userVal;

    // Remplissage des champs cachés
    document.getElementById('modal_hidden_server_id').value = selServer.value;
    document.getElementById('modal_hidden_username').value = userVal;

    // Pré-remplissage de l'ancien mot de passe
    document.getElementById('modal_old_password').value = inputPwd.value;

    // Réinitialisation des nouveaux mots de passe
    document.getElementById('modal_new_password').value = '';
    document.getElementById('modal_confirm_password').value = '';

    // Affichage du modal Bootstrap
    const modalEl = document.getElementById('modalUpdatePassword');
    const modalInstance = new bootstrap.Modal(modalEl);
    modalInstance.show();
    setTimeout(() => {
        document.getElementById('modal_new_password').focus();
    }, 400);
}

function validateAndConfirmPasswordChange() {
    const newPwd = document.getElementById('modal_new_password').value;
    const confirmPwd = document.getElementById('modal_confirm_password').value;
    const oldPwd = document.getElementById('modal_old_password').value;
    const srvName = document.getElementById('modal_display_server_name').innerText;
    const user = document.getElementById('modal_display_user').innerText;

    if (!oldPwd) {
        alert("Erreur : L'ancien mot de passe est obligatoire.");
        document.getElementById('modal_old_password').focus();
        return false;
    }

    if (!newPwd) {
        alert("Erreur : Le nouveau mot de passe est obligatoire.");
        document.getElementById('modal_new_password').focus();
        return false;
    }

    if (newPwd !== confirmPwd) {
        alert("Erreur : Le nouveau mot de passe et sa confirmation ne correspondent pas.");
        document.getElementById('modal_confirm_password').focus();
        return false;
    }

    if (newPwd === oldPwd) {
        alert("Erreur : Le nouveau mot de passe doit être différent de l'ancien mot de passe.");
        document.getElementById('modal_new_password').focus();
        return false;
    }

    if (newPwd.length < 8) {
        alert("Erreur : Le mot de passe doit contenir au moins 8 caractères.");
        document.getElementById('modal_new_password').focus();
        return false;
    }

    const confirmed = confirm(
        "CONFIRMATION DE MODIFICATION DE MOT DE PASSE\n\n" +
        "• Compte Oracle : " + user + "\n" +
        "• Base de données : " + srvName + "\n\n" +
        "Confirmez-vous la mise à jour de votre mot de passe ?\n" +
        "Ce changement sera applicable sur l'ensemble des conteneurs (CDB et PDBs autorisées)."
    );

    if (confirmed) {
        const btn = document.getElementById('btn-submit-pwd');
        btn.disabled = true;
        btn.innerText = 'Modification en cours...';
        return true;
    }

    return false;
}
</script>

