<?php
// Página que abre el chofer al escanear el QR del equipo, sin sesión. HTML del servidor con la
// hoja de estilos de la app (partials/public_page_head.php); el JS del flujo de la foto es propio.
// Las clases se repiten a mano: Tailwind las encuentra porque frontend/tailwind.config.js lee esta carpeta.
// La píldora va solo en los campos de una línea; el área de texto usa rounded-2xl.
$fieldClass = 'min-h-12 w-full [&:not(textarea)]:rounded-pill border border-transparent bg-surface-muted px-4 py-2 text-lg text-ink placeholder:text-ink-subtle focus:border-border-focus focus:outline-none focus:ring-2 focus:ring-primary/15 aria-[invalid=true]:border-danger';
$fileClass  = 'w-full rounded-2xl bg-surface-muted p-2 text-sm text-ink file:mr-3 file:rounded-pill file:border-0 file:bg-action file:px-4 file:py-2 file:text-sm file:font-semibold file:text-action-foreground';
$labelClass = 'mb-1.5 mt-6 block text-sm font-semibold text-ink';
$helpClass  = 'mt-1.5 text-sm leading-snug text-ink-muted';
?>
<?= view('partials/public_page_head', ['locale' => $locale ?? 'ES', 'title' => $labels['title'] ?? 'Registrar lectura']) ?>
<style>
    /* El JS del flujo de la foto marca los problemas con la clase "error". */
    .ai-status.error { background: rgb(var(--warning-subtle)); color: rgb(var(--warning-foreground)); }
</style>
<main class="mx-auto max-w-lg px-4 py-6">
    <section class="rounded-card bg-surface-raised p-5 shadow-card sm:p-6">
        <p class="text-xs font-bold uppercase tracking-wide text-primary"><?= esc($labels['title'] ?? 'Registrar lectura') ?></p>
        <h1 class="mt-1 text-2xl font-bold text-ink-strong"><?= esc($equipment['codigo']) ?></h1>
        <p class="mt-0.5 text-sm text-ink-muted"><?= esc($equipment['patente'] ?? $equipment['tipo_nombre']) ?></p>

        <?php if ($success): ?><div class="mt-4 rounded-2xl bg-success-subtle px-4 py-3 text-sm font-medium text-success-strong" role="status"><?= esc($success) ?></div><?php endif ?>
        <?php if ($error): ?><div class="mt-4 rounded-2xl bg-danger-subtle px-4 py-3 text-sm font-medium text-danger-strong" role="alert"><?= esc($error) ?></div><?php endif ?>

        <div class="mt-4 space-y-1 rounded-2xl bg-surface-muted px-4 py-3 text-sm text-ink">
            <?php if ($equipment['km_actual'] !== null): ?>
                <div><?= esc($labels['last_km'] ?? 'Último kilometraje') ?>: <strong class="tabular-nums"><?= number_format((int) $equipment['km_actual'], 0, ',', '.') ?> km</strong></div>
            <?php endif ?>
            <?php if ($equipment['horas_actuales'] !== null): ?>
                <div><?= esc($labels['last_hours'] ?? 'Último horómetro') ?>: <strong class="tabular-nums"><?= esc($equipment['horas_actuales']) ?> h</strong></div>
            <?php endif ?>
        </div>

        <?php if (! empty($registered)): ?>
            <div class="pt-6 text-center" role="status">
                <h2 class="text-xl font-bold text-success-strong"><?= esc($labels['registered_title'] ?? 'Lectura registrada') ?></h2>
                <p class="mt-2 text-sm leading-relaxed text-ink-muted"><?= esc($labels['registered_help'] ?? 'La lectura quedó guardada correctamente. Ya podés cerrar esta ventana.') ?></p>
                <?php if (! empty($readingReceipt)): ?>
                    <div class="mt-4 space-y-1 rounded-2xl bg-surface-muted px-4 py-3 text-left text-sm text-ink">
                        <?php if (($readingReceipt['kilometers'] ?? null) !== null): ?>
                            <div><?= esc($labels['receipt_km'] ?? 'Kilometraje registrado') ?>:
                                <strong class="tabular-nums"><?= number_format((int) $readingReceipt['kilometers'], 0, ',', '.') ?> km</strong>
                            </div>
                        <?php endif ?>
                        <?php if (($readingReceipt['hours'] ?? null) !== null): ?>
                            <div><?= esc($labels['receipt_hours'] ?? 'Horómetro registrado') ?>:
                                <strong class="tabular-nums"><?= number_format((float) $readingReceipt['hours'], 1, ',', '.') ?> h</strong>
                            </div>
                        <?php endif ?>
                        <div><?= esc($labels['receipt_code'] ?? 'Comprobante') ?>:
                            <strong><?= esc($readingReceipt['code'] ?? '') ?></strong>
                        </div>
                        <p class="<?= $helpClass ?>"><?= esc($labels['receipt_help'] ?? 'Guardá este comprobante.') ?></p>
                    </div>
                <?php endif ?>
            </div>
        <?php else: ?>
        <form method="post" id="reading-form" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="request_key" value="<?= esc($requestKey) ?>">
            <input type="hidden" id="evidence_ref" name="evidence_ref" value="<?= esc(old('evidence_ref')) ?>">
            <label for="evidence_photo" class="<?= $labelClass ?>"><?= esc($labels['photo'] ?? 'Foto obligatoria del tablero') ?></label>
            <input id="evidence_photo" name="evidence_photo" type="file" accept="image/jpeg,image/png" capture="environment" required class="<?= $fileClass ?>">
            <p class="<?= $helpClass ?>"><?= esc($labels['photo_help'] ?? 'Sacá una foto nítida donde se vea el odómetro. La foto quedará guardada como evidencia.') ?></p>
            <!-- Sin clases de display: le ganarían al atributo hidden que maneja el JS. -->
            <div id="evidence-ready" class="mt-3 rounded-2xl bg-success-subtle px-4 py-3 text-sm font-semibold text-success-strong" hidden>Foto guardada como evidencia ✓</div>
            <img id="evidence-preview" class="mt-3 max-h-56 w-full rounded-2xl bg-surface-muted object-contain" hidden alt="Vista previa de la evidencia">
            <div id="ai-status" class="ai-status mt-3 rounded-2xl bg-info-subtle px-4 py-3 text-sm leading-snug text-info-strong" hidden role="status" aria-live="polite"></div>

            <?php if ((int) $equipment['controla_km'] === 1): ?>
                <label for="kilometers" class="<?= $labelClass ?>"><?= esc($labels['current_km'] ?? 'Kilómetros actuales') ?></label>
                <input id="kilometers" name="kilometers" type="number" inputmode="numeric" min="0"
                       value="<?= esc(old('kilometers')) ?>" required autofocus class="<?= $fieldClass ?> tabular-nums">
                <p class="<?= $helpClass ?>"><?= esc($labels['current_km_help'] ?? 'Ejemplo: si el tablero muestra 494497, escribí 494497.') ?></p>
            <?php endif ?>

            <?php if ((int) $equipment['controla_horas'] === 1): ?>
                <label for="hours" class="<?= $labelClass ?>"><?= esc($labels['current_hours'] ?? 'Horas actuales') ?></label>
                <input id="hours" name="hours" type="number" inputmode="decimal" min="0" step="0.1"
                       value="<?= esc(old('hours')) ?>" required class="<?= $fieldClass ?> tabular-nums">
            <?php endif ?>

            <label for="notes" class="<?= $labelClass ?>"><?= esc($labels['notes'] ?? 'Observación (opcional)') ?></label>
            <textarea id="notes" name="notes" rows="3" maxlength="500" class="<?= $fieldClass ?> rounded-2xl py-3 text-base"><?= esc(old('notes')) ?></textarea>

            <?php if ($largeJump): ?>
                <label class="mt-5 flex items-start gap-3 rounded-2xl bg-warning-subtle px-4 py-3 text-sm font-medium text-warning-foreground">
                    <input type="checkbox" name="confirm_large_jump" value="1" required class="mt-0.5 size-5 shrink-0">
                    <?= esc($labels['confirm_jump'] ?? 'Confirmo que revisé el valor y es correcto.') ?>
                </label>
            <?php endif ?>

            <button type="submit" id="reading-submit" class="ui-interactive mt-7 inline-flex min-h-12 w-full items-center justify-center rounded-pill bg-action px-5 py-3 text-base font-semibold text-action-foreground hover:bg-action/90 active:bg-action/80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-border-focus focus-visible:ring-offset-2 disabled:cursor-wait disabled:opacity-60" data-saving-label="<?= esc($labels['saving'] ?? 'Guardando...') ?>"><?= esc($labels['submit'] ?? 'Registrar lectura') ?></button>
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
            const evidenceReadyBox = document.getElementById('evidence-ready');
            const evidencePreview = document.getElementById('evidence-preview');
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
                evidenceReadyBox.hidden = false;
                status.hidden = false;
                status.classList.remove('error');
                status.textContent = 'La foto ya quedó guardada. Podés registrar la lectura sin volver a seleccionarla.';
            }

            async function optimizePhoto(file) {
                if (!file || !file.type.startsWith('image/')) return file;
                if (file.size <= 1500000) return file;

                try {
                    const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
                    const maxSide = 1920;
                    const scale = Math.min(1, maxSide / Math.max(bitmap.width, bitmap.height));
                    const width = Math.max(1, Math.round(bitmap.width * scale));
                    const height = Math.max(1, Math.round(bitmap.height * scale));
                    const canvas = document.createElement('canvas');
                    canvas.width = width;
                    canvas.height = height;
                    const ctx = canvas.getContext('2d', { alpha: false });
                    if (!ctx) return file;
                    ctx.drawImage(bitmap, 0, 0, width, height);
                    bitmap.close?.();

                    const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.84));
                    if (!blob) return file;
                    return new File([blob], 'odometro.jpg', { type: 'image/jpeg', lastModified: Date.now() });
                } catch (_) {
                    return file;
                }
            }

            async function analyzePhoto() {
                const file = photo.files && photo.files[0];
                if (!file) return;

                if (evidencePreview) {
                    evidencePreview.src = URL.createObjectURL(file);
                    evidencePreview.hidden = false;
                }
                evidenceReadyBox.hidden = true;
                evidenceReady = false;
                if (evidenceRef) evidenceRef.value = '';
                photo.required = true;
                setSubmitAvailable(false);

                status.hidden = false;
                status.classList.remove('error');
                status.textContent = 'Preparando foto...';

                const uploadFile = await optimizePhoto(file);
                status.textContent = <?= json_encode($labels['analyzing'] ?? 'Analizando foto...') ?>;

                const data = new FormData();
                data.append('evidence_photo', uploadFile, uploadFile.name || 'odometro.jpg');
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

                    if (!response.ok || !payload.ok) {
                        throw new Error(payload.error || 'No pudimos procesar la foto.');
                    }

                    if (payload.evidenceValid === false && payload.evidenceError) {
                        evidenceReady = false;
                        if (evidenceRef) evidenceRef.value = '';
                        photo.required = true;
                        evidenceReadyBox.hidden = true;
                        setSubmitAvailable(false);
                        status.classList.add('error');
                        status.textContent = payload.evidenceError;
                        return;
                    }

                    if (payload.evidenceRef) {
                        if (evidenceRef) {
                            evidenceRef.value = payload.evidenceRef;
                        }
                        evidenceReady = true;
                        photo.required = false;
                        photo.value = '';
                        evidenceReadyBox.hidden = false;
                        setSubmitAvailable(true);
                    } else {
                        // El análisis pudo funcionar aunque el staging del servidor
                        // no haya quedado listo. Conservamos la foto seleccionada
                        // y permitimos que el POST final la envíe directamente.
                        evidenceReady = false;
                        photo.required = true;
                        evidenceReadyBox.hidden = true;
                        setSubmitAvailable(true);
                    }

                    if (!payload.legible || payload.kilometers == null) {
                        status.classList.add('error');
                        status.textContent = payload.analysisError || 'No pudimos leer automáticamente el odómetro. Podés escribir el valor manualmente.';
                        if (!payload.evidenceRef) {
                            status.textContent += ' La foto se enviará al registrar la lectura.';
                        }
                        return;
                    }

                    if (km) {
                        km.value = String(payload.kilometers);
                        km.focus();
                        km.select();
                    }
                    const confidence = payload.confidence == null ? '' : ' · confianza ' + Math.round(payload.confidence * 100) + '%';
                    status.textContent = 'IA detectó ' + Number(payload.kilometers).toLocaleString('es-AR') + ' km' + confidence + '. Revisá el valor y corregilo si hace falta.';
                    if (!payload.evidenceRef) {
                        status.textContent += ' La foto se guardará junto con la lectura al registrar.';
                    }
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
                    status.textContent = 'Seleccioná o sacá una foto antes de registrar.';
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
