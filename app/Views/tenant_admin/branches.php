<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sucursales - Mantenimiento</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
    <nav class="navbar navbar-dark bg-dark">
        <div class="container d-flex gap-2">
            <a class="navbar-brand" href="<?= base_url('dashboard') ?>">Mantenimiento</a>
            <div class="ms-auto d-flex gap-2">
                <a class="btn btn-outline-light btn-sm" href="<?= base_url('administracion/usuarios') ?>">Usuarios</a>
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
            <h1 class="h3 mb-1">Sucursales</h1>
            <p class="text-muted mb-0">Bases, talleres y ubicaciones operativas de tu empresa.</p>
        </header>

        <?php if (session()->getFlashdata('success')): ?>
            <div class="alert alert-success" role="status"><?= esc(session()->getFlashdata('success')) ?></div>
        <?php endif; ?>
        <?php if (session()->getFlashdata('error')): ?>
            <div class="alert alert-danger" role="alert"><?= esc(session()->getFlashdata('error')) ?></div>
        <?php endif; ?>

        <section class="card shadow-sm mb-4">
            <div class="card-header"><h2 class="h5 mb-0">Nueva sucursal</h2></div>
            <div class="card-body">
                <form method="post" action="<?= base_url('administracion/sucursales') ?>" class="row g-3">
                    <?= csrf_field() ?>
                    <div class="col-md-3">
                        <label class="form-label" for="branch-code">Código</label>
                        <input class="form-control text-uppercase" id="branch-code" name="codigo" maxlength="20" required value="<?= esc(old('codigo')) ?>">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label" for="branch-name">Nombre</label>
                        <input class="form-control" id="branch-name" name="nombre" maxlength="255" required value="<?= esc(old('nombre')) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="branch-email">Email de alertas</label>
                        <input class="form-control" type="email" id="branch-email" name="email_alertas" maxlength="255" value="<?= esc(old('email_alertas')) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="branch-address">Dirección</label>
                        <input class="form-control" id="branch-address" name="direccion" maxlength="255" value="<?= esc(old('direccion')) ?>">
                    </div>
                    <div class="col-12">
                        <button class="btn btn-primary" type="submit">Crear sucursal</button>
                    </div>
                </form>
            </div>
        </section>

        <section>
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 class="h4 mb-0">Sucursales registradas</h2>
                <span class="badge text-bg-secondary"><?= count($branches) ?></span>
            </div>
            <div class="row g-3">
                <?php foreach ($branches as $branch): ?>
                    <div class="col-12 col-xl-6">
                        <form method="post" action="<?= base_url('administracion/sucursales/' . $branch['id']) ?>" class="card h-100 shadow-sm">
                            <?= csrf_field() ?>
                            <div class="card-body row g-3">
                                <div class="col-12 d-flex justify-content-between align-items-center">
                                    <strong><?= esc($branch['codigo']) ?></strong>
                                    <span class="badge <?= (int) $branch['estado'] === 1 ? 'text-bg-success' : 'text-bg-secondary' ?>">
                                        <?= (int) $branch['estado'] === 1 ? 'Activa' : 'Inactiva' ?>
                                    </span>
                                </div>
                                <div class="col-sm-4">
                                    <label class="form-label" for="code-<?= esc($branch['id']) ?>">Código</label>
                                    <input class="form-control text-uppercase" id="code-<?= esc($branch['id']) ?>" name="codigo" maxlength="20" required value="<?= esc($branch['codigo']) ?>">
                                </div>
                                <div class="col-sm-8">
                                    <label class="form-label" for="name-<?= esc($branch['id']) ?>">Nombre</label>
                                    <input class="form-control" id="name-<?= esc($branch['id']) ?>" name="nombre" maxlength="255" required value="<?= esc($branch['nombre']) ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="address-<?= esc($branch['id']) ?>">Dirección</label>
                                    <input class="form-control" id="address-<?= esc($branch['id']) ?>" name="direccion" maxlength="255" value="<?= esc($branch['direccion'] ?? '') ?>">
                                </div>
                                <div class="col-sm-8">
                                    <label class="form-label" for="email-<?= esc($branch['id']) ?>">Email de alertas</label>
                                    <input class="form-control" type="email" id="email-<?= esc($branch['id']) ?>" name="email_alertas" maxlength="255" value="<?= esc($branch['email_alertas'] ?? '') ?>">
                                </div>
                                <div class="col-sm-4">
                                    <label class="form-label" for="state-<?= esc($branch['id']) ?>">Estado</label>
                                    <select class="form-select" id="state-<?= esc($branch['id']) ?>" name="estado">
                                        <option value="1" <?= (int) $branch['estado'] === 1 ? 'selected' : '' ?>>Activa</option>
                                        <option value="0" <?= (int) $branch['estado'] === 0 ? 'selected' : '' ?>>Inactiva</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <button class="btn btn-outline-primary" type="submit">Guardar cambios</button>
                                </div>
                            </div>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </main>
</body>
</html>
