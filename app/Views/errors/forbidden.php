<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Acceso restringido · Mantenimiento</title>
    <style>
        :root { color-scheme: dark; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            background: #071426;
            color: #eef5ff;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        .card {
            width: min(560px, 100%);
            border: 1px solid #29415f;
            border-radius: 18px;
            background: #111f33;
            padding: 32px;
            box-shadow: 0 24px 70px rgba(0,0,0,.28);
        }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border-radius: 999px;
            background: #2d1c20;
            color: #ff9a9a;
            padding: 6px 10px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
        }
        h1 { margin: 18px 0 8px; font-size: clamp(28px, 5vw, 38px); line-height: 1.05; }
        p { margin: 0; color: #aebbd0; line-height: 1.6; }
        .actions { margin-top: 26px; display: flex; gap: 12px; flex-wrap: wrap; }
        a {
            display: inline-flex;
            min-height: 44px;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            padding: 10px 16px;
            text-decoration: none;
            font-weight: 700;
        }
        .primary { background: #49a5ff; color: #06111f; }
        .secondary { border: 1px solid #3d5878; color: #d9e8fa; }
    </style>
</head>
<body>
    <main class="card">
        <span class="badge">Acceso restringido</span>
        <h1>No podés entrar a esta sección</h1>
        <p><?= esc($message ?? 'Tu usuario no tiene permiso para acceder a este contenido.') ?></p>
        <div class="actions">
            <a class="primary" href="<?= esc(base_url('dashboard')) ?>">Volver al dashboard</a>
            <a class="secondary" href="javascript:history.back()">Volver atrás</a>
        </div>
    </main>
</body>
</html>
