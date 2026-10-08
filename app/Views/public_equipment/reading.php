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
        input,textarea{box-sizing:border-box;width:100%;font-size:1.15rem;padding:13px;border:1px solid #cbd5e1;border-radius:10px}\n        input[type=file]{font-size:1rem;background:#f8fafc}
        button{width:100%;margin-top:20px;padding:15px;border:0;border-radius:11px;font-size:1.1rem;font-weight:700;cursor:pointer}
        .msg{padding:12px;border-radius:10px;margin:12px 0}.ok{background:#dcfce7}.err{background:#fee2e2}
        .reading{font-size:.95rem;background:#f8fafc;padding:12px;border-radius:10px}
        .help{font-size:.9rem;color:#64748b;margin:6px 0 0;line-height:1.4}
        .done{text-align:center;padding:18px 8px 6px}.done h2{margin:0 0 8px;font-size:1.35rem;color:#166534}.done p{margin:0;color:#475569;line-height:1.5}
        button[disabled]{opacity:.65;cursor:wait}.ai-status{margin-top:10px;padding:10px 12px;border-radius:10px;background:#eef2ff;color:#3730a3;font-size:.9rem;line-height:1.4}.ai-status.error{background:#fff7ed;color:#9a3412}
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
        <form method="post" id="reading-form" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="request_key" value="<?= esc($requestKey) ?>">
            <input type="hidden" id="evidence_ref" name="evidence_ref" value="<?= esc(old('evidence_ref')) ?>">
            <label for="evidence_photo"><?= esc($labels['photo'] ?? 'Foto obligatoria del tablero') ?></label>
            <input id="evidence_photo" name="evidence_photo" type="file" accept="image/jpeg,image/png" capture="environment" required>
            <p class="help"><?= esc($labels['photo_help'] ?? 'Sacá una foto nítida donde se vea el odómetro. La foto quedará guardada como evidencia.') ?></p>
            <div id="ai-status" class="ai-status" hidden role="status" aria-live="polite"></div>

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
            const submit = document.getElementById('reading-submit');
            const button = submit;
            const photo = document.getElementById('evidence_photo');
            const km = document.getElementById('kilometers');
            const status = document.getElementById('ai-status');
            const evidenceRef = document.getElementById('evidence_ref');
            if (!form || !submit || !photo) return;

            const analysisUrl = <?= json_encode(base_url('mantenimiento/publico/equipo/' . rawurlencode($token) . '/lectura/analizar'), JSON_UNESCAPED_SLASHES) ?>;
            let evidenceReady = Boolean(evidenceRef && evidenceRef.value);

            function setSubmitAvailable(available) {
                submit.disabled = !available;
                if (available) {
                    submit.textContent = submit.dataset.defaultLabel || submit.textContent;
                }
            }

            if (submit && !submit.dataset.defaultLabel) {
                submit.dataset.defaultLabel = submit.textContent;
            }
            if (evidenceReady) {
                photo.required = false;
            }

            async function analyzePhoto() {
                const file = photo.files && photo.files[0];
                if (!file) return;

                evidenceReady = false;
                if (evidenceRef) evidenceRef.value = '';
                photo.required = true;
                setSubmitAvailable(false);

                status.hidden = false;
                status.classList.remove('error');
                status.textContent = <?= json_encode($labels['analyzing'] ?? 'Analizando foto...') ?>;

                const data = new FormData();
                data.append('evidence_photo', file);
                const csrfInput = form.querySelector('input[type="hidden"][name]:not([name="request_key"])');
                if (csrfInput) data.append(csrfInput.name, csrfInput.value);

                try {
                    const response = await fetch(analysisUrl, {
                        method: 'POST',
                        body: data,
                        headers: {'Accept': 'application/json'},
                        credentials: 'same-origin',
                    });
                    const payload = await response.json();

                    if (payload.csrfToken && payload.csrfHash) {
                        const current = form.querySelector('input[name="' + payload.csrfToken + '"]');
                        if (current) current.value = payload.csrfHash;
                    }

                    if (!response.ok || !payload.ok || !payload.evidenceRef) {
                        throw new Error(payload.error || 'No pudimos procesar la foto.');
                    }

                    if (evidenceRef) {
                        evidenceRef.value = payload.evidenceRef;
                    }
                    evidenceReady = true;
                    photo.required = false;
                    setSubmitAvailable(true);

                    if (!response.ok || !payload.ok) {
                        throw new Error(payload.error || 'No pudimos procesar la foto.');
                    }

                    if (!payload.legible || payload.kilometers == null) {
                        status.classList.add('error');
                        status.textContent = payload.analysisError || 'No pudimos leer automáticamente el odómetro. Podés escribir el valor manualmente. La foto YA quedó guardada como evidencia.';
                        return;
                    }

                    if (km) {
                        km.value = String(payload.kilometers);
                        km.focus();
                        km.select();
                    }
                    const confidence = payload.confidence == null ? '' : ' · confianza ' + Math.round(payload.confidence * 100) + '%';
                    status.textContent = 'IA detectó ' + Number(payload.kilometers).toLocaleString('es-AR') + ' km' + confidence + '. Revisá el valor y corregilo si hace falta.';
                } catch (error) {
                    evidenceReady = false;
                    if (evidenceRef) evidenceRef.value = '';
                    photo.required = true;
                    setSubmitAvailable(false);
                    status.classList.add('error');
                    status.textContent = (error && error.message ? error.message : 'No pudimos procesar la foto.') + ' Volvé a seleccionar o tomar una foto antes de registrar.';
                }
            }

            photo.addEventListener('change', analyzePhoto);

            form.addEventListener('submit', (event) => {
                const hasDirectPhoto = Boolean(photo.files && photo.files[0]);
                if (!evidenceReady && !hasDirectPhoto) {
                    event.preventDefault();
                    status.hidden = false;
                    status.classList.add('error');
                    status.textContent = 'Esperá a que la foto quede guardada como evidencia antes de registrar.';
                    return;
                }

                button.disabled = true;
                button.textContent = button.dataset.savingLabel || 'Guardando...';
            });
        })();
        </script>
        <?php endif ?>
    </section>
</main>
</body>
</html>
