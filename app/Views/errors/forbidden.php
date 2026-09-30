<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso restringido - Mantenimiento</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <style>
        body { background: #f4f6f8; }
        .forbidden-card { max-width: 520px; margin: 6vh auto; }
        .forbidden-badge {
            display: inline-block;
            background: #fde8e8;
            color: #a12a2a;
            border-radius: 999px;
            padding: 4px 12px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="forbidden-card card shadow-sm">
            <div class="card-body p-4">
                <span class="forbidden-badge mb-3">Acceso restringido</span>
                <h1 class="h4 mb-3">No podés entrar a esta secci&oacute;n</h1>
                <p class="text-muted small mb-4">
                    <?= esc($message ?? 'Tu usuario no tiene permiso para acceder a este contenido.') ?>
                </p>
                <p class="text-muted small mb-4">
                    Si cre&eacute;s que deber&iacute;as poder verlo, ped&iacute;le a un administrador de tu empresa
                    que revise los permisos de tu rol.
                </p>
                <div class="d-flex gap-2 flex-wrap">
                    <a class="btn btn-primary" href="<?= esc(base_url('dashboard')) ?>">Volver al dashboard</a>
                    <a class="btn btn-outline-secondary" href="javascript:history.back()">Volver atr&aacute;s</a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
