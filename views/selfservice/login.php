<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-5">
            <div class="card border-0 shadow-lg rounded-3 overflow-hidden">
                <div class="card-header bg-white border-0 pt-4 pb-2 text-center">
                    <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary bg-opacity-10 text-primary mb-3" style="width: 68px; height: 68px;">
                        <span class="fs-2">🛠️</span>
                    </div>
                    <h4 class="fw-bold text-dark mb-1">Portail Développeur ZIRI OAM</h4>
                    <p class="text-muted small mb-0">
                        Connectez-vous à un serveur Oracle avec votre compte développeur pour gérer vos PDBs et synchroniser vos privilèges.
                    </p>
                </div>

                <div class="card-body p-4">
                    <?php if (!empty($errorMessage)): ?>
                        <div class="alert alert-danger small mb-3">
                            <?= e($errorMessage) ?>
                        </div>
                    <?php endif; ?>

                    <form action="<?= zirioam_url('/selfservice/connect') ?>" method="POST" autocomplete="off">
                        <?= zirioam_csrf_field() ?>

                        <!-- 1. Choix du serveur depuis servers.json -->
                        <div class="mb-3">
                            <label for="ss_server_id" class="form-label fw-bold small text-dark">
                                Serveur Oracle cible <span class="text-danger">*</span>
                            </label>
                            <select name="server_id" id="ss_server_id" class="form-select form-select-lg" required>
                                <option value="" disabled selected>-- Sélectionnez une base de données --</option>
                                <?php foreach ($servers as $srv): ?>
                                    <?php
                                        $srvService = $srv['cdb_services'][0]['root_service'] ?? $srv['cdb_services'][0]['cdb_name'] ?? 'CDB';
                                    ?>
                                    <option value="<?= e($srv['server_id']) ?>">
                                        <?= e($srv['server_name']) ?> &mdash; <?= e($srv['environment'] ?? '') ?> (<?= e($srv['ip_address']) ?>:<?= (int)$srv['port'] ?> / <?= e($srvService) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- 2. Username développeur -->
                        <div class="mb-3">
                            <label for="ss_username" class="form-label fw-bold small text-dark">
                                Identifiant Développeur <span class="text-danger">*</span>
                            </label>
                            <input type="text"
                                   name="username"
                                   id="ss_username"
                                   class="form-control form-control-lg font-monospace text-uppercase"
                                   placeholder="ex: C##SON0196 ou SON0196"
                                   required
                                   autofocus
                                   autocomplete="username">
                            <div class="form-text small">
                                Le préfixe <code>C##</code> est ajouté automatiquement s'il est omis.
                            </div>
                        </div>

                        <!-- 3. Password -->
                        <div class="mb-4">
                            <label for="ss_password" class="form-label fw-bold small text-dark">
                                Mot de passe Oracle <span class="text-danger">*</span>
                            </label>
                            <div class="input-group input-group-lg">
                                <input type="password"
                                       name="password"
                                       id="ss_password"
                                       class="form-control"
                                       placeholder="Votre mot de passe"
                                       required
                                       autocomplete="current-password">
                                <button class="btn btn-outline-secondary"
                                        type="button"
                                        onclick="ssTogglePwd('ss_password', this)"
                                        title="Afficher / Masquer">
                                    👁️
                                </button>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary btn-lg w-100 fw-bold shadow-sm">
                            Se connecter à Oracle &rarr;
                        </button>
                    </form>
                </div>

                <div class="card-footer bg-light border-top py-3 px-4 text-center">
                    <div class="small text-muted mb-2">
                        Besoin de modifier ou renouveler votre mot de passe Oracle ?
                    </div>
                    <a href="<?= zirioam_url('/UpdPwd') ?>" class="btn btn-outline-primary btn-sm fw-semibold">
                        🔑 Changer mon mot de passe (/UpdPwd) &rarr;
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function ssTogglePwd(id, btn) {
    const el = document.getElementById(id);
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

