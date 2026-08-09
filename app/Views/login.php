<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar sesion - Mantenimiento</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <style>
        body { background: #f4f6f8; }
        .login-card { max-width: 420px; margin: 4rem auto; }
    </style>
</head>
<body>
    <div class="container">
        <div class="login-card card shadow-sm">
            <div class="card-body p-4">
                <h1 class="h4 mb-3">Mantenimiento</h1>
                <p class="text-muted small mb-4">Inicia sesion para continuar</p>

                <?php if (isset($error)): ?>
                    <div class="alert alert-danger"><?= esc($error) ?></div>
                <?php endif; ?>

                <?php if (session()->getFlashdata('error')): ?>
                    <div class="alert alert-warning"><?= esc(session()->getFlashdata('error')) ?></div>
                <?php endif; ?>

                <?php if (session()->getFlashdata('msg')): ?>
                    <div class="alert alert-success"><?= esc(session()->getFlashdata('msg')) ?></div>
                <?php endif; ?>

                <form method="post" action="<?= base_url('login/authenticate') ?>" autocomplete="off">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                               id="email" name="email" value="<?= esc($email ?? '') ?>" required autofocus>
                        <?php if (isset($errors['email'])): ?>
                            <div class="invalid-feedback"><?= esc($errors['email']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">Contrasena</label>
                        <input type="password" class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>"
                               id="password" name="password" required>
                        <?php if (isset($errors['password'])): ?>
                            <div class="invalid-feedback"><?= esc($errors['password']) ?></div>
                        <?php endif; ?>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Iniciar sesion</button>
                </form>

                <p class="text-muted small mt-4 mb-0">
                    Demo: <code>admin@mantenimiento.local</code> / <code>Admin1234</code>
                </p>
            </div>
        </div>
    </div>
</body>
</html>
