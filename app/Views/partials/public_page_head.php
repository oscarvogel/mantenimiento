<?php
/**
 * <head> común de las páginas públicas (sin sesión): toma la hoja de estilos de la app,
 * siempre en tema claro (se abren al aire libre y no hay preferencia guardada).
 * Sin manifest la página igual funciona, solo sin estilos: nunca un error 500 para el chofer.
 *
 * @var string $locale
 * @var string $title
 */
$manifestPath = FCPATH . 'assets/dashboard/.vite/manifest.json';
$manifest     = is_file($manifestPath) ? json_decode((string) file_get_contents($manifestPath), true) : null;
$stylesheets  = is_array($manifest) ? (array) ($manifest['src/main.js']['css'] ?? []) : [];
?>
<!doctype html>
<html lang="<?= esc(strtolower($locale ?? 'ES'), 'attr') ?>" data-theme="light" style="color-scheme: light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0c1523">
    <meta name="robots" content="noindex">
    <link rel="icon" type="image/png" href="<?= esc(base_url('assets/brand/vogel-consultoria.png'), 'attr') ?>">
    <title><?= esc($title ?? 'Registrar lectura') ?></title>
    <?php foreach ($stylesheets as $stylesheet): ?>
        <link rel="stylesheet" href="<?= esc(base_url('assets/dashboard/' . $stylesheet), 'attr') ?>">
    <?php endforeach; ?>
</head>
<body class="min-h-dvh">
    <!-- Franja marino con el logo, igual que la barra de la app. -->
    <header class="bg-secondary-900">
        <div class="mx-auto flex max-w-lg items-center px-4 py-3">
            <span role="img" aria-label="Vogel Consultoría" class="block h-8 w-[3.25rem] bg-no-repeat"
                  style="background-image: url('<?= esc(base_url('assets/brand/vogel-consultoria-sin-fondo.png'), 'attr') ?>'); background-size: 146% auto; background-position: center 47.5%"></span>
        </div>
    </header>
