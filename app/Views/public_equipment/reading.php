<!doctype html>
<html lang="<?= esc(strtolower($locale ?? 'ES')) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= esc($labels['title'] ?? 'Registrar lectura') ?></title>
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
        .help{font-size:.9rem;color:#64748b;margin:6px 0 0;line-height:1.4}
        .done{text-align:center;padding:18px 8px 6px}.done h2{margin:0 0 8px;font-size:1.35rem;color:#166534}.done p{margin:0;color:#475569;line-height:1.5}
        button[disabled]{opacity:.65;cursor:wait}
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
                <?= esc($labels['last_km'] ?? 'Último kilometraje') ?>: <strong><?= number_format((int) $equipment['km_actual'], 0, ',', '.') ?> km</strong><br>
            <?php endif ?>
            <?php if ($equipment['horas_actuales'] !== null): ?>
                <?= esc($labels['last_hours'] ?? 'Último horómetro') ?>: <strong><?= esc($equipment['horas_actuales']) ?> h</strong>
            <?php endif ?>
        </div>

        <?php if (! empty($registered)): ?>
            <div class="done" role="status">
                <h2><?= esc($labels['registered_title'] ?? 'Lectura registrada') ?></h2>
                <p><?= esc($labels['registered_help'] ?? 'La lectura quedó guardada correctamente. Ya podés cerrar esta ventana.') ?></p>
            </div>
        <?php else: ?>
        <form method="post" id="reading-form">
            <?= csrf_field() ?>
            <input type="hidden" name="request_key" value="<?= esc($requestKey) ?>">
            <?php if ((int) $equipment['controla_km'] === 1): ?>
                <label for="kilometers"><?= esc($labels['current_km'] ?? 'Kilómetros actuales') ?></label>
                <input id="kilometers" name="kilometers" type="number" inputmode="numeric" min="0"
                       value="<?= esc(old('kilometers')) ?>" required autofocus>
                <p class="help"><?= esc($labels['current_km_help'] ?? 'Ejemplo: si el tablero muestra 494497, escribí 494497.') ?></p>
            <?php endif ?>

            <?php if ((int) $equipment['controla_horas'] === 1): ?>
                <label for="hours"><?= esc($labels['current_hours'] ?? 'Horas actuales') ?></label>
                <input id="hours" name="hours" type="number" inputmode="decimal" min="0" step="0.1"
                       value="<?= esc(old('hours')) ?>" required>
            <?php endif ?>

            <label for="notes"><?= esc($labels['notes'] ?? 'Observación (opcional)') ?></label>
            <textarea id="notes" name="notes" rows="3" maxlength="500"><?= esc(old('notes')) ?></textarea>

            <?php if ($largeJump): ?>
                <label style="font-weight:500">
                    <input type="checkbox" name="confirm_large_jump" value="1" required style="width:auto">
                    <?= esc($labels['confirm_jump'] ?? 'Confirmo que revisé el valor y es correcto.') ?>
                </label>
            <?php endif ?>

            <button type="submit" id="reading-submit" data-saving-label="<?= esc($labels['saving'] ?? 'Guardando...') ?>"><?= esc($labels['submit'] ?? 'Registrar lectura') ?></button>
        </form>
        <script>
        (() => {
            const form = document.getElementById('reading-form');
            const button = document.getElementById('reading-submit');
            if (!form || !button) return;
            form.addEventListener('submit', () => {
                button.disabled = true;
                button.textContent = button.dataset.savingLabel || 'Guardando...';
            }, { once: true });
        })();
        </script>
        <?php endif ?>
    </section>
</main>
</body>
</html>
