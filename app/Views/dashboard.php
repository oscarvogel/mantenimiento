<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Mantenimiento</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="/dashboard">Mantenimiento</a>
            <div class="navbar-text text-light me-3">
                <?= esc($usuario['nombre']) ?>
            </div>
            <a href="/logout" class="btn btn-outline-light btn-sm">Cerrar sesion</a>
        </div>
    </nav>

    <main class="container py-4">
        <h1 class="h3 mb-4">Bienvenido, <?= esc($usuario['nombre']) ?></h1>

        <div class="row g-3">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body">
                        <h6 class="text-muted small">Email</h6>
                        <p class="mb-0"><?= esc($usuario['email']) ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body">
                        <h6 class="text-muted small">Empresa ID</h6>
                        <p class="mb-0"><?= esc($usuario['empresa_id']) ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body">
                        <h6 class="text-muted small">Sucursales asignadas</h6>
                        <p class="mb-0"><?= count($sucursales) ?></p>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mt-2">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-body">
                        <h6 class="text-muted small mb-2">Roles</h6>
                        <?php foreach ($roles as $r): ?>
                            <span class="badge bg-primary me-1"><?= esc($r['nombre']) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-body">
                        <h6 class="text-muted small mb-2">Permisos (<?= count($permisos) ?>)</h6>
                        <p class="small text-muted mb-0" style="max-height: 120px; overflow: auto;">
                            <?= esc(implode(', ', $permisos)) ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <p class="text-muted small mt-4">
            Bienvenido al sistema. Esta es la pantalla basica de inicio post-login.
            Las siguientes pantallas (equipos, lecturas, planes, ordenes, etc.) se
            iran sumando en las proximas etapas.
        </p>
    </main>
</body>
</html>