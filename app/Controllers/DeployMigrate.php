<?php

declare(strict_types=1);

namespace App\Controllers;

use CodeIgniter\Database\MigrationRunner;
use Config\Database;
use Throwable;

/**
 * Helper temporal de migracion para deploys en Ferozo sin SSH.
 *
 * Para usarlo:
 *   1. Definir `MIGRATE_TOKEN=<random-seguro>` en el `.env` de produccion.
 *   2. Acceder a:
 *        GET /admin/deploy-migrate?status=1   (solo ver)
 *        GET /admin/deploy-migrate            (correr pendientes)
 *      con header `X-Migrate-Token: <token>`.
 *   3. BORRAR este controller y la ruta despues de cada deploy.
 *
 * Este archivo NO debe quedar en produccion entre deploys. Es solo un
 * helper de operacion, no parte del modelo de aplicacion.
 */
final class DeployMigrate extends BaseController
{
    public function run(): \CodeIgniter\HTTP\Response
    {
        $environment = env('CI_ENVIRONMENT', 'production');
        if ($environment !== 'production') {
            return $this->plain(403, "ERROR: solo corre en produccion. CI_ENVIRONMENT='$environment'.");
        }

        $expectedToken = (string) (env('MIGRATE_TOKEN') ?? '');
        if ($expectedToken === '' || strlen($expectedToken) < 32) {
            return $this->plain(500, 'ERROR: MIGRATE_TOKEN no esta definido o es muy corto en .env.');
        }

        $provided = (string) ($this->request->getHeaderLine('X-Migrate-Token') ?? '');
        if (! hash_equals($expectedToken, $provided)) {
            return $this->plain(401, 'ERROR: token invalido o ausente (header X-Migrate-Token).');
        }

        try {
            /** @var MigrationRunner $runner */
            $runner = service('migrations');
        } catch (Throwable $e) {
            return $this->plain(500, 'ERROR al obtener servicio migrations: ' . $e->getMessage());
        }

        $runner->clearCliMessages();
        $group = Database::connect()->DBDriver === 'MySQLi' ? 'default' : null;
        $action = $this->request->getGet('status') === '1' ? 'status' : 'migrate';

        if ($action === 'status') {
            $history = $runner->getHistory();
            $body = "Migraciones aplicadas hasta el momento:\n";
            if ($history === []) {
                $body .= "  (sin migraciones registradas)\n";
            } else {
                foreach ($history as $entry) {
                    $version = $entry->version ?? '?';
                    $name    = $entry->name ?? '?';
                    $body   .= "  - $version  $name\n";
                }
            }
            $body .= "OK: estado reportado. Sin cambios en la base.\n";
            $body .= "Recordatorio: borrar app/Controllers/DeployMigrate.php y la ruta cuando termine el deploy.\n";
            return $this->plain(200, $body);
        }

        try {
            $ok = $runner->latest($group);
        } catch (Throwable $e) {
            return $this->plain(500, 'ERROR al correr migraciones: ' . $e->getMessage() . "\nTraza:\n" . $e->getTraceAsString());
        }

        $body = '';
        foreach ($runner->getCliMessages() as $message) {
            $body .= $message . "\n";
        }
        if (! $ok) {
            return $this->plain(500, $body . "ERROR: el runner devolvio false. Revisar writable/logs/.\n");
        }
        $body .= "OK: migraciones aplicadas.\n";
        $body .= "Recordatorio: borrar app/Controllers/DeployMigrate.php y la ruta cuando termine el deploy.\n";
        return $this->plain(200, $body);
    }

    private function plain(int $status, string $body): \CodeIgniter\HTTP\Response
    {
        return $this->response
            ->setStatusCode($status)
            ->setContentType('text/plain', 'utf-8')
            ->setBody($body);
    }
}
