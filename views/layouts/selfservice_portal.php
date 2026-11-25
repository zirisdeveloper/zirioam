<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title ?? 'ZIRI OAM — Self-Service Développeur') ?></title>
    <link rel="stylesheet" href="<?= zirioam_asset('css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= zirioam_asset('css/zirioam.css') ?>">
</head>
<body class="bg-light">
<?php
    $ssSess = $selfServiceSession ?? null;
    $isLoggedIn = is_array($ssSess) && !empty($ssSess['username']) && !empty($ssSess['server']);
    $ssServer = $isLoggedIn ? $ssSess['server'] : null;
    $ssUser = $isLoggedIn ? (string)$ssSess['username'] : '';
    $ssCdb = $selfServiceCdb ?? ($ssSess['cdb'] ?? '');
    $ssPdb = $selfServicePdb ?? '';
    $currentNav = $activeNav ?? '';
?>
    <!-- Navbar Portail Self-Service Développeur -->
    <nav class="navbar navbar-expand-lg navbar-dark zirioam-navbar py-2 sticky-top">
        <div class="container-fluid px-4">
            <a class="navbar-brand zirioam-brand d-flex align-items-center me-4" href="<?= zirioam_url($isLoggedIn ? '/selfservice/pdbs' : '/selfservice') ?>">
                <span>ZIRI OAM</span>
                <span class="badge-tag">Self-Service</span>
            </a>

            <?php if ($isLoggedIn): ?>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#selfServiceNavbar" aria-controls="selfServiceNavbar" aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>

                <div class="collapse navbar-collapse" id="selfServiceNavbar">
                    <!-- Menu Développeur -->
                    <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                        <li class="nav-item">
                            <a class="nav-link <?= $currentNav === 'pdbs' ? 'active fw-bold text-white' : '' ?>" href="<?= zirioam_url('/selfservice/pdbs') ?>">
                                Bases PDB
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= $currentNav === 'privs' ? 'active fw-bold text-white' : '' ?>" href="<?= zirioam_url('/selfservice/privs') ?>">
                                Privs
                            </a>
                        </li>
                    </ul>

                    <!-- Bloc Infos Session + Déconnexion Oracle + Menu Compte Développeur -->
                    <div class="d-flex align-items-center gap-3">
                        <?php if (!empty($ssServer)): ?>
                            <div class="text-end d-none d-md-block">
                                <div class="text-light small fw-bold">
                                    <?= e($ssServer['server_name']) ?>
                                    <?php if (!empty($ssCdb)): ?>
                                        <span class="text-info">(CDB: <?= e($ssCdb) ?>)</span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-muted" style="font-size: 0.75rem;">
                                    <span class="text-light opacity-75"><?= e($ssUser) ?></span>
                                    <span class="badge bg-secondary">DEV</span>
                                    <?php if (!empty($ssPdb)): ?>
                                        &bull; <span class="text-light opacity-75">PDB:</span> <span class="text-warning fw-bold"><?= e($ssPdb) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <form action="<?= zirioam_url('/selfservice/disconnect') ?>" method="POST" class="d-inline m-0">
                            <?= zirioam_csrf_field() ?>
                            <button type="submit" class="btn btn-outline-danger btn-sm" title="Fermer la session Oracle développeur">
                                Déconnexion Oracle
                            </button>
                        </form>

                        <!-- Menu Compte Développeur -->
                        <div class="dropdown">
                            <button class="btn btn-outline-light btn-sm dropdown-toggle d-flex align-items-center gap-2 rounded-pill px-3 py-1"
                                    type="button"
                                    id="ssAccountDropdown"
                                    data-bs-toggle="dropdown"
                                    aria-expanded="false">
                                <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-light text-dark" style="width: 24px; height: 24px;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
                                        <path d="M11 6a3 3 0 1 1-6 0 3 3 0 0 1 6 0"/>
                                        <path fill-rule="evenodd" d="M0 8a8 8 0 1 1 16 0A8 8 0 0 1 0 8m8-7a7 7 0 0 0-5.468 11.37C3.242 11.226 4.805 10 8 10s4.757 1.225 5.468 2.37A7 7 0 0 0 8 1"/>
                                    </svg>
                                </span>
                                <span class="fw-semibold small font-monospace"><?= e($ssUser) ?></span>
                            </button>

                            <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2" aria-labelledby="ssAccountDropdown" style="min-width: 270px;">
                                <li class="px-3 py-2 border-bottom bg-light">
                                    <div class="small text-muted">Connecté à :</div>
                                    <div class="fw-bold text-dark font-monospace">
                                        <?= e($ssServer['server_name'] ?? '') ?>
                                        <?php if (!empty($ssCdb)): ?>
                                            <span class="text-primary">(CDB: <?= e($ssCdb) ?>)</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="small text-muted mt-1">
                                        Compte : <strong class="font-monospace text-dark"><?= e($ssUser) ?></strong>
                                    </div>
                                    <?php if (!empty($ssPdb)): ?>
                                        <div class="small text-muted">
                                            PDB active : <strong class="font-monospace text-warning bg-dark px-1 rounded"><?= e($ssPdb) ?></strong>
                                        </div>
                                    <?php endif; ?>
                                </li>
                                <li>
                                    <a class="dropdown-item py-2 d-flex align-items-center gap-2 fw-semibold" href="<?= zirioam_url('/UpdPwd') ?>">
                                        <span>🔑</span>
                                        <span>Modifier password</span>
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <span class="text-light small opacity-75">
                    Portail Développeur Oracle 19c
                </span>
            <?php endif; ?>
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
    </div>

    <!-- Contenu Principal -->
    <main class="main-wrapper container-fluid px-4">
        <?= $content ?>
    </main>

    <!-- Footer -->
    <footer class="zirioam-footer">
        <div class="container-fluid px-4 d-flex justify-content-between align-items-center small text-muted">
            <div>
                <strong>ZIRI OAM</strong> — Portail Self-Service Développeur &bull; Oracle 19c
            </div>
            <div>
                Accès sécurisé sans privilèges d'administration
            </div>
        </div>
    </footer>

    <script src="<?= zirioam_asset('js/bootstrap.bundle.min.js') ?>"></script>
    <script src="<?= zirioam_asset('js/zirioam.js') ?>"></script>
</body>
</html>

