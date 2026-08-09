<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Usuarios - Mantenimiento</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
    <nav class="navbar navbar-dark bg-dark">
        <div class="container d-flex gap-2">
            <a class="navbar-brand" href="<?= base_url('dashboard') ?>">Mantenimiento</a>
            <div class="ms-auto d-flex gap-2">
                <a class="btn btn-outline-light btn-sm" href="<?= base_url('administracion/sucursales') ?>">Sucursales</a>
                <form method="post" action="<?= base_url('logout') ?>" class="mb-0">
                    <?= csrf_field() ?>
                    <button class="btn btn-outline-light btn-sm" type="submit">Cerrar sesión</button>
                </form>
            </div>
        </div>
    </nav>

    <main class="container py-4">
        <header class="mb-4">
            <p class="text-primary fw-semibold mb-1"><?= esc($company['razon_social']) ?></p>
            <h1 class="h3 mb-1">Usuarios y acceso</h1>
            <p class="text-muted mb-0">Cada usuario pertenece a esta empresa. Los Administradores acceden automáticamente a todas sus sucursales.</p>
        </header>

        <?php if (session()->getFlashdata('success')): ?>
            <div class="alert alert-success" role="status"><?= esc(session()->getFlashdata('success')) ?></div>
        <?php endif; ?>
        <?php if (session()->getFlashdata('error')): ?>
            <div class="alert alert-danger" role="alert"><?= esc(session()->getFlashdata('error')) ?></div>
        <?php endif; ?>

        <section class="card shadow-sm mb-4">
            <div class="card-header"><h2 class="h5 mb-0">Nuevo usuario</h2></div>
            <div class="card-body">
                <form method="post" action="<?= base_url('administracion/usuarios') ?>" class="row g-3">
                    <?= csrf_field() ?>
                    <div class="col-md-6">
                        <label class="form-label" for="new-user-name">Nombre</label>
                        <input class="form-control" id="new-user-name" name="nombre" maxlength="255" required value="<?= esc(old('nombre')) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="new-user-email">Email</label>
                        <input class="form-control" type="email" id="new-user-email" name="email" maxlength="255" required value="<?= esc(old('email')) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="new-user-password">Contraseña inicial</label>
                        <input class="form-control" type="password" id="new-user-password" name="password" minlength="8" maxlength="255" autocomplete="new-password" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="new-user-password-confirmation">Repetir contraseña</label>
                        <input class="form-control" type="password" id="new-user-password-confirmation" name="password_confirmation" minlength="8" maxlength="255" autocomplete="new-password" required>
                    </div>
                    <fieldset class="col-lg-6">
                        <legend class="h6">Roles</legend>
                        <div class="row g-2">
                            <?php foreach ($roles as $role): ?>
                                <div class="col-sm-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="roles[]" value="<?= esc($role['id']) ?>" id="new-role-<?= esc($role['id']) ?>">
                                        <label class="form-check-label" for="new-role-<?= esc($role['id']) ?>"><?= esc($role['nombre']) ?></label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                    <fieldset class="col-lg-6">
                        <legend class="h6">Sucursales</legend>
                        <div class="row g-2">
                            <?php foreach ($branches as $branch): ?>
                                <?php if ((int) $branch['estado'] === 1): ?>
                                    <div class="col-sm-6">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="sucursales[]" value="<?= esc($branch['id']) ?>" id="new-branch-<?= esc($branch['id']) ?>">
                                            <label class="form-check-label" for="new-branch-<?= esc($branch['id']) ?>"><?= esc($branch['nombre']) ?></label>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-text">Si elegís Administrador, las sucursales se asignan automáticamente.</div>
                    </fieldset>
                    <div class="col-12">
                        <label class="form-label" for="new-user-reason">Motivo del alta</label>
                        <input class="form-control" id="new-user-reason" name="motivo" minlength="5" maxlength="255" required placeholder="Ej.: incorporación aprobada">
                    </div>
                    <div class="col-12">
                        <button class="btn btn-primary" type="submit">Crear usuario</button>
                    </div>
                </form>
            </div>
        </section>

        <section>
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 class="h4 mb-0">Usuarios de la empresa</h2>
                <span class="badge text-bg-secondary"><?= count($users) ?></span>
            </div>
            <div class="vstack gap-3">
                <?php foreach ($users as $user): ?>
                    <?php $isSelf = (int) $user['id'] === (int) $actorUserId; ?>
                    <?php $assignedRoleIds = array_map('intval', array_column($user['roles'], 'id')); ?>
                    <?php $assignedBranchIds = array_map('intval', array_column($user['branches'], 'id')); ?>
                    <article class="card shadow-sm">
                        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <div>
                                <strong><?= esc($user['nombre']) ?></strong>
                                <?php if ($isSelf): ?><span class="badge text-bg-info ms-1">Tu cuenta</span><?php endif; ?>
                            </div>
                            <span class="badge <?= (int) $user['activo'] === 1 ? 'text-bg-success' : 'text-bg-secondary' ?>">
                                <?= (int) $user['activo'] === 1 ? 'Activo' : 'Inactivo' ?>
                            </span>
                        </div>
                        <div class="card-body">
                            <div class="row g-4">
                                <div class="col-xl-6">
                                    <h3 class="h6">Datos de la cuenta</h3>
                                    <form method="post" action="<?= base_url('administracion/usuarios/' . $user['id']) ?>" class="row g-2">
                                        <?= csrf_field() ?>
                                        <div class="col-md-6">
                                            <label class="form-label" for="user-name-<?= esc($user['id']) ?>">Nombre</label>
                                            <input class="form-control" id="user-name-<?= esc($user['id']) ?>" name="nombre" maxlength="255" required value="<?= esc($user['nombre']) ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label" for="user-email-<?= esc($user['id']) ?>">Email</label>
                                            <input class="form-control" type="email" id="user-email-<?= esc($user['id']) ?>" name="email" maxlength="255" required value="<?= esc($user['email']) ?>">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label" for="user-state-<?= esc($user['id']) ?>">Estado</label>
                                            <select class="form-select" id="user-state-<?= esc($user['id']) ?>" name="activo">
                                                <option value="1" <?= (int) $user['activo'] === 1 ? 'selected' : '' ?>>Activo</option>
                                                <?php if (! $isSelf): ?><option value="0" <?= (int) $user['activo'] === 0 ? 'selected' : '' ?>>Inactivo</option><?php endif; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-8">
                                            <label class="form-label" for="user-reason-<?= esc($user['id']) ?>">Motivo</label>
                                            <input class="form-control" id="user-reason-<?= esc($user['id']) ?>" name="motivo" minlength="5" maxlength="255" required>
                                        </div>
                                        <div class="col-12"><button class="btn btn-outline-primary" type="submit">Guardar cuenta</button></div>
                                    </form>
                                </div>

                                <div class="col-xl-6">
                                    <h3 class="h6">Restablecer contraseña</h3>
                                    <form method="post" action="<?= base_url('administracion/usuarios/' . $user['id'] . '/password') ?>" class="row g-2">
                                        <?= csrf_field() ?>
                                        <div class="col-md-6">
                                            <label class="form-label" for="password-<?= esc($user['id']) ?>">Nueva contraseña</label>
                                            <input class="form-control" type="password" id="password-<?= esc($user['id']) ?>" name="password" minlength="8" maxlength="255" autocomplete="new-password" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label" for="password-confirmation-<?= esc($user['id']) ?>">Repetir contraseña</label>
                                            <input class="form-control" type="password" id="password-confirmation-<?= esc($user['id']) ?>" name="password_confirmation" minlength="8" maxlength="255" autocomplete="new-password" required>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label" for="password-reason-<?= esc($user['id']) ?>">Motivo</label>
                                            <input class="form-control" id="password-reason-<?= esc($user['id']) ?>" name="motivo" minlength="5" maxlength="255" required>
                                        </div>
                                        <div class="col-12"><button class="btn btn-outline-warning" type="submit">Restablecer contraseña</button></div>
                                    </form>
                                </div>

                                <div class="col-12 border-top pt-3">
                                    <h3 class="h6">Acceso efectivo</h3>
                                    <?php if ($isSelf): ?>
                                        <p class="text-muted mb-0">Tus propios roles y sucursales no se modifican desde esta pantalla.</p>
                                    <?php else: ?>
                                        <form method="post" action="<?= base_url('administracion/usuarios/' . $user['id'] . '/acceso') ?>" class="row g-3">
                                            <?= csrf_field() ?>
                                            <fieldset class="col-lg-5">
                                                <legend class="form-label">Roles</legend>
                                                <?php foreach ($roles as $role): ?>
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" name="roles[]" value="<?= esc($role['id']) ?>" id="role-<?= esc($user['id']) ?>-<?= esc($role['id']) ?>" <?= in_array((int) $role['id'], $assignedRoleIds, true) ? 'checked' : '' ?>>
                                                        <label class="form-check-label" for="role-<?= esc($user['id']) ?>-<?= esc($role['id']) ?>"><?= esc($role['nombre']) ?></label>
                                                    </div>
                                                <?php endforeach; ?>
                                            </fieldset>
                                            <fieldset class="col-lg-5">
                                                <legend class="form-label">Sucursales</legend>
                                                <?php if ($user['all_company_branches']): ?>
                                                    <div class="alert alert-info py-2">Acceso automático a todas las sucursales activas.</div>
                                                <?php endif; ?>
                                                <?php foreach ($branches as $branch): ?>
                                                    <?php if ((int) $branch['estado'] === 1): ?>
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="checkbox" name="sucursales[]" value="<?= esc($branch['id']) ?>" id="branch-<?= esc($user['id']) ?>-<?= esc($branch['id']) ?>" <?= in_array((int) $branch['id'], $assignedBranchIds, true) ? 'checked' : '' ?>>
                                                            <label class="form-check-label" for="branch-<?= esc($user['id']) ?>-<?= esc($branch['id']) ?>"><?= esc($branch['nombre']) ?></label>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </fieldset>
                                            <div class="col-lg-2">
                                                <label class="form-label" for="access-reason-<?= esc($user['id']) ?>">Motivo</label>
                                                <input class="form-control mb-2" id="access-reason-<?= esc($user['id']) ?>" name="motivo" minlength="5" maxlength="255" required>
                                                <button class="btn btn-outline-primary w-100" type="submit">Guardar acceso</button>
                                            </div>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    </main>
</body>
</html>
