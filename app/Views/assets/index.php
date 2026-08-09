<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Equipos - Mantenimiento</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark">
    <div class="container d-flex gap-2">
        <a class="navbar-brand" href="<?= base_url('dashboard') ?>">Mantenimiento</a>
        <a class="btn btn-outline-light btn-sm ms-auto" href="<?= base_url('mantenimiento') ?>">Circuito preventivo</a>
        <form method="post" action="<?= base_url('logout') ?>" class="mb-0"><?= csrf_field() ?><button class="btn btn-outline-light btn-sm" type="submit">Cerrar sesión</button></form>
    </div>
</nav>
<main class="container py-4">
    <header class="mb-4"><p class="text-primary fw-semibold mb-1">Registro de activos</p><h1 class="h3">Equipos y catálogos</h1><p class="text-muted">Listado filtrado, ficha técnica, relaciones y acceso QR.</p></header>
    <?php if (session()->getFlashdata('success')): ?><div class="alert alert-success" role="status"><?= esc(session()->getFlashdata('success')) ?></div><?php endif; ?>
    <?php if (session()->getFlashdata('error')): ?><div class="alert alert-danger" role="alert"><?= esc(session()->getFlashdata('error')) ?></div><?php endif; ?>

    <section class="card shadow-sm mb-4">
        <div class="card-header"><h2 class="h5 mb-0">Listado de equipos</h2></div>
        <div class="card-body border-bottom">
            <form method="get" class="row g-2">
                <div class="col-md-4"><label class="form-label" for="filter-q">Buscar</label><input class="form-control" id="filter-q" name="q" maxlength="100" value="<?= esc($filters['q']) ?>" placeholder="Código, patente o chasis"></div>
                <div class="col-md-2"><label class="form-label" for="filter-type">Tipo</label><select class="form-select" id="filter-type" name="tipo_id"><option value="">Todos</option><?php foreach ($catalogs['types'] as $type): ?><option value="<?= esc($type['id']) ?>" <?= (int) ($filters['type_id'] ?? 0) === (int) $type['id'] ? 'selected' : '' ?>><?= esc($type['nombre']) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-2"><label class="form-label" for="filter-brand">Marca</label><select class="form-select" id="filter-brand" name="marca_id"><option value="">Todas</option><?php foreach ($catalogs['brands'] as $brand): ?><?php if ((int) $brand['activo'] === 1): ?><option value="<?= esc($brand['id']) ?>" <?= (int) ($filters['brand_id'] ?? 0) === (int) $brand['id'] ? 'selected' : '' ?>><?= esc($brand['nombre']) ?></option><?php endif; ?><?php endforeach; ?></select></div>
                <div class="col-md-2"><label class="form-label" for="filter-branch">Sucursal ID</label><input class="form-control" id="filter-branch" name="sucursal_id" type="number" min="1" value="<?= esc($filters['branch_id']) ?>"></div>
                <div class="col-md-2"><label class="form-label" for="filter-status">Estado</label><select class="form-select" id="filter-status" name="estado"><option value="">Todos</option><option value="ACTIVO" <?= $filters['status'] === 'ACTIVO' ? 'selected' : '' ?>>Activo</option><option value="BAJA" <?= $filters['status'] === 'BAJA' ? 'selected' : '' ?>>Baja</option></select></div>
                <div class="col-12"><button class="btn btn-primary" type="submit">Aplicar filtros</button> <a class="btn btn-outline-secondary" href="<?= base_url('mantenimiento/equipos') ?>">Limpiar</a></div>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table align-middle mb-0"><thead><tr><th>Equipo</th><th>Ficha técnica</th><th>Sucursal</th><th>Uso actual</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead><tbody>
            <?php foreach ($equipmentPage['items'] as $equipment): ?><tr>
                <td><strong><?= esc($equipment['codigo']) ?></strong><br><span class="small text-muted"><?= esc($equipment['tipo_nombre']) ?><?= $equipment['patente'] ? ' · ' . esc($equipment['patente']) : '' ?></span></td>
                <td><?= esc($equipment['marca_nombre'] ?? 'Sin marca') ?><?= $equipment['modelo_nombre'] ? ' · ' . esc($equipment['modelo_nombre']) : '' ?><br><span class="small text-muted"><?= $equipment['anio'] ? esc($equipment['anio']) : 'Año sin informar' ?></span></td>
                <td><?= esc($equipment['sucursal_codigo'] . ' · ' . $equipment['sucursal_nombre']) ?></td>
                <td><?= $equipment['km_actual'] === null ? '—' : esc($equipment['km_actual'] . ' km') ?><br><?= $equipment['horas_actuales'] === null ? '—' : esc($equipment['horas_actuales'] . ' h') ?></td>
                <td><span class="badge text-bg-<?= $equipment['estado'] === 'ACTIVO' ? 'success' : 'secondary' ?>"><?= esc($equipment['estado']) ?></span></td>
                <td class="text-end"><a class="btn btn-sm btn-outline-dark" href="<?= base_url('mantenimiento/equipos/' . $equipment['id']) ?>">Ficha</a> <a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener" href="<?= base_url('mantenimiento/equipos/' . $equipment['id'] . '/qr.svg') ?>">QR</a></td>
            </tr><?php endforeach; ?>
            <?php if ($equipmentPage['items'] === []): ?><tr><td colspan="6" class="text-muted text-center py-4">No hay equipos que coincidan con los filtros.</td></tr><?php endif; ?>
            </tbody></table>
        </div>
        <?php if ($equipmentPage['totalPages'] > 1): ?><div class="card-footer d-flex justify-content-between"><a class="btn btn-sm btn-outline-secondary <?= $equipmentPage['page'] <= 1 ? 'disabled' : '' ?>" href="?<?= http_build_query(array_filter(array_merge($filters, ['page' => max(1, $equipmentPage['page'] - 1)]), static fn ($value): bool => $value !== null && $value !== '')) ?>">Anterior</a><span class="small text-muted">Página <?= esc($equipmentPage['page']) ?> de <?= esc($equipmentPage['totalPages']) ?> · <?= esc($equipmentPage['total']) ?> equipos</span><a class="btn btn-sm btn-outline-secondary <?= $equipmentPage['page'] >= $equipmentPage['totalPages'] ? 'disabled' : '' ?>" href="?<?= http_build_query(array_filter(array_merge($filters, ['page' => min($equipmentPage['totalPages'], $equipmentPage['page'] + 1)]), static fn ($value): bool => $value !== null && $value !== '')) ?>">Siguiente</a></div><?php endif; ?>
    </section>

    <?php if ($canEdit): ?>
    <section class="row g-3">
        <div class="col-12 col-xl-5"><div class="card shadow-sm h-100"><div class="card-header"><h2 class="h5 mb-0">Marcas</h2></div><div class="card-body">
            <form method="post" action="<?= base_url('mantenimiento/catalogos/marcas') ?>" class="input-group mb-3"><?= csrf_field() ?><input class="form-control" name="nombre" maxlength="100" required placeholder="Nueva marca"><button class="btn btn-primary">Crear</button></form>
            <?php foreach ($catalogs['brands'] as $brand): ?><form method="post" action="<?= base_url('mantenimiento/catalogos/marcas/' . $brand['id']) ?>" class="d-flex gap-2 mb-2"><?= csrf_field() ?><input class="form-control form-control-sm" name="nombre" maxlength="100" required value="<?= esc($brand['nombre']) ?>" <?= (int) $brand['activo'] !== 1 ? 'disabled' : '' ?>><button class="btn btn-sm btn-outline-primary" <?= (int) $brand['activo'] !== 1 ? 'disabled' : '' ?>>Guardar</button></form><?php if ((int) $brand['activo'] === 1): ?><form method="post" action="<?= base_url('mantenimiento/catalogos/marcas/' . $brand['id'] . '/inactivar') ?>" class="mb-3"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger" type="submit">Inactivar <?= esc($brand['nombre']) ?></button></form><?php else: ?><p class="small text-muted">Inactiva</p><?php endif; ?><?php endforeach; ?>
        </div></div></div>
        <div class="col-12 col-xl-7"><div class="card shadow-sm h-100"><div class="card-header"><h2 class="h5 mb-0">Modelos</h2></div><div class="card-body">
            <form method="post" action="<?= base_url('mantenimiento/catalogos/modelos') ?>" class="row g-2 mb-3"><?= csrf_field() ?><div class="col-sm-4"><select class="form-select" name="marca_id" required><?php foreach ($catalogs['brands'] as $brand): ?><?php if ((int) $brand['activo'] === 1): ?><option value="<?= esc($brand['id']) ?>"><?= esc($brand['nombre']) ?></option><?php endif; ?><?php endforeach; ?></select></div><div class="col-sm-4"><select class="form-select" name="tipo_equipo_id" required><?php foreach ($catalogs['types'] as $type): ?><?php if ((int) $type['activo'] === 1): ?><option value="<?= esc($type['id']) ?>"><?= esc($type['nombre']) ?></option><?php endif; ?><?php endforeach; ?></select></div><div class="col-sm-4"><div class="input-group"><input class="form-control" name="nombre" maxlength="100" required placeholder="Modelo"><button class="btn btn-primary">Crear</button></div></div></form>
            <?php foreach ($catalogs['models'] as $model): ?><div class="border rounded p-2 mb-2"><div class="small text-muted mb-1"><?= esc($model['marca_nombre'] . ' · ' . $model['tipo_nombre']) ?></div><form method="post" action="<?= base_url('mantenimiento/catalogos/modelos/' . $model['id']) ?>" class="d-flex gap-2"><?= csrf_field() ?><input class="form-control form-control-sm" name="nombre" maxlength="100" required value="<?= esc($model['nombre']) ?>" <?= (int) $model['activo'] !== 1 ? 'disabled' : '' ?>><button class="btn btn-sm btn-outline-primary" <?= (int) $model['activo'] !== 1 ? 'disabled' : '' ?>>Guardar</button></form><?php if ((int) $model['activo'] === 1): ?><form method="post" action="<?= base_url('mantenimiento/catalogos/modelos/' . $model['id'] . '/inactivar') ?>" class="mt-2"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger" type="submit">Inactivar</button></form><?php else: ?><span class="badge text-bg-secondary mt-2">Inactivo</span><?php endif; ?></div><?php endforeach; ?>
        </div></div></div>
    </section>
    <?php endif; ?>
</main>
</body>
</html>
