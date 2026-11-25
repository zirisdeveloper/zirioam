<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="card border-0 shadow-lg rounded-3 overflow-hidden">
                <div class="card-header bg-white border-0 pt-4 pb-2 text-center">
                    <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary bg-opacity-10 text-primary mb-3" style="width: 68px; height: 68px;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="34" height="34" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
                            <path d="M8 1a2 2 0 0 1 2 2v4H6V3a2 2 0 0 1 2-2m3 6V3a3 3 0 0 0-6 0v4a2 2 0 0 0-2 2v5a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2"/>
                        </svg>
                    </div>
                    <h4 class="fw-bold text-dark mb-1">Connexion Administrateur</h4>
                    <p class="text-muted small mb-0">
                        Authentification requise pour accéder à la plateforme ZIRI OAM.
                    </p>
                </div>

                <div class="card-body p-4">
                    <form action="<?= zirioam_url('/login') ?>" method="POST" autocomplete="off">
                        <?= zirioam_csrf_field() ?>

                        <div class="mb-3">
                            <label for="admin_login_username" class="form-label fw-bold small text-dark">
                                Nom d'utilisateur <span class="text-danger">*</span>
                            </label>
                            <input type="text"
                                   name="username"
                                   id="admin_login_username"
                                   class="form-control form-control-lg font-monospace text-uppercase"
                                   placeholder="ex: SOND0509"
                                   required
                                   autofocus
                                   autocomplete="username">
                        </div>

                        <div class="mb-4">
                            <label for="admin_login_password" class="form-label fw-bold small text-dark">
                                Mot de passe <span class="text-danger">*</span>
                            </label>
                            <div class="input-group input-group-lg">
                                <input type="password"
                                       name="password"
                                       id="admin_login_password"
                                       class="form-control"
                                       placeholder="Votre mot de passe"
                                       required
                                       autocomplete="current-password">
                                <button class="btn btn-outline-secondary"
                                        type="button"
                                        onclick="zirioamTogglePassword('admin_login_password', this)"
                                        title="Afficher / Masquer">
                                    👁️
                                </button>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary btn-lg w-100 fw-bold shadow-sm">
                            Se connecter &rarr;
                        </button>
                    </form>
                </div>

                <div class="card-footer bg-light border-top py-3 px-4 text-center">
                    <div class="small text-muted mb-2">
                        Vous êtes développeur et souhaitez changer votre mot de passe Oracle ?
                    </div>
                    <a href="<?= zirioam_url('/UpdPwd') ?>" class="btn btn-outline-primary btn-sm fw-semibold">
                        🔑 Accès Self-Service Développeur (/UpdPwd) &rarr;
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function zirioamTogglePassword(inputId, btn) {
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
</script>

