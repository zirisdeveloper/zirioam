<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($appConfig['app_title'] ?? 'ZIRI OAM') ?></title>
    <link rel="stylesheet" href="<?= zirioam_asset('css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= zirioam_asset('css/zirioam.css') ?>">
</head>
<body>
    <!-- Navbar Principale ZIRIOAM -->
    <nav class="navbar navbar-expand-lg navbar-dark zirioam-navbar py-2 sticky-top">
        <div class="container-fluid px-4">
            <a class="navbar-brand zirioam-brand d-flex align-items-center me-4" href="<?= zirioam_url('/dashboard') ?>">
                <span>ZIRI OAM</span>
                <span class="badge-tag">Oracle 19c</span>
            </a>

            <button class="navbar-toggler" type="button" data-bs-dismiss="collapse" data-bs-target="#zirioamNavbar" aria-controls="zirioamNavbar" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="zirioamNavbar">
                <!-- Liens de navigation fonctionnels -->
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item">
                        <a class="nav-link" href="<?= zirioam_url('/dashboard') ?>">Tableau de bord</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= zirioam_url('/databases') ?>">Bases CDB / PDB</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= zirioam_url('/developers') ?>">Développeurs</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= zirioam_url('/roles') ?>">Gestion des Rôles</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= zirioam_url('/access') ?>">Privilèges DDL / DML</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= zirioam_url('/scripts') ?>">Moteur DDL / Scripts</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= zirioam_url('/audit') ?>">Audit & Traçabilité</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link text-warning" href="<?= zirioam_url('/installer/status?view=1') ?>">État Composants</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link text-info" href="<?= zirioam_url('/installer/maintenance') ?>">🛠️ Maintenance & Schémas</a>
                    </li>
                </ul>

                <!-- Informations de Session Oracle Active -->
                <div class="d-flex align-items-center gap-3">
                    <?php if (!empty($currentServer)): ?>
                        <div class="text-end d-none d-md-block">
                            <div class="text-light small fw-bold">
                                <?= e($currentServer['server_name']) ?>
                                <?php if (!empty($currentCdb)): ?>
                                    <span class="text-info">(CDB: <?= e($currentCdb) ?>)</span>
                                <?php endif; ?>
                            </div>
                            <div class="text-muted" style="font-size: 0.75rem;">
                                <?= e($currentSession->get('current_user')) ?>
                                <span class="badge bg-secondary"><?= e($currentSession->get('current_mode')) ?></span>
                                <?php if (!empty($currentPdb)): ?>
                                    &bull; PDB: <span class="text-warning fw-bold"><?= e($currentPdb) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <form action="<?= zirioam_url('/disconnect') ?>" method="POST" class="d-inline">
                        <?= zirioam_csrf_field() ?>
                        <button type="submit" class="btn btn-outline-danger btn-sm" title="Fermer la session Oracle">
                            Déconnexion
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </nav>

    <!-- Alertes et Notifications Flash -->
    <div class="container-fluid px-4 mt-3">
        <?php foreach (['danger', 'warning', 'success', 'info'] as $type): ?>
            <?php foreach (zirioam_flash($type) as $msg): ?>
                <div class="alert alert-<?= $type ?> alert-dismissible fade show shadow-sm" role="alert">
                    <?= e($msg) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endforeach; ?>
        <?php endforeach; ?>

        <!-- Révélation unique de mot de passe temporaire -->
        <?php $tempPassword = $currentSession->get('_temp_revealed_password'); ?>
        <?php if ($tempPassword): ?>
            <?php $currentSession->remove('_temp_revealed_password'); ?>
            <div class="alert alert-warning border-warning shadow p-3 mb-4">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="alert-heading text-dark fw-bold mb-1">
                            ⚠️ Mot de passe temporaire généré (Révélation Unique)
                        </h5>
                        <p class="mb-0 text-dark small">
                            Ce mot de passe est marqué <strong>PASSWORD EXPIRE</strong>. Le développeur devra obligatoirement le changer lors de sa prochaine connexion Oracle.
                            Il ne sera plus jamais réaffiché et n'est pas conservé en base.
                        </p>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <input type="text" id="temp-password-field" class="form-control font-monospace fw-bold text-center" 
                               value="<?= e($tempPassword) ?>" readonly style="width: 220px; font-size: 1.1rem; background-color: #fff;">
                        <button type="button" id="btn-copy-password" class="btn btn-dark">
                            Copier
                        </button>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Contenu Principal -->
    <main class="main-wrapper container-fluid px-4">
        <?= $content ?>
    </main>

    <!-- Footer -->
    <footer class="zirioam-footer">
        <div class="container-fluid px-4 d-flex justify-content-between align-items-center">
            <div>
                <strong>ZIRI OAM</strong> — Oracle Developer Access Manager &bull; v<?= e($appConfig['version'] ?? '1.0.0') ?>
            </div>
            <div class="small">
                Architecture Multitenant Oracle 19c &bull; Isolation stricte des privilèges &bull; Moteur DDL sécurisé
            </div>
        </div>
    </footer>

    <script src="<?= zirioam_asset('js/bootstrap.bundle.min.js') ?>"></script>
    <script src="<?= zirioam_asset('js/zirioam.js') ?>"></script>
</body>
</html>

