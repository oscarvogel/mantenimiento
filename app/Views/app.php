<?php

declare(strict_types=1);

$manifestPath = FCPATH . 'assets/dashboard/.vite/manifest.json';
$manifest = is_file($manifestPath)
    ? json_decode((string) file_get_contents($manifestPath), true)
    : null;
$entry = is_array($manifest) ? ($manifest['src/main.js'] ?? null) : null;

if (! is_array($entry) || ! isset($entry['file'])) {
    throw new RuntimeException('No se encontró el bundle de la aplicación. Ejecute npm run build dentro de frontend/.');
}

$appJson = json_encode(
    $appPayload,
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#031A3E">
    <meta name="color-scheme" content="light">
    <link rel="icon" href="<?= esc(base_url('favicon.ico'), 'attr') ?>">
    <link rel="manifest" href="<?= esc(base_url('manifest.webmanifest'), 'attr') ?>">
    <meta name="csrf-token" content="<?= esc(csrf_hash(), 'attr') ?>">
    <title><?= esc($pageTitle ?? 'Mantenimiento') ?></title>
    <?php if (isset($preloadImage) && is_string($preloadImage) && $preloadImage !== ''): ?>
        <link rel="preload" as="image" href="<?= esc($preloadImage, 'attr') ?>" type="image/webp" fetchpriority="high">
    <?php endif; ?>
    <?php foreach (($entry['css'] ?? []) as $stylesheet): ?>
        <link rel="stylesheet" href="<?= esc(base_url('assets/dashboard/' . $stylesheet), 'attr') ?>">
    <?php endforeach; ?>
</head>
<body>
    <div id="maintenance-app"></div>
    <noscript><p>Esta aplicación necesita JavaScript habilitado para funcionar.</p></noscript>
    <script id="maintenance-app-data" type="application/json"><?= $appJson ?></script>
    <script type="module" src="<?= esc(base_url('assets/dashboard/' . $entry['file']), 'attr') ?>"></script>
</body>
</html>
