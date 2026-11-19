<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-12 col-md-8 col-lg-6">
            <div class="card border-0 shadow-sm rounded-3">
                <div class="card-header bg-dark text-white p-4">
                    <h4 class="mb-1 fw-bold">Connexion Oracle DBA</h4>
                    <p class="mb-0 text-white-50 small">
                        Serveur cible : <strong><?= e($server['server_name']) ?></strong> (<?= e($server['ip_address']) ?>:<?= (int)$server['port'] ?>)
                    </p>
                </div>
                <div class="card-body p-4">
                    <form action="<?= zirioam_url('/servers/' . rawurlencode($server['server_id']) . '/connect') ?>" method="POST">
                        <?= zirioam_csrf_field() ?>

                        <!-- Choix du Service Racine CDB -->
                        <div class="mb-3">
                            <label for="service_name" class="form-label fw-semibold">Service Oracle racine (CDB)</label>
                            <?php if (!empty($server['cdb_services'])): ?>
                                <select name="service_name" id="service_name" class="form-select font-monospace" required>
                                    <?php foreach ($server['cdb_services'] as $svc): ?>
                                        <option value="<?= e($svc['root_service']) ?>" <?= $svc['root_service'] === $defaultService ? 'selected' : '' ?>>
                                            <?= e($svc['cdb_name']) ?> — <?= e($svc['root_service']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <input type="text" name="service_name" id="service_name" class="form-control font-monospace" 
                                       value="<?= e($defaultService) ?>" placeholder="ex: cdbprod.company.local" required>
                            <?php endif; ?>
                            <div class="form-text">Service d'écoute configuré dans le listener Oracle (ex: <code>v$services</code>).</div>
                        </div>

                        <!-- Utilisateur Oracle -->
                        <div class="mb-3">
                            <label for="username" class="form-label fw-semibold">Utilisateur Oracle</label>
                            <input type="text" name="username" id="username" class="form-control font-monospace text-uppercase" 
                                   placeholder="SYS ou SYSTEM" required autofocus>
                            <div class="form-text">Exemple : <code>SYS</code> (pour installation initiale) ou <code>SYSTEM</code>.</div>
                        </div>

                        <!-- Mot de passe Oracle -->
                        <div class="mb-3">
                            <label for="password" class="form-label fw-semibold">Mot de passe</label>
                            <input type="password" name="password" id="password" class="form-control font-monospace" required autocomplete="off">
                            <div class="form-text">Ce mot de passe n'est jamais enregistré en clair ni consigné dans les logs.</div>
                        </div>

                        <!-- Mode de connexion -->
                        <div class="mb-4 p-3 bg-light rounded border">
                            <label class="form-label fw-bold d-block mb-2">Mode de connexion Oracle :</label>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="radio" name="mode" id="modeNormal" value="NORMAL" checked>
                                <label class="form-check-label" for="modeNormal">
                                    <strong>Connexion Normale</strong> (recommandé pour SYSTEM ou compte technique)
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="mode" id="modeSysdba" value="SYSDBA">
                                <label class="form-check-label" for="modeSysdba">
                                    <strong>Mode SYSDBA</strong> (obligatoire pour SYS lors du déploiement initial)
                                </label>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="d-flex justify-content-between align-items-center">
                            <a href="<?= zirioam_url('/servers') ?>" class="btn btn-outline-secondary">
                                &larr; Annuler
                            </a>
                            <button type="submit" class="btn btn-primary px-4 fw-semibold">
                                SE CONNECTER AU SERVEUR &rarr;
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="mt-4 p-3 border rounded bg-white text-muted small">
                <h6 class="fw-bold text-dark mb-1">Règles de sécurité Oracle 19c :</h6>
                <ul class="mb-0 ps-3">
                    <li>L'utilisateur <code>SYS</code> doit obligatoirement être utilisé avec le privilège <code>SYSDBA</code>.</li>
                    <li>L'utilisateur <code>SYSTEM</code> s'utilise en mode Connexion Normale.</li>
                    <li>La session est protégée en mémoire par chiffrement AES-256-GCM.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

