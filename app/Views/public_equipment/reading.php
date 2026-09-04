<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Registrar lectura</title>
    <style>
        body{font-family:system-ui,-apple-system,sans-serif;background:#f5f6f8;margin:0;color:#1f2937}
        main{max-width:520px;margin:0 auto;padding:24px 16px}
        .card{background:#fff;border-radius:18px;padding:22px;box-shadow:0 8px 28px rgba(0,0,0,.08)}
        h1{font-size:1.5rem;margin:0 0 6px}.muted{color:#6b7280;margin:0 0 18px}
        label{display:block;font-weight:600;margin:16px 0 6px}
        input,textarea{box-sizing:border-box;width:100%;font-size:1.15rem;padding:13px;border:1px solid #cbd5e1;border-radius:10px}
        button{width:100%;margin-top:20px;padding:15px;border:0;border-radius:11px;font-size:1.1rem;font-weight:700;cursor:pointer}
        .msg{padding:12px;border-radius:10px;margin:12px 0}.ok{background:#dcfce7}.err{background:#fee2e2}
        .reading{font-size:.95rem;background:#f8fafc;padding:12px;border-radius:10px}
    </style>
</head>
<body>
<main>
    <section class="card">
        <h1><?= esc($equipment['codigo']) ?></h1>
        <p class="muted"><?= esc($equipment['patente'] ?? $equipment['tipo_nombre']) ?></p>

        <?php if ($success): ?><div class="msg ok"><?= esc($success) ?></div><?php endif ?>
        <?php if ($error): ?><div class="msg err"><?= esc($error) ?></div><?php endif ?>

        <div class="reading">
            <?php if ($equipment['km_actual'] !== null): ?>
                Último kilometraje: <strong><?= number_format((int) $equipment['km_actual'], 0, ',', '.') ?> km</strong><br>
            <?php endif ?>
            <?php if ($equipment['horas_actuales'] !== null): ?>
                Último horómetro: <strong><?= esc($equipment['horas_actuales']) ?> h</strong>
            <?php endif ?>
        </div>

        <form method="post">
            <?= csrf_field() ?>
            <?php if ((int) $equipment['controla_km'] === 1): ?>
                <label for="kilometers">Kilómetros actuales</label>
                <input id="kilometers" name="kilometers" type="number" inputmode="numeric" min="0"
                       value="<?= esc(old('kilometers')) ?>" required autofocus>
            <?php endif ?>

            <?php if ((int) $equipment['controla_horas'] === 1): ?>
                <label for="hours">Horas actuales</label>
                <input id="hours" name="hours" type="number" inputmode="decimal" min="0" step="0.1"
                       value="<?= esc(old('hours')) ?>" required>
            <?php endif ?>

            <label for="notes">Observación (opcional)</label>
            <textarea id="notes" name="notes" rows="3" maxlength="500"><?= esc(old('notes')) ?></textarea>

            <button type="submit">Registrar lectura</button>
        </form>
    </section>
</main>
</body>
</html>
