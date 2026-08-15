<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Circuito preventivo - Mantenimiento</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
    <nav class="navbar navbar-dark bg-dark">
        <div class="container d-flex gap-2">
            <a class="navbar-brand" href="<?= base_url('dashboard') ?>">Mantenimiento</a>
            <span class="navbar-text text-light d-none d-sm-inline"><?= esc($company['nombre_fantasia'] ?: $company['razon_social']) ?></span>
            <form method="post" action="<?= base_url('logout') ?>" class="ms-auto mb-0">
                <?= csrf_field() ?>
                <button class="btn btn-outline-light btn-sm" type="submit">Cerrar sesión</button>
            </form>
        </div>
    </nav>

    <main class="container py-4">
        <header class="mb-4">
            <p class="text-primary fw-semibold mb-1">Primer circuito vertical</p>
            <h1 class="h3 mb-2">Mantenimiento preventivo</h1>
            <p class="text-muted mb-0">Equipo → lectura → plan → vencimiento → orden → cierre → próximo servicio.</p>
            <a class="btn btn-sm btn-outline-primary mt-3" href="<?= base_url('mantenimiento/equipos') ?>">Ver listado y catálogos de equipos</a>
        </header>

        <?php if (session()->getFlashdata('success')): ?>
            <div class="alert alert-success" role="status"><?= esc(session()->getFlashdata('success')) ?></div>
        <?php endif; ?>
        <?php if (session()->getFlashdata('error')): ?>
            <div class="alert alert-danger" role="alert"><?= esc(session()->getFlashdata('error')) ?></div>
        <?php endif; ?>

        <section class="card shadow-sm mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h2 class="h5 mb-0">1. Equipos y lecturas</h2>
                <span class="badge text-bg-secondary"><?= count($equipments) ?></span>
            </div>
            <div class="card-body">
                <?php if ($can['createEquipment']): ?>
                    <form method="post" action="<?= base_url('mantenimiento/equipos') ?>" class="row g-3 border-bottom pb-4 mb-4">
                        <?= csrf_field() ?>
                        <div class="col-sm-6 col-lg-3">
                            <label class="form-label" for="equipment-code">Código</label>
                            <input class="form-control text-uppercase" id="equipment-code" name="codigo" maxlength="50" required value="<?= esc(old('codigo')) ?>">
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <label class="form-label" for="equipment-plate">Patente</label>
                            <input class="form-control text-uppercase" id="equipment-plate" name="patente" maxlength="20" value="<?= esc(old('patente')) ?>">
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <label class="form-label" for="equipment-branch">Sucursal</label>
                            <select class="form-select" id="equipment-branch" name="sucursal_id" required>
                                <?php foreach ($branches as $branch): ?>
                                    <option value="<?= esc($branch['id']) ?>"><?= esc($branch['codigo'] . ' · ' . $branch['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <label class="form-label" for="equipment-type">Tipo</label>
                            <select class="form-select" id="equipment-type" name="tipo_equipo_id" required>
                                <?php foreach ($equipmentTypes as $type): ?>
                                    <option value="<?= esc($type['id']) ?>"><?= esc($type['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <label class="form-label" for="equipment-date">Fecha de alta</label>
                            <input class="form-control" id="equipment-date" type="date" name="fecha_alta" required value="<?= esc(old('fecha_alta') ?: date('Y-m-d')) ?>">
                        </div>
                        <div class="col-sm-6 col-lg-3"><label class="form-label" for="equipment-brand">Marca</label><select class="form-select" id="equipment-brand" name="marca_id"><option value="">Sin informar</option><?php foreach ($assetCatalogs['brands'] as $brand): ?><option value="<?= esc($brand['id']) ?>"><?= esc($brand['nombre']) ?></option><?php endforeach; ?></select></div>
                        <div class="col-sm-6 col-lg-3"><label class="form-label" for="equipment-model">Modelo</label><select class="form-select" id="equipment-model" name="modelo_id"><option value="">Sin informar</option><?php foreach ($assetCatalogs['models'] as $model): ?><option value="<?= esc($model['id']) ?>"><?= esc($model['marca_nombre'] . ' · ' . $model['nombre'] . ' · ' . $model['tipo_nombre']) ?></option><?php endforeach; ?></select></div>
                        <div class="col-sm-6 col-lg-2"><label class="form-label" for="equipment-year">Año</label><input class="form-control" id="equipment-year" type="number" min="1900" max="2100" name="anio" value="<?= esc(old('anio')) ?>"></div>
                        <div class="col-sm-6 col-lg-3"><label class="form-label" for="equipment-chassis">Chasis</label><input class="form-control" id="equipment-chassis" name="chasis" maxlength="100" value="<?= esc(old('chasis')) ?>"></div>
                        <div class="col-sm-6 col-lg-3"><label class="form-label" for="equipment-engine">Motor</label><input class="form-control" id="equipment-engine" name="motor" maxlength="100" value="<?= esc(old('motor')) ?>"></div>
                        <div class="col-sm-6 col-lg-7">
                            <label class="form-label" for="equipment-notes">Observaciones</label>
                            <input class="form-control" id="equipment-notes" name="observaciones" maxlength="500" value="<?= esc(old('observaciones')) ?>">
                        </div>
                        <div class="col-lg-2 d-flex align-items-end">
                            <button class="btn btn-primary w-100" type="submit">Crear equipo</button>
                        </div>
                    </form>
                <?php endif; ?>

                <?php if ($equipments === []): ?>
                    <p class="text-muted mb-0">Todavía no hay equipos dentro de tu alcance.</p>
                <?php endif; ?>
                <div class="row g-3">
                    <?php foreach ($equipments as $equipment): ?>
                        <div class="col-12 col-xl-6">
                            <article class="card h-100">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between gap-2 mb-3">
                                        <div>
                                            <h3 class="h5 mb-1"><?= esc($equipment['codigo']) ?></h3>
                                            <p class="text-muted small mb-0"><?= esc($equipment['tipo_nombre'] . ' · ' . $equipment['sucursal_nombre']) ?></p>
                                        </div>
                                        <span class="badge text-bg-success align-self-start"><?= esc($equipment['estado']) ?></span>
                                    </div>
                                    <div class="d-flex flex-wrap gap-2 mb-3">
                                        <?php if ((int) $equipment['controla_km'] === 1): ?><span class="badge text-bg-light">Km: <?= esc($equipment['km_actual'] ?? 'sin datos') ?></span><?php endif; ?>
                                        <?php if ((int) $equipment['controla_horas'] === 1): ?><span class="badge text-bg-light">Horas: <?= esc($equipment['horas_actuales'] ?? 'sin datos') ?></span><?php endif; ?>
                                        <?php if ($equipment['patente']): ?><span class="badge text-bg-light">Patente: <?= esc($equipment['patente']) ?></span><?php endif; ?>
                                    </div>

                                    <a class="btn btn-sm btn-outline-dark mb-3" href="<?= base_url('mantenimiento/equipos/' . $equipment['id']) ?>">Ver ficha e historial</a>

                                    <?php if ($can['registerReading']): ?>
                                        <form method="post" action="<?= base_url('mantenimiento/equipos/' . $equipment['id'] . '/lecturas') ?>" class="row g-2 mb-3">
                                            <?= csrf_field() ?>
                                            <div class="col-sm-4">
                                                <label class="form-label small" for="km-<?= esc($equipment['id']) ?>">Kilómetros</label>
                                                <input class="form-control" id="km-<?= esc($equipment['id']) ?>" type="number" min="0" name="kilometraje" <?= (int) $equipment['controla_km'] !== 1 ? 'disabled' : '' ?>>
                                            </div>
                                            <div class="col-sm-4">
                                                <label class="form-label small" for="hours-<?= esc($equipment['id']) ?>">Horómetro</label>
                                                <input class="form-control" id="hours-<?= esc($equipment['id']) ?>" type="text" inputmode="decimal" name="horometro" <?= (int) $equipment['controla_horas'] !== 1 ? 'disabled' : '' ?>>
                                            </div>
                                            <div class="col-sm-4 d-flex align-items-end">
                                                <button class="btn btn-outline-primary w-100" type="submit">Cargar lectura</button>
                                            </div>
                                            <input type="hidden" name="fecha_lectura" value="<?= date('Y-m-d H:i:s') ?>">
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($can['assignPlan']): ?>
                                        <details>
                                            <summary class="text-primary fw-semibold">Asignar plan preventivo</summary>
                                            <form method="post" action="<?= base_url('mantenimiento/equipos/' . $equipment['id'] . '/planes') ?>" class="row g-2 mt-2">
                                                <?= csrf_field() ?>
                                                <div class="col-12">
                                                    <label class="form-label small" for="service-<?= esc($equipment['id']) ?>">Servicio</label>
                                                    <select class="form-select" id="service-<?= esc($equipment['id']) ?>" name="tipo_servicio_id" required>
                                                        <?php foreach ($serviceTypes as $service): ?><option value="<?= esc($service['id']) ?>"><?= esc($service['nombre']) ?></option><?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <?php if ((int) $equipment['controla_km'] === 1): ?>
                                                    <div class="col-6"><label class="form-label small">Cada km</label><input class="form-control" type="number" min="1" name="intervalo_km"></div>
                                                    <div class="col-6"><label class="form-label small">Avisar antes (km)</label><input class="form-control" type="number" min="0" name="anticipacion_km"></div>
                                                <?php endif; ?>
                                                <?php if ((int) $equipment['controla_horas'] === 1): ?>
                                                    <div class="col-6"><label class="form-label small">Cada horas</label><input class="form-control" type="number" min="0.1" step="0.1" name="intervalo_horas"></div>
                                                    <div class="col-6"><label class="form-label small">Avisar antes (h)</label><input class="form-control" type="number" min="0" step="0.1" name="anticipacion_horas"></div>
                                                <?php endif; ?>
                                                <div class="col-6"><label class="form-label small">Cada días</label><input class="form-control" type="number" min="1" name="intervalo_dias"></div>
                                                <div class="col-6"><label class="form-label small">Avisar antes (días)</label><input class="form-control" type="number" min="0" name="anticipacion_dias"></div>
                                                <div class="col-12"><button class="btn btn-primary" type="submit">Crear plan</button></div>
                                            </form>
                                        </details>
                                    <?php endif; ?>
                                </div>
                            </article>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="card shadow-sm mb-4">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <h2 class="h5 mb-0">2. Vencimientos y avisos</h2>
                <?php if ($can['detectDue']): ?>
                    <form method="post" action="<?= base_url('mantenimiento/vencimientos/detectar') ?>" class="mb-0">
                        <?= csrf_field() ?><button class="btn btn-warning btn-sm" type="submit">Detectar vencidos</button>
                    </form>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div class="row g-3 mb-4">
                    <?php foreach ($plans as $plan): ?>
                        <?php $stateClass = ['VENCIDO' => 'danger', 'PROXIMO' => 'warning', 'AL_DIA' => 'success', 'SIN_DATOS' => 'secondary'][$plan['computed_state'] ?? 'SIN_DATOS']; ?>
                        <div class="col-sm-6 col-xl-4">
                            <article class="border rounded p-3 h-100">
                                <div class="d-flex justify-content-between gap-2"><strong><?= esc($plan['equipo_codigo']) ?></strong><span class="badge text-bg-<?= esc($stateClass) ?>"><?= esc($plan['computed_state'] ?? 'SIN_DATOS') ?></span></div>
                                <p class="mb-2"><?= esc($plan['servicio_nombre']) ?></p>
                                <p class="small text-muted mb-0">Próximo: <?= $plan['proximo_km'] !== null ? esc($plan['proximo_km'] . ' km') : '' ?> <?= $plan['proximas_horas'] !== null ? esc($plan['proximas_horas'] . ' h') : '' ?> <?= $plan['proxima_fecha'] !== null ? esc($plan['proxima_fecha']) : '' ?></p>
                            </article>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($plans === []): ?><p class="text-muted mb-0">No hay planes activos.</p><?php endif; ?>
                </div>

                <h3 class="h6">Avisos pendientes</h3>
                <?php foreach ($notices as $notice): ?>
                    <div class="border rounded p-3 mb-2 d-flex flex-column flex-lg-row justify-content-between gap-3">
                        <div><strong><?= esc($notice['equipo_codigo']) ?></strong> · <?= esc($notice['servicio_nombre']) ?><br><span class="text-danger small">Vencido por <?= esc($notice['criterios_disparadores']) ?></span></div>
                        <?php if ($can['generateOrder']): ?>
                            <form method="post" action="<?= base_url('mantenimiento/avisos/' . $notice['id'] . '/orden') ?>" class="d-flex flex-wrap gap-2">
                                <?= csrf_field() ?>
                                <select class="form-select form-select-sm" name="responsable_usuario_id" aria-label="Responsable">
                                    <?php foreach ($users as $user): ?><option value="<?= esc($user['id']) ?>"><?= esc($user['nombre']) ?></option><?php endforeach; ?>
                                </select>
                                <button class="btn btn-primary btn-sm" type="submit">Generar OT</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if ($notices === []): ?><p class="text-muted mb-0">No hay avisos vencidos pendientes.</p><?php endif; ?>
            </div>
        </section>

        <section class="card shadow-sm mb-4">
            <div class="card-header"><h2 class="h5 mb-0">3. Órdenes de trabajo</h2></div>
            <div class="card-body">
                <div class="row g-3">
                    <?php foreach ($orders as $order): ?>
                        <div class="col-12 col-xl-6">
                            <article class="border rounded p-3 h-100">
                                <div class="d-flex justify-content-between gap-2 mb-2"><strong><?= esc($order['numero']) ?> · <?= esc($order['equipo_codigo']) ?></strong><span class="badge text-bg-info"><?= esc($order['estado']) ?></span></div>
                                <p class="small text-muted"><?= esc($order['servicio_nombre'] ?? 'Servicio preventivo') ?> · Responsable: <?= esc($order['responsable_nombre'] ?? 'Sin asignar') ?></p>
                                <?php foreach ($order['tasks'] as $task): ?><p class="small mb-1">• <?= esc($task['descripcion_solicitada']) ?> <span class="text-muted">(<?= esc($task['estado']) ?>)</span></p><?php endforeach; ?>

                                <?php if ($order['estado'] === 'EMITIDA' && $can['editOrder']): ?>
                                    <form method="post" action="<?= base_url('mantenimiento/ordenes/' . $order['id'] . '/iniciar') ?>" class="mt-3">
                                        <?= csrf_field() ?><button class="btn btn-outline-primary" type="submit">Iniciar orden</button>
                                    </form>
                                <?php elseif ($order['estado'] === 'EN_PROCESO' && $can['closeOrder']): ?>
                                    <form method="post" action="<?= base_url('mantenimiento/ordenes/' . $order['id'] . '/cerrar') ?>" class="row g-2 mt-2">
                                        <?= csrf_field() ?>
                                        <div class="col-12"><label class="form-label small">Trabajo realizado</label><textarea class="form-control" name="trabajo_realizado" rows="2" required></textarea></div>
                                        <div class="col-sm-4"><label class="form-label small">Fecha servicio</label><input class="form-control" type="date" name="fecha_servicio" required value="<?= date('Y-m-d') ?>"></div>
                                        <div class="col-sm-4"><label class="form-label small">Km salida</label><input class="form-control" type="number" min="0" name="km_salida"></div>
                                        <div class="col-sm-4"><label class="form-label small">Horas salida</label><input class="form-control" type="text" inputmode="decimal" name="horas_salida"></div>
                                        <div class="col-12"><button class="btn btn-success" type="submit">Cerrar y recalcular</button></div>
                                    </form>
                                <?php endif; ?>
                            </article>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($orders === []): ?><p class="text-muted mb-0">Todavía no hay órdenes en este circuito.</p><?php endif; ?>
                </div>
            </div>
        </section>

        <section class="card shadow-sm">
            <div class="card-header"><h2 class="h5 mb-0">Historial reciente de lecturas</h2></div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Equipo</th><th>Fecha</th><th>Km</th><th>Horas</th><th>Origen</th></tr></thead>
                    <tbody>
                        <?php foreach ($readings as $reading): ?><tr><td><?= esc($reading['equipo_codigo']) ?></td><td><?= esc($reading['fecha_lectura']) ?></td><td><?= esc($reading['kilometraje'] ?? '—') ?></td><td><?= esc($reading['horometro'] ?? '—') ?></td><td><?= esc($reading['origen']) ?></td></tr><?php endforeach; ?>
                        <?php if ($readings === []): ?><tr><td colspan="5" class="text-muted">Sin lecturas registradas.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>
