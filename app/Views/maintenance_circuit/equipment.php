<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ficha <?= esc($equipment['codigo']) ?> - Mantenimiento</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
    <nav class="navbar navbar-dark bg-dark">
        <div class="container d-flex gap-2">
            <a class="navbar-brand" href="<?= base_url('dashboard') ?>">Mantenimiento</a>
            <a class="btn btn-outline-light btn-sm ms-auto" href="<?= base_url('mantenimiento/equipos') ?>">Listado</a>
            <a class="btn btn-outline-light btn-sm" href="<?= base_url('mantenimiento/equipos/' . $equipment['id'] . '/qr.svg') ?>" target="_blank" rel="noopener">Ver QR</a>
            <a class="btn btn-outline-light btn-sm" href="<?= base_url('mantenimiento') ?>">Circuito</a>
            <form method="post" action="<?= base_url('logout') ?>" class="mb-0">
                <?= csrf_field() ?>
                <button class="btn btn-outline-light btn-sm" type="submit">Cerrar sesión</button>
            </form>
        </div>
    </nav>

    <main class="container py-4">
        <header class="d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-3 mb-4">
            <div>
                <p class="text-primary fw-semibold mb-1">Ficha del equipo</p>
                <h1 class="h3 mb-1"><?= esc($equipment['codigo']) ?></h1>
                <p class="text-muted mb-0"><?= esc($equipment['tipo_nombre']) ?> · <?= esc($equipment['sucursal_codigo'] . ' · ' . $equipment['sucursal_nombre']) ?></p>
            </div>
            <span class="badge fs-6 text-bg-<?= $equipment['estado'] === 'ACTIVO' ? 'success' : 'secondary' ?>"><?= esc($equipment['estado']) ?></span>
        </header>

        <?php if (session()->getFlashdata('success')): ?>
            <div class="alert alert-success" role="status"><?= esc(session()->getFlashdata('success')) ?></div>
        <?php endif; ?>
        <?php if (session()->getFlashdata('error')): ?>
            <div class="alert alert-danger" role="alert"><?= esc(session()->getFlashdata('error')) ?></div>
        <?php endif; ?>

        <section class="row g-3 mb-4" aria-label="Resumen del equipo">
            <div class="col-6 col-lg-3">
                <div class="card h-100"><div class="card-body"><div class="text-muted small">Kilometraje actual</div><strong><?= esc($equipment['km_actual'] ?? 'Sin datos') ?><?= $equipment['km_actual'] !== null ? ' km' : '' ?></strong></div></div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="card h-100"><div class="card-body"><div class="text-muted small">Horómetro actual</div><strong><?= esc($equipment['horas_actuales'] ?? 'Sin datos') ?><?= $equipment['horas_actuales'] !== null ? ' h' : '' ?></strong></div></div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="card h-100"><div class="card-body"><div class="text-muted small">Patente</div><strong><?= esc($equipment['patente'] ?? 'Sin informar') ?></strong></div></div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="card h-100"><div class="card-body"><div class="text-muted small">Alta</div><strong><?= esc($equipment['fecha_alta']) ?></strong><?php if ($equipment['fecha_baja']): ?><div class="small text-danger">Baja: <?= esc($equipment['fecha_baja']) ?></div><?php endif; ?></div></div>
            </div>
        </section>

        <?php if ($can['edit']): ?>
            <section class="row g-3 mb-4">
                <div class="col-12 col-xl-6">
                    <div class="card shadow-sm h-100">
                        <div class="card-header"><h2 class="h5 mb-0">Datos del equipo</h2></div>
                        <div class="card-body">
                            <?php if ($equipment['estado'] === 'ACTIVO'): ?>
                                <form method="post" action="<?= base_url('mantenimiento/equipos/' . $equipment['id'] . '/editar') ?>" class="row g-3">
                                    <?= csrf_field() ?>
                                    <div class="col-sm-6"><label class="form-label" for="equipment-code">Código</label><input class="form-control text-uppercase" id="equipment-code" name="codigo" maxlength="50" required value="<?= esc(old('codigo') ?: $equipment['codigo']) ?>"></div>
                                    <div class="col-sm-6"><label class="form-label" for="equipment-plate">Patente</label><input class="form-control text-uppercase" id="equipment-plate" name="patente" maxlength="20" value="<?= esc(old('patente') ?: $equipment['patente']) ?>"></div>
                                    <div class="col-sm-6"><label class="form-label" for="equipment-brand">Marca</label><select class="form-select" id="equipment-brand" name="marca_id"><option value="">Sin informar</option><?php foreach ($catalogs['brands'] as $brand): ?><option value="<?= esc($brand['id']) ?>" <?= (int) ($equipment['marca_id'] ?? 0) === (int) $brand['id'] ? 'selected' : '' ?>><?= esc($brand['nombre']) ?></option><?php endforeach; ?></select></div>
                                    <div class="col-sm-6"><label class="form-label" for="equipment-model">Modelo</label><select class="form-select" id="equipment-model" name="modelo_id"><option value="">Sin informar</option><?php foreach ($catalogs['models'] as $model): ?><option value="<?= esc($model['id']) ?>" <?= (int) ($equipment['modelo_id'] ?? 0) === (int) $model['id'] ? 'selected' : '' ?>><?= esc($model['marca_nombre'] . ' · ' . $model['nombre'] . ' · ' . $model['tipo_nombre']) ?></option><?php endforeach; ?></select></div>
                                    <div class="col-sm-4"><label class="form-label" for="equipment-year">Año</label><input class="form-control" id="equipment-year" type="number" min="1900" max="2100" name="anio" value="<?= esc(old('anio') ?: $equipment['anio']) ?>"></div>
                                    <div class="col-sm-4"><label class="form-label" for="equipment-chassis">Chasis</label><input class="form-control" id="equipment-chassis" name="chasis" maxlength="100" value="<?= esc(old('chasis') ?: $equipment['chasis']) ?>"></div>
                                    <div class="col-sm-4"><label class="form-label" for="equipment-engine">Motor</label><input class="form-control" id="equipment-engine" name="motor" maxlength="100" value="<?= esc(old('motor') ?: $equipment['motor']) ?>"></div>
                                    <div class="col-12"><label class="form-label" for="equipment-notes">Observaciones</label><textarea class="form-control" id="equipment-notes" name="observaciones" rows="3"><?= esc(old('observaciones') ?: $equipment['observaciones']) ?></textarea></div>
                                    <div class="col-12"><button class="btn btn-primary" type="submit">Guardar cambios</button></div>
                                </form>
                            <?php else: ?>
                                <p class="text-muted mb-0">La ficha queda en modo de consulta porque el equipo está dado de baja.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-xl-6">
                    <div class="card shadow-sm h-100">
                        <div class="card-header"><h2 class="h5 mb-0">Ubicación y estado</h2></div>
                        <div class="card-body">
                            <?php if ($equipment['estado'] === 'ACTIVO'): ?>
                                <?php $destinations = array_values(array_filter($availableBranches, static fn (array $branch): bool => (int) $branch['id'] !== (int) $equipment['sucursal_id'])); ?>
                                <?php if ($destinations !== []): ?>
                                    <form method="post" action="<?= base_url('mantenimiento/equipos/' . $equipment['id'] . '/trasladar') ?>" class="row g-3 border-bottom pb-4 mb-4">
                                        <?= csrf_field() ?>
                                        <div class="col-sm-7"><label class="form-label" for="destination-branch">Nueva sucursal</label><select class="form-select" id="destination-branch" name="sucursal_destino_id" required><?php foreach ($destinations as $branch): ?><option value="<?= esc($branch['id']) ?>"><?= esc($branch['codigo'] . ' · ' . $branch['nombre']) ?></option><?php endforeach; ?></select></div>
                                        <div class="col-sm-5"><label class="form-label" for="transfer-date">Fecha</label><input class="form-control" id="transfer-date" name="fecha_traslado" type="date" required value="<?= date('Y-m-d') ?>"></div>
                                        <div class="col-12"><label class="form-label" for="transfer-reason">Motivo</label><textarea class="form-control" id="transfer-reason" name="motivo" minlength="5" maxlength="255" rows="2" required></textarea></div>
                                        <div class="col-12"><button class="btn btn-outline-primary" type="submit">Registrar traslado</button></div>
                                    </form>
                                <?php else: ?>
                                    <p class="text-muted">No hay otra sucursal activa y autorizada disponible para trasladar este equipo.</p>
                                <?php endif; ?>

                                <form method="post" action="<?= base_url('mantenimiento/equipos/' . $equipment['id'] . '/baja') ?>" class="row g-3" onsubmit="return confirm('¿Confirmás la baja lógica? El historial se conservará.');">
                                    <?= csrf_field() ?>
                                    <div class="col-sm-7"><label class="form-label" for="decommission-date">Fecha de baja</label><input class="form-control" id="decommission-date" name="fecha_baja" type="date" required value="<?= date('Y-m-d') ?>"></div>
                                    <div class="col-sm-5 d-flex align-items-end"><button class="btn btn-outline-danger w-100" type="submit">Dar de baja</button></div>
                                    <div class="col-12"><p class="small text-muted mb-0">Se rechazará si el equipo tiene una orden de trabajo abierta.</p></div>
                                </form>
                            <?php else: ?>
                                <p class="text-muted mb-0">El equipo permanece disponible para consulta y auditoría.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <section class="card shadow-sm mb-4">
            <div class="card-header d-flex justify-content-between align-items-center gap-2"><h2 class="h5 mb-0">Relaciones entre equipos</h2><span class="badge text-bg-secondary"><?= esc($relationsTotal) ?></span></div>
            <div class="card-body">
                <?php if ($can['edit'] && $equipment['estado'] === 'ACTIVO'): ?>
                    <?php $relationOptions = array_values(array_filter($relatedCandidates, static fn (array $candidate): bool => (int) $candidate['id'] !== (int) $equipment['id'])); ?>
                    <?php if ($relationOptions !== []): ?>
                        <form method="post" action="<?= base_url('mantenimiento/equipos/' . $equipment['id'] . '/relaciones') ?>" class="row g-3 border-bottom pb-4 mb-4">
                            <?= csrf_field() ?>
                            <div class="col-md-4"><label class="form-label" for="related-equipment">Equipo relacionado</label><select class="form-select" id="related-equipment" name="equipo_relacionado_id" required><?php foreach ($relationOptions as $candidate): ?><option value="<?= esc($candidate['id']) ?>"><?= esc($candidate['codigo'] . ' · ' . $candidate['tipo_nombre']) ?></option><?php endforeach; ?></select></div>
                            <div class="col-md-3"><label class="form-label" for="relation-type">Tipo</label><select class="form-select" id="relation-type" name="tipo_relacion"><option value="TRACTOR_ACOPLADO">Tractor-acoplado</option><option value="OTRO">Otro</option></select></div>
                            <div class="col-md-3"><label class="form-label" for="relation-start">Desde</label><input class="form-control" id="relation-start" type="datetime-local" name="desde" required value="<?= date('Y-m-d\TH:i') ?>"></div>
                            <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100" type="submit">Relacionar</button></div>
                            <div class="col-12"><label class="form-label" for="relation-notes">Observaciones</label><input class="form-control" id="relation-notes" name="observaciones" maxlength="500"></div>
                        </form>
                    <?php else: ?><p class="text-muted">No hay otro equipo activo y autorizado disponible.</p><?php endif; ?>
                <?php endif; ?>
                <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Equipos</th><th>Tipo</th><th>Vigencia</th><th>Registro</th><th class="text-end">Acción</th></tr></thead><tbody>
                    <?php foreach ($relations as $relation): ?><tr>
                        <td><?= esc($relation['equipo_principal_codigo'] . ' ↔ ' . $relation['equipo_relacionado_codigo']) ?></td><td><?= esc($relation['tipo_relacion']) ?></td><td><?= esc($relation['desde']) ?><br><span class="small text-muted"><?= $relation['hasta'] === null ? 'Activa' : 'Hasta ' . esc($relation['hasta']) ?></span></td><td><?= esc($relation['usuario_nombre']) ?><?php if ($relation['observaciones']): ?><br><span class="small text-muted"><?= esc($relation['observaciones']) ?></span><?php endif; ?></td>
                        <td class="text-end"><?php if ($can['edit'] && $relation['hasta'] === null): ?><details class="d-inline-block text-start"><summary class="btn btn-sm btn-outline-warning">Finalizar</summary><form method="post" action="<?= base_url('mantenimiento/equipos/' . $equipment['id'] . '/relaciones/' . $relation['id'] . '/finalizar') ?>" class="border rounded bg-white p-3 mt-2" style="min-width:min(20rem,80vw)"><?= csrf_field() ?><label class="form-label small">Hasta</label><input class="form-control form-control-sm mb-2" type="datetime-local" name="hasta" required value="<?= date('Y-m-d\TH:i') ?>"><label class="form-label small">Observaciones de cierre</label><textarea class="form-control form-control-sm mb-2" name="observaciones_fin" maxlength="500"></textarea><button class="btn btn-warning btn-sm" type="submit">Finalizar relación</button></form></details><?php endif; ?></td>
                    </tr><?php endforeach; ?>
                    <?php if ($relations === []): ?><tr><td colspan="5" class="text-muted text-center py-4">No hay relaciones registradas.</td></tr><?php endif; ?>
                </tbody></table></div>
            </div>
            <?php if ($relationsTotalPages > 1): ?><div class="card-footer d-flex justify-content-between"><a class="btn btn-sm btn-outline-secondary <?= $relationsPage <= 1 ? 'disabled' : '' ?>" href="?<?= http_build_query(['page' => $readings?->page ?? 1, 'transfer_page' => $transferHistoryPage, 'attachment_page' => $attachments->page, 'relation_page' => max(1, $relationsPage - 1)]) ?>">Anterior</a><span class="small text-muted">Página <?= esc($relationsPage) ?> de <?= esc($relationsTotalPages) ?></span><a class="btn btn-sm btn-outline-secondary <?= $relationsPage >= $relationsTotalPages ? 'disabled' : '' ?>" href="?<?= http_build_query(['page' => $readings?->page ?? 1, 'transfer_page' => $transferHistoryPage, 'attachment_page' => $attachments->page, 'relation_page' => min($relationsTotalPages, $relationsPage + 1)]) ?>">Siguiente</a></div><?php endif; ?>
        </section>

        <section class="card shadow-sm mb-4">
            <div class="card-header d-flex justify-content-between align-items-center gap-2">
                <h2 class="h5 mb-0">Adjuntos privados</h2>
                <span class="badge text-bg-secondary"><?= esc($attachments->total) ?></span>
            </div>
            <div class="card-body">
                <?php if ($can['edit'] && $equipment['estado'] === 'ACTIVO'): ?>
                    <form method="post" enctype="multipart/form-data" action="<?= base_url('mantenimiento/equipos/' . $equipment['id'] . '/adjuntos') ?>" class="row g-3 border-bottom pb-4 mb-4">
                        <?= csrf_field() ?>
                        <div class="col-sm-4">
                            <label class="form-label" for="attachment-file">Archivo</label>
                            <input class="form-control" id="attachment-file" type="file" name="archivo" accept=".pdf,.jpg,.jpeg,.png,.webp" required>
                            <div class="form-text">PDF o imagen, hasta <?= esc((int) env('uploads.maxSizeMB', 10)) ?> MB.</div>
                        </div>
                        <div class="col-sm-3">
                            <label class="form-label" for="attachment-type">Tipo</label>
                            <select class="form-select" id="attachment-type" name="tipo" required>
                                <option value="DOCUMENTO">Documento</option>
                                <option value="FOTO">Foto</option>
                                <option value="MANUAL">Manual</option>
                                <option value="COMPROBANTE">Comprobante</option>
                                <option value="OTRO">Otro</option>
                            </select>
                        </div>
                        <div class="col-sm-5">
                            <label class="form-label" for="attachment-description">Descripción</label>
                            <input class="form-control" id="attachment-description" name="descripcion" maxlength="500">
                        </div>
                        <div class="col-12"><button class="btn btn-primary" type="submit">Subir adjunto</button></div>
                    </form>
                <?php endif; ?>

                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead><tr><th>Archivo</th><th>Tipo</th><th>Registro</th><th>Estado</th><th class="text-end">Acción</th></tr></thead>
                        <tbody>
                            <?php foreach ($attachments->items as $attachment): ?>
                                <tr class="<?= $attachment['retirado_at'] !== null ? 'table-light text-muted' : '' ?>">
                                    <td><?= esc($attachment['nombre_original']) ?><br><span class="small text-muted"><?= esc($attachment['mime_type']) ?> · <?= esc(number_format(((int) $attachment['tamanio']) / 1024, 1)) ?> KB</span></td>
                                    <td><?= esc($attachment['tipo']) ?><?php if ($attachment['descripcion']): ?><br><span class="small text-muted"><?= esc($attachment['descripcion']) ?></span><?php endif; ?></td>
                                    <td><?= esc($attachment['created_at']) ?><br><span class="small text-muted"><?= esc($attachment['created_by_nombre']) ?></span></td>
                                    <td>
                                        <?php if ($attachment['retirado_at'] === null): ?>
                                            <span class="badge text-bg-success">Activo</span>
                                        <?php else: ?>
                                            <span class="badge text-bg-secondary">Retirado</span>
                                            <div class="small mt-1"><?= esc($attachment['motivo_retiro']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($attachment['retirado_at'] === null): ?>
                                            <a class="btn btn-sm btn-outline-primary" href="<?= base_url('mantenimiento/equipos/' . $equipment['id'] . '/adjuntos/' . $attachment['id'] . '/descargar') ?>">Descargar</a>
                                            <?php if ($can['edit']): ?>
                                                <details class="d-inline-block text-start">
                                                    <summary class="btn btn-sm btn-outline-danger">Retirar</summary>
                                                    <form method="post" action="<?= base_url('mantenimiento/equipos/' . $equipment['id'] . '/adjuntos/' . $attachment['id'] . '/retirar') ?>" class="border rounded bg-white p-3 mt-2" style="min-width: min(20rem, 80vw)">
                                                        <?= csrf_field() ?>
                                                        <label class="form-label small">Motivo</label>
                                                        <textarea class="form-control form-control-sm mb-2" name="motivo" minlength="5" maxlength="255" required></textarea>
                                                        <button class="btn btn-danger btn-sm" type="submit">Confirmar retiro</button>
                                                    </form>
                                                </details>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($attachments->items === []): ?><tr><td colspan="5" class="text-muted text-center py-4">No hay adjuntos para este equipo.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php if ($attachments->totalPages() > 1): ?>
                <div class="card-footer d-flex justify-content-between align-items-center">
                    <a class="btn btn-sm btn-outline-secondary <?= $attachments->page <= 1 ? 'disabled' : '' ?>" href="<?= current_url() . '?' . http_build_query(['page' => $readings?->page ?? 1, 'transfer_page' => $transferHistoryPage, 'attachment_page' => max(1, $attachments->page - 1)]) ?>">Anterior</a>
                    <span class="small text-muted">Página <?= esc($attachments->page) ?> de <?= esc($attachments->totalPages()) ?></span>
                    <a class="btn btn-sm btn-outline-secondary <?= $attachments->page >= $attachments->totalPages() ? 'disabled' : '' ?>" href="<?= current_url() . '?' . http_build_query(['page' => $readings?->page ?? 1, 'transfer_page' => $transferHistoryPage, 'attachment_page' => min($attachments->totalPages(), $attachments->page + 1)]) ?>">Siguiente</a>
                </div>
            <?php endif; ?>
        </section>

        <section class="card shadow-sm mb-4">
            <div class="card-header d-flex justify-content-between align-items-center gap-2">
                <h2 class="h5 mb-0">Historial de lecturas</h2>
                <?php if ($readings !== null): ?><span class="badge text-bg-secondary"><?= esc($readings->total) ?></span><?php endif; ?>
            </div>
            <?php if ($readings === null): ?>
                <div class="card-body"><p class="text-muted mb-0">No tenés permiso para consultar lecturas.</p></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead><tr><th>Fecha</th><th>Valores</th><th>Origen y autor</th><th>Estado</th><th class="text-end">Acción</th></tr></thead>
                        <tbody>
                            <?php foreach ($readings->items as $reading): ?>
                                <tr class="<?= $reading->annulled ? 'table-light text-muted' : '' ?>">
                                    <td class="text-nowrap"><?= esc($reading->recordedAt->format('d/m/Y H:i')) ?></td>
                                    <td><span class="text-nowrap"><?= $reading->kilometers === null ? '—' : esc($reading->kilometers . ' km') ?></span><br><span class="text-nowrap"><?= $reading->hours === null ? '—' : esc($reading->hours . ' h') ?></span></td>
                                    <td><?= esc($reading->origin) ?><br><span class="small text-muted"><?= esc($reading->userName) ?> · sucursal #<?= esc($reading->branchId) ?></span></td>
                                    <td>
                                        <?php if ($reading->annulled): ?>
                                            <span class="badge text-bg-secondary">Anulada</span>
                                            <div class="small mt-1"><?= esc($reading->annulmentReason ?? '') ?></div>
                                            <?php if ($reading->replacementReadingId): ?><div class="small">Reemplazada por #<?= esc($reading->replacementReadingId) ?></div><?php endif; ?>
                                        <?php elseif ($reading->correctedReadingId): ?>
                                            <span class="badge text-bg-info">Corrección de #<?= esc($reading->correctedReadingId) ?></span>
                                            <div class="small mt-1"><?= esc($reading->correctionReason ?? '') ?></div>
                                        <?php else: ?>
                                            <span class="badge text-bg-success">Válida</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($can['correctReadings'] && ! $reading->annulled): ?>
                                            <details class="text-start d-inline-block">
                                                <summary class="btn btn-sm btn-outline-warning">Corregir</summary>
                                                <form method="post" action="<?= base_url('mantenimiento/equipos/' . $equipment['id'] . '/lecturas/' . $reading->id . '/corregir') ?>" class="border rounded bg-white p-3 mt-2" style="min-width: min(22rem, 80vw)">
                                                    <?= csrf_field() ?>
                                                    <div class="mb-2"><label class="form-label small">Kilómetros</label><input class="form-control form-control-sm" type="number" min="0" name="kilometraje" value="<?= esc($reading->kilometers) ?>" <?= (int) $equipment['controla_km'] !== 1 ? 'disabled' : '' ?>></div>
                                                    <div class="mb-2"><label class="form-label small">Horómetro</label><input class="form-control form-control-sm" type="text" inputmode="decimal" name="horometro" value="<?= esc($reading->hours) ?>" <?= (int) $equipment['controla_horas'] !== 1 ? 'disabled' : '' ?>></div>
                                                    <div class="mb-2"><label class="form-label small">Motivo obligatorio</label><textarea class="form-control form-control-sm" name="motivo" minlength="5" maxlength="255" rows="2" required></textarea></div>
                                                    <div class="mb-2"><label class="form-label small">Observaciones</label><textarea class="form-control form-control-sm" name="observaciones" rows="2"></textarea></div>
                                                    <button class="btn btn-warning btn-sm" type="submit">Guardar corrección auditada</button>
                                                </form>
                                            </details>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($readings->items === []): ?><tr><td colspan="5" class="text-muted text-center py-4">No hay lecturas registradas.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($readings->totalPages() > 1): ?>
                    <div class="card-footer d-flex justify-content-between align-items-center">
                        <a class="btn btn-sm btn-outline-secondary <?= $readings->page <= 1 ? 'disabled' : '' ?>" href="<?= current_url() . '?' . http_build_query(['page' => max(1, $readings->page - 1), 'transfer_page' => $transferHistoryPage]) ?>">Anterior</a>
                        <span class="small text-muted">Página <?= esc($readings->page) ?> de <?= esc($readings->totalPages()) ?></span>
                        <a class="btn btn-sm btn-outline-secondary <?= $readings->page >= $readings->totalPages() ? 'disabled' : '' ?>" href="<?= current_url() . '?' . http_build_query(['page' => min($readings->totalPages(), $readings->page + 1), 'transfer_page' => $transferHistoryPage]) ?>">Siguiente</a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <section class="card shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center gap-2"><h2 class="h5 mb-0">Historial de traslados</h2><span class="badge text-bg-secondary"><?= esc($transferHistoryTotal) ?></span></div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>Fecha</th><th>Origen</th><th>Destino</th><th>Motivo</th><th>Registró</th></tr></thead>
                    <tbody>
                        <?php foreach ($transferHistory as $movement): ?><tr><td class="text-nowrap"><?= esc($movement['fecha_movimiento']) ?></td><td><?= esc($movement['sucursal_origen_codigo'] . ' · ' . $movement['sucursal_origen_nombre']) ?></td><td><?= esc($movement['sucursal_destino_codigo'] . ' · ' . $movement['sucursal_destino_nombre']) ?></td><td><?= esc($movement['motivo']) ?></td><td><?= esc($movement['usuario_nombre']) ?></td></tr><?php endforeach; ?>
                        <?php if ($transferHistory === []): ?><tr><td colspan="5" class="text-muted text-center py-4">Este equipo todavía no fue trasladado.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($transferHistoryTotalPages > 1): ?>
                <div class="card-footer d-flex justify-content-between align-items-center">
                    <a class="btn btn-sm btn-outline-secondary <?= $transferHistoryPage <= 1 ? 'disabled' : '' ?>" href="<?= current_url() . '?' . http_build_query(['page' => $readings?->page ?? 1, 'transfer_page' => max(1, $transferHistoryPage - 1)]) ?>">Anterior</a>
                    <span class="small text-muted">Página <?= esc($transferHistoryPage) ?> de <?= esc($transferHistoryTotalPages) ?></span>
                    <a class="btn btn-sm btn-outline-secondary <?= $transferHistoryPage >= $transferHistoryTotalPages ? 'disabled' : '' ?>" href="<?= current_url() . '?' . http_build_query(['page' => $readings?->page ?? 1, 'transfer_page' => min($transferHistoryTotalPages, $transferHistoryPage + 1)]) ?>">Siguiente</a>
                </div>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
