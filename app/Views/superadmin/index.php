<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administración global - Mantenimiento</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
    <nav class="navbar navbar-dark bg-dark">
        <div class="container d-flex gap-2">
            <a class="navbar-brand" href="<?= base_url('superadmin') ?>">Administración global</a>
            <div class="ms-auto d-flex gap-2">
                <a class="btn btn-outline-light btn-sm" href="<?= base_url('dashboard') ?>">Dashboard</a>
                <form method="post" action="<?= base_url('logout') ?>">
                    <?= csrf_field() ?>
                    <button class="btn btn-outline-light btn-sm" type="submit">Cerrar sesión</button>
                </form>
            </div>
        </div>
    </nav>

    <main class="container py-4">
        <div class="mb-4">
            <h1 class="h3 mb-1">Empresas y acceso de usuarios</h1>
            <p class="text-muted mb-0">Los traslados eliminan roles y sucursales anteriores para evitar accesos cruzados.</p>
        </div>

        <?php if (session()->getFlashdata('success')): ?>
            <div class="alert alert-success" role="status"><?= esc(session()->getFlashdata('success')) ?></div>
        <?php endif; ?>
        <?php if (session()->getFlashdata('error')): ?>
            <div class="alert alert-danger" role="alert"><?= esc(session()->getFlashdata('error')) ?></div>
        <?php endif; ?>

        <section class="card shadow-sm mb-4">
            <div class="card-header"><h2 class="h5 mb-0">Crear empresa</h2></div>
            <div class="card-body">
                <form method="post" action="<?= base_url('superadmin/empresas') ?>" class="row g-3">
                    <?= csrf_field() ?>
                    <div class="col-md-6">
                        <label class="form-label" for="razon_social">Razón social</label>
                        <input class="form-control" id="razon_social" name="razon_social" maxlength="255" required value="<?= esc(old('razon_social')) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="nombre_fantasia">Nombre de fantasía</label>
                        <input class="form-control" id="nombre_fantasia" name="nombre_fantasia" maxlength="255" value="<?= esc(old('nombre_fantasia')) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="cuit">CUIT</label>
                        <input class="form-control" id="cuit" name="cuit" maxlength="20" value="<?= esc(old('cuit')) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="email">Email</label>
                        <input class="form-control" type="email" id="email" name="email" maxlength="255" value="<?= esc(old('email')) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="telefono">Teléfono</label>
                        <input class="form-control" id="telefono" name="telefono" maxlength="50" value="<?= esc(old('telefono')) ?>">
                    </div>
                    <div class="col-12">
                        <button class="btn btn-primary" type="submit">Crear empresa</button>
                    </div>
                </form>
            </div>
        </section>

        <section class="mb-4">
            <h2 class="h4">Empresas</h2>
            <div class="row g-3">
                <?php foreach ($companies as $company): ?>
                    <div class="col-12 col-xl-6">
                        <form method="post" action="<?= base_url('superadmin/empresas/' . $company['id']) ?>" class="card h-100 shadow-sm">
                            <?= csrf_field() ?>
                            <div class="card-body row g-3">
                                <div class="col-12 d-flex justify-content-between">
                                    <strong>#<?= esc($company['id']) ?></strong>
                                    <span class="badge <?= (int) $company['estado'] === 1 ? 'bg-success' : 'bg-secondary' ?>">
                                        <?= (int) $company['estado'] === 1 ? 'Activa' : 'Inactiva' ?>
                                    </span>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Razón social</label>
                                    <input class="form-control" name="razon_social" required maxlength="255" value="<?= esc($company['razon_social']) ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Nombre de fantasía</label>
                                    <input class="form-control" name="nombre_fantasia" maxlength="255" value="<?= esc($company['nombre_fantasia'] ?? '') ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">CUIT</label>
                                    <input class="form-control" name="cuit" maxlength="20" value="<?= esc($company['cuit'] ?? '') ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Email</label>
                                    <input class="form-control" type="email" name="email" maxlength="255" value="<?= esc($company['email'] ?? '') ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Teléfono</label>
                                    <input class="form-control" name="telefono" maxlength="50" value="<?= esc($company['telefono'] ?? '') ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Estado</label>
                                    <select class="form-select" name="estado">
                                        <option value="1" <?= (int) $company['estado'] === 1 ? 'selected' : '' ?>>Activa</option>
                                        <option value="0" <?= (int) $company['estado'] === 0 ? 'selected' : '' ?>>Inactiva</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <button class="btn btn-outline-primary" type="submit">Guardar empresa</button>
                                </div>
                            </div>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section>
            <h2 class="h4">Usuarios</h2>
            <div class="vstack gap-3">
                <?php foreach ($users as $user): ?>
                    <article class="card shadow-sm">
                        <div class="card-body">
                            <div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
                                <div>
                                    <h3 class="h5 mb-1"><?= esc($user['nombre']) ?></h3>
                                    <div class="text-muted small"><?= esc($user['email']) ?></div>
                                </div>
                                <div>
                                    <?php if ((int) $user['es_superadmin'] === 1): ?>
                                        <span class="badge bg-danger">Superadministrador</span>
                                    <?php else: ?>
                                        <span class="badge bg-primary"><?= esc($user['empresa_nombre'] ?? 'Sin empresa') ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if ((int) $user['es_superadmin'] !== 1): ?>
                                <div class="row g-4">
                                    <div class="col-lg-5">
                                        <form method="post" action="<?= base_url('superadmin/usuarios/' . $user['id'] . '/empresa') ?>">
                                            <?= csrf_field() ?>
                                            <label class="form-label">Empresa</label>
                                            <select class="form-select mb-2" name="empresa_id" required>
                                                <?php foreach ($companies as $company): ?>
                                                    <?php if ((int) $company['estado'] === 1): ?>
                                                        <option value="<?= esc($company['id']) ?>" <?= (int) $company['id'] === (int) $user['empresa_id'] ? 'selected' : '' ?>>
                                                            <?= esc($company['razon_social']) ?>
                                                        </option>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </select>
                                            <label class="form-label">Motivo del cambio</label>
                                            <input class="form-control mb-2" name="motivo" minlength="5" maxlength="255" required placeholder="Ej.: reasignación organizativa">
                                            <button class="btn btn-outline-warning" type="submit">Asignar empresa</button>
                                        </form>
                                    </div>
                                    <div class="col-lg-7">
                                        <form method="post" action="<?= base_url('superadmin/usuarios/' . $user['id'] . '/roles') ?>">
                                            <?= csrf_field() ?>
                                            <fieldset>
                                                <legend class="form-label">Roles empresariales</legend>
                                                <div class="row g-2 mb-2">
                                                    <?php $assignedRoleIds = array_column($user['roles'], 'id'); ?>
                                                    <?php foreach ($roles as $role): ?>
                                                        <div class="col-md-6">
                                                            <div class="form-check">
                                                                <input class="form-check-input" type="checkbox" name="roles[]" value="<?= esc($role['id']) ?>" id="role-<?= esc($user['id']) ?>-<?= esc($role['id']) ?>" <?= in_array((int) $role['id'], array_map('intval', $assignedRoleIds), true) ? 'checked' : '' ?>>
                                                                <label class="form-check-label" for="role-<?= esc($user['id']) ?>-<?= esc($role['id']) ?>"><?= esc($role['nombre']) ?></label>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </fieldset>
                                            <label class="form-label">Motivo de la asignación</label>
                                            <input class="form-control mb-2" name="motivo" minlength="5" maxlength="255" required placeholder="Ej.: responsabilidades aprobadas">
                                            <button class="btn btn-outline-primary" type="submit">Guardar roles</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    </main>
</body>
</html>
