<?php if (!empty($currentAdminUser)): ?>
<div class="dropdown">
    <button class="btn btn-outline-light btn-sm dropdown-toggle d-flex align-items-center gap-2 rounded-pill px-3 py-1"
            type="button"
            id="zirioamAdminProfileDropdown"
            data-bs-toggle="dropdown"
            aria-expanded="false"
            title="Menu Profil Administrateur">
        <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-light text-dark" style="width: 24px; height: 24px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
                <path d="M11 6a3 3 0 1 1-6 0 3 3 0 0 1 6 0"/>
                <path fill-rule="evenodd" d="M0 8a8 8 0 1 1 16 0A8 8 0 0 1 0 8m8-7a7 7 0 0 0-5.468 11.37C3.242 11.226 4.805 10 8 10s4.757 1.225 5.468 2.37A7 7 0 0 0 8 1"/>
            </svg>
        </span>
        <span class="fw-semibold small font-monospace"><?= e($currentAdminUser) ?></span>
    </button>

    <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2" aria-labelledby="zirioamAdminProfileDropdown" style="min-width: 270px;">
        <li class="px-3 py-2 border-bottom bg-light">
            <div class="small text-muted">Connecté en tant qu'administrateur</div>
            <div class="fw-bold text-dark font-monospace"><?= e($currentAdminUser) ?></div>
        </li>
        <li>
            <button type="button" class="dropdown-item py-2 d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#modalAdminChangePassword">
                <span>🔑</span>
                <span>Modifier mon mot de passe</span>
            </button>
        </li>
        <li>
            <button type="button" class="dropdown-item py-2 d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#modalAdminCreate">
                <span>➕</span>
                <span>Créer un administrateur</span>
            </button>
        </li>
        <li>
            <button type="button" class="dropdown-item py-2 d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#modalAdminResetPassword">
                <span>🔄</span>
                <span>Initialiser mot de passe admin</span>
            </button>
        </li>
        <li><hr class="dropdown-divider"></li>
        <li>
            <form action="<?= zirioam_url('/logout') ?>" method="POST" class="m-0">
                <?= zirioam_csrf_field() ?>
                <button type="submit" class="dropdown-item py-2 text-danger fw-semibold d-flex align-items-center gap-2">
                    <span>🚪</span>
                    <span>Déconnexion de la plateforme</span>
                </button>
            </form>
        </li>
    </ul>
</div>
<?php endif; ?>
