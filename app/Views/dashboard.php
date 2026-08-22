<?php

declare(strict_types=1);

$manifestPath = FCPATH . 'assets/dashboard/.vite/manifest.json';
$manifest = is_file($manifestPath)
    ? json_decode((string) file_get_contents($manifestPath), true)
    : null;
$entry = is_array($manifest) ? ($manifest['src/main.js'] ?? null) : null;

if (! is_array($entry) || ! isset($entry['file'])) {
    throw new RuntimeException('No se encontro el bundle del dashboard. Ejecute npm run build dentro de frontend/.');
}

$dashboardJson = json_encode(
    $dashboardPayload,
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
);
header('Cache-Control: no-store, must-revalidate');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#031A3E">
    <meta name="color-scheme" content="light">
    <title>Panel de mantenimiento</title>
    <meta name="csrf-token" content="<?= esc(csrf_hash(), 'attr') ?>">
    <?php foreach (($entry['css'] ?? []) as $stylesheet): ?>
        <link rel="stylesheet" href="<?= esc(base_url('assets/dashboard/' . $stylesheet), 'attr') ?>">
    <?php endforeach; ?>
</head>
<body>
    <div id="maintenance-dashboard"></div>
    <noscript>
        <p>Este panel necesita JavaScript habilitado para funcionar.</p>
    </noscript>
    <script id="maintenance-dashboard-data" type="application/json"><?= $dashboardJson ?></script>
    <script type="module" src="<?= esc(base_url('assets/dashboard/' . $entry['file']), 'attr') ?>?v=<?= esc($entry['file'], 'attr') ?>"></script>
</body>
</html>
