<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title ?? 'ZIRI OAM — Espace Développeur (Changement de mot de passe)') ?></title>
    <link rel="stylesheet" href="<?= zirioam_asset('css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= zirioam_asset('css/zirioam.css') ?>">
    <style>
        .self-service-header {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            border-bottom: 2px solid #3b82f6;
        }
        .dev-badge {
            font-size: 0.8rem;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }
    </style>
</head>
<body class="bg-light d-flex flex-column min-vh-100">
    <!-- Entête épurée : Uniquement l'identité ZIRIOAM, AUCUN MENU DE NAVIGATION -->
    <header class="self-service-header py-3 shadow-sm">
        <div class="container d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-2">
                <span class="fs-4 fw-bold text-white tracking-wide">ZIRI OAM</span>
                <span class="badge bg-primary dev-badge">Espace Développeur</span>
                <span class="badge bg-secondary dev-badge">Oracle 19c</span>
            </div>
            <div class="text-end text-light small d-none d-md-block">
                <span class="text-white-50">Portail Autonome de Changement de Mot de Passe</span>
            </div>
        </div>
    </header>

    <!-- Alertes et Notifications Flash -->
    <div class="container mt-4">
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
    <main class="container my-auto py-4">
        <?= $content ?>
    </main>

    <!-- Pied de page épuré -->
    <footer class="zirioam-footer mt-auto py-3 bg-white border-top">
        <div class="container text-center text-muted small">
            <strong>ZIRI OAM</strong> &bull; Oracle Database 19c Multitenant Access Manager &bull; Mise à jour globale du mot de passe (<code>CONTAINER = ALL</code>)
        </div>
    </footer>

    <script src="<?= zirioam_asset('js/bootstrap.bundle.min.js') ?>"></script>
    <script src="<?= zirioam_asset('js/zirioam.js') ?>"></script>
</body>
</html>

