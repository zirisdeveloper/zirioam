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
                <!-- Zone d'affichage des alertes d'authentification préalable -->
                <div id="pre-auth-alert-container"></div>

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
                    <button type="submit" id="btn-continue-preauth" class="btn btn-primary btn-lg w-100 fw-bold shadow-sm">
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

function showAlert(message, type = 'danger') {
    const alertContainer = document.getElementById('pre-auth-alert-container');
    if (!alertContainer) return;
    const icon = type === 'danger' ? '⛔' : (type === 'warning' ? '⚠️' : 'ℹ️');
    alertContainer.innerHTML = `
        <div class="alert alert-${type} alert-dismissible fade show d-flex align-items-center mb-3 shadow-sm" role="alert">
            <span class="fs-4 me-2">${icon}</span>
            <div class="flex-grow-1 fw-semibold small">${escapeHtml(message)}</div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fermer"></button>
        </div>
    `;
    alertContainer.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.innerText = text;
    return div.innerHTML;
}

async function handleOpenModal() {
    const selServer = document.getElementById('sel_server_id');
    const inputUser = document.getElementById('input_username');
    const inputPwd = document.getElementById('input_current_password');
    const btnSubmit = document.getElementById('btn-continue-preauth');
    const alertContainer = document.getElementById('pre-auth-alert-container');

    if (alertContainer) {
        alertContainer.innerHTML = '';
    }

    if (!selServer.value) {
        showAlert("Veuillez sélectionner une base de données dans la liste.", "warning");
        selServer.focus();
        return;
    }

    let userVal = inputUser.value.trim().toUpperCase();
    if (!userVal) {
        showAlert("Veuillez saisir votre nom d'utilisateur développeur.", "warning");
        inputUser.focus();
        return;
    }

    if (!userVal.startsWith('C##')) {
        userVal = 'C##' + userVal;
    }

    if (!inputPwd.value) {
        showAlert("Veuillez saisir votre mot de passe actuel.", "warning");
        inputPwd.focus();
        return;
    }

    // Affichage d'un spinner sur le bouton pendant la vérification Oracle
    const origBtnHtml = btnSubmit.innerHTML;
    btnSubmit.disabled = true;
    btnSubmit.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Vérification de vos identifiants...';

    try {
        const formData = new FormData();
        formData.append('server_id', selServer.value);
        formData.append('username', userVal);
        formData.append('current_password', inputPwd.value);
        formData.append('_zirioam_csrf_token', '<?= zirioam_csrf_token() ?>');

        const response = await fetch('<?= zirioam_url('/UpdPwd/verify') ?>', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-Csrf-Token': '<?= zirioam_csrf_token() ?>'
            }
        });

        const data = await response.json();

        if (response.ok && data.valid) {
            // Extraction des données confirmées par le serveur
            const selectedOption = selServer.options[selServer.selectedIndex];
            const srvName = data.server_name || selectedOption.getAttribute('data-name') || selServer.value;
            const srvEndpoint = data.server_endpoint || (selectedOption.getAttribute('data-ip') + ':' + selectedOption.getAttribute('data-port'));
            const srvService = data.server_service || selectedOption.getAttribute('data-service') || '';
            const srvEnv = data.server_env || selectedOption.getAttribute('data-env') || '';

            // Mise à jour de l'affichage dans le modal
            document.getElementById('modal_display_server_name').innerText = srvName + (srvEnv ? ' (' + srvEnv + ')' : '');
            document.getElementById('modal_display_server_endpoint').innerText = srvEndpoint;
            document.getElementById('modal_display_server_service').innerText = srvService;
            document.getElementById('modal_display_user').innerText = data.oracle_user || userVal;

            // Remplissage des champs cachés
            document.getElementById('modal_hidden_server_id').value = selServer.value;
            document.getElementById('modal_hidden_username').value = data.oracle_user || userVal;

            // Pré-remplissage de l'ancien mot de passe
            document.getElementById('modal_old_password').value = inputPwd.value;

            // Réinitialisation des nouveaux mots de passe
            document.getElementById('modal_new_password').value = '';
            document.getElementById('modal_confirm_password').value = '';

            // Affichage du modal Bootstrap (uniquement si les identifiants sont validés !)
            const modalEl = document.getElementById('modalUpdatePassword');
            const modalInstance = new bootstrap.Modal(modalEl);
            modalInstance.show();
            setTimeout(() => {
                document.getElementById('modal_new_password').focus();
            }, 400);
        } else {
            // Identifiant ou mot de passe incorrect : ne pas ouvrir le modal et avertir l'utilisateur
            const errorMsg = data.message || "Mot de passe actuel incorrect ou identifiant introuvable. Veuillez réessayer.";
            showAlert(errorMsg, "danger");
            inputPwd.focus();
            inputPwd.select();
        }
    } catch (err) {
        showAlert("Erreur lors de la communication avec le serveur pour la vérification. Veuillez vérifier votre connexion.", "danger");
    } finally {
        btnSubmit.disabled = false;
        btnSubmit.innerHTML = origBtnHtml;
    }
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

