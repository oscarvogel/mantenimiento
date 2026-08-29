<?php
/** @var array<string,mixed> $order */
$esc = static fn (mixed $value): string => esc((string) ($value ?? ''));
$fmtNumber = static fn (mixed $value, int $decimals = 0): string => $value === null || $value === '' ? '—' : number_format((float) $value, $decimals, ',', '.');
$fmtMoney = static fn (mixed $value): string => '$ ' . number_format((float) ($value ?? 0), 2, ',', '.');
$fmtDate = static function (mixed $value): string {
    if ($value === null || $value === '') { return '—'; }
    try { return (new DateTimeImmutable((string) $value))->format('d/m/Y H:i'); } catch (Throwable) { return (string) $value; }
};
$isCorrective = ($order['origen'] ?? '') === 'CORRECTIVO';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>OT <?= $esc($order['numero'] ?? $order['id']) ?></title>
    <style>
        :root { color-scheme: light; font-family: Arial, Helvetica, sans-serif; color: #111827; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f3f4f6; }
        .toolbar { display: flex; justify-content: flex-end; gap: 8px; padding: 12px; max-width: 960px; margin: 0 auto; }
        .toolbar button { border: 0; border-radius: 8px; padding: 10px 16px; font-weight: 700; cursor: pointer; }
        .sheet { width: min(210mm, calc(100% - 24px)); min-height: 270mm; margin: 0 auto 24px; background: #fff; padding: 14mm; box-shadow: 0 2px 16px rgba(0,0,0,.08); }
        .header { display: flex; justify-content: space-between; gap: 24px; border-bottom: 2px solid #111827; padding-bottom: 12px; }
        h1 { margin: 0; font-size: 24px; }
        .number { font-size: 18px; font-weight: 800; text-align: right; }
        .meta { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px 24px; margin: 18px 0; font-size: 13px; }
        .meta div { border-bottom: 1px solid #d1d5db; padding: 6px 0; }
        .meta strong { display: inline-block; min-width: 110px; }
        h2 { font-size: 15px; margin: 20px 0 8px; text-transform: uppercase; letter-spacing: .04em; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { border: 1px solid #9ca3af; padding: 8px; vertical-align: top; }
        th { background: #f3f4f6; text-align: left; }
        .notes { min-height: 64px; border: 1px solid #9ca3af; padding: 8px; white-space: pre-wrap; font-size: 13px; }
        .costs { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px; font-size: 12px; }
        .costs div { border: 1px solid #9ca3af; padding: 8px; }
        .costs strong { display: block; margin-bottom: 4px; }
        .signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 48px; margin-top: 48px; font-size: 12px; text-align: center; }
        .signature { border-top: 1px solid #111827; padding-top: 6px; }
        @media print {
            @page { size: A4; margin: 10mm; }
            body { background: #fff; }
            .toolbar { display: none; }
            .sheet { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
        }
        @media (max-width: 640px) {
            .meta, .costs { grid-template-columns: 1fr; }
            .sheet { padding: 20px; }
        }
    </style>
</head>
<body>
    <div class="toolbar"><button type="button" onclick="window.print()">Imprimir OT</button></div>
    <main class="sheet">
        <header class="header">
            <div>
                <h1>Orden de trabajo</h1>
                <div><?= $esc($isCorrective ? 'Correctiva' : ($order['servicio_nombre'] ?? 'Mantenimiento preventivo')) ?></div>
            </div>
            <div class="number">
                <?= $esc($order['numero'] ?? ('#' . $order['id'])) ?><br>
                <small><?= $esc($order['estado'] ?? '') ?></small>
            </div>
        </header>

        <section class="meta">
            <div><strong>Tipo</strong> <?= $esc($isCorrective ? 'CORRECTIVA' : 'PREVENTIVA') ?></div>
            <div><strong>Equipo</strong> <?= $esc($order['equipo_codigo'] ?? '') ?></div>
            <div><strong>Patente</strong> <?= $esc($order['equipo_patente'] ?: '—') ?></div>
            <div><strong>Chasis</strong> <?= $esc($order['equipo_chasis'] ?: '—') ?></div>
            <div><strong>Sucursal</strong> <?= $esc($order['sucursal_nombre'] ?? '') ?></div>
            <div><strong>Responsable</strong> <?= $esc($order['responsable_nombre'] ?: 'Sin asignar') ?></div>
            <div><strong>Prioridad</strong> <?= $esc($order['prioridad'] ?? '') ?></div>
            <div><strong>Apertura</strong> <?= $esc($fmtDate($order['fecha_apertura'] ?? null)) ?></div>
            <div><strong>Inicio</strong> <?= $esc($fmtDate($order['fecha_inicio'] ?? null)) ?></div>
            <div><strong>Finalización</strong> <?= $esc($fmtDate($order['fecha_finalizacion'] ?? null)) ?></div>
            <div><strong>Km ingreso / salida</strong> <?= $esc($fmtNumber($order['km_ingreso'] ?? null)) ?> / <?= $esc($fmtNumber($order['km_salida'] ?? null)) ?></div>
            <div><strong>Horas ingreso / salida</strong> <?= $esc($fmtNumber($order['horas_ingreso'] ?? null, 1)) ?> / <?= $esc($fmtNumber($order['horas_salida'] ?? null, 1)) ?></div>
        </section>

        <?php if ($isCorrective): ?>
            <h2>Problema reportado / diagnóstico</h2>
            <div class="notes"><?= $esc($order['diagnostico'] ?: '') ?></div>

            <h2>Trabajo realizado</h2>
            <div class="notes"><?= $esc($order['trabajo_realizado'] ?: 'Pendiente de completar') ?></div>
        <?php else: ?>
            <h2>Tareas del servicio</h2>
            <table>
                <thead><tr><th style="width:42px">#</th><th>Tarea</th><th style="width:110px">Estado</th><th>Trabajo realizado</th></tr></thead>
                <tbody>
                <?php if (($order['tasks'] ?? []) === []): ?>
                    <tr><td colspan="4">La orden no tiene tareas cargadas.</td></tr>
                <?php else: ?>
                    <?php foreach ($order['tasks'] as $index => $task): ?>
                        <tr>
                            <td><?= $index + 1 ?></td>
                            <td><?= $esc($task['description'] ?? '') ?><?= ! empty($task['required']) ? ' *' : '' ?></td>
                            <td><?= $esc($task['status'] ?? '') ?></td>
                            <td><?= $esc($task['workDone'] ?: '') ?></td>
                        </tr>
                    <?php endforeach ?>
                <?php endif ?>
                </tbody>
            </table>
        <?php endif ?>

        <h2>Costos del servicio</h2>
        <section class="costs">
            <div><strong>Mano de obra</strong><?= $esc($fmtMoney($order['costo_mano_obra'] ?? 0)) ?></div>
            <div><strong>Repuestos / insumos</strong><?= $esc($fmtMoney($order['costo_repuestos'] ?? 0)) ?></div>
            <div><strong>Otros costos</strong><?= $esc($fmtMoney($order['otros_costos'] ?? 0)) ?></div>
            <div><strong>Total</strong><?= $esc($fmtMoney($order['costo_total'] ?? 0)) ?></div>
        </section>

        <h2>Observaciones</h2>
        <div class="notes"><?= $esc($order['observaciones'] ?: '') ?></div>

        <div class="signatures">
            <div class="signature">Responsable / Taller</div>
            <div class="signature">Conformidad</div>
        </div>
    </main>
</body>
</html>
