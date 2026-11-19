<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($appConfig['app_title'] ?? 'ZIRI OAM') ?></title>
    <link rel="stylesheet" href="<?= zirioam_asset('css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= zirioam_asset('css/zirioam.css') ?>">
</head>
<body class="bg-light">
    <!-- Navbar simplifiée -->
    <nav class="navbar navbar-dark zirioam-navbar py-2">
        <div class="container d-flex justify-content-between align-items-center">
            <a class="navbar-brand zirioam-brand d-flex align-items-center" href="<?= zirioam_url('/servers') ?>">
                <span>ZIRI OAM</span>
                <span class="badge-tag">Oracle 19c</span>
            </a>
            <span class="text-light small opacity-75">
                Oracle Developer Access Manager
            </span>
        </div>
    </nav>

    <!-- Conteneur Flash Alerts -->
    <div class="container mt-3">
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
    <main class="main-wrapper">
        <?= $content ?>
    </main>

    <!-- Footer -->
    <footer class="zirioam-footer">
        <div class="container text-center text-muted small">
            <div><strong>ZIRI OAM</strong> — Architecture Multitenant Oracle 19c &bull; Version <?= e($appConfig['version'] ?? '1.0.0') ?></div>
            <div class="mt-1">Sécurité stricte &bull; Isolation CDB/PDB &bull; Non-persistance des secrets</div>
        </div>
    </footer>

    <script src="<?= zirioam_asset('js/bootstrap.bundle.min.js') ?>"></script>
    <script src="<?= zirioam_asset('js/zirioam.js') ?>"></script>
</body>
</html>

