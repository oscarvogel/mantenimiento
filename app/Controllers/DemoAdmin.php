<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database\Seeds\DemoCompanySeeder;
use CodeIgniter\HTTP\RedirectResponse;
use RuntimeException;
use Throwable;

final class DemoAdmin extends BaseController
{
    public function provision(): RedirectResponse
    {
        if (! $this->validate([
            'demo_email' => 'required|valid_email|max_length[255]',
            'demo_password' => 'required|min_length[8]|max_length[255]',
            'demo_password_confirmation' => 'required|matches[demo_password]',
            'demo_dias' => 'required|integer|greater_than_equal_to[1]|less_than_equal_to[90]',
            'demo_accion' => 'required|in_list[crear,regenerar]',
        ])) {
            return redirect()->to('/superadmin')->withInput()->with(
                'error',
                implode(' ', $this->validator->getErrors()),
            );
        }

        $reset = (string) $this->request->getPost('demo_accion') === 'regenerar';

        try {
            $this->ensureDemoSchema();

            $seeder = $this->createDemoSeeder();
            $seeder->configure(
                (string) $this->request->getPost('demo_email'),
                (string) $this->request->getPost('demo_password'),
                (int) $this->request->getPost('demo_dias'),
                $reset,
            )->run();

            $message = $reset
                ? 'Empresa demo regenerada correctamente. Sus datos volvieron al estado inicial.'
                : 'Empresa demo creada correctamente y lista para usar.';

            return redirect()->to('/superadmin')->with('success', $message);
        } catch (RuntimeException $exception) {
            return redirect()->to('/superadmin')->withInput()->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            $errorId = 'DEMO-' . date('YmdHis') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
            log_message('error', '[{errorId}] Falló la generación de empresa demo: {message}', [
                'errorId' => $errorId,
                'message' => $exception->getMessage(),
            ]);

            return redirect()->to('/superadmin')->withInput()->with(
                'error',
                'No se pudo generar la empresa demo. Código de diagnóstico: ' . $errorId . '.',
            );
        }
    }

    private function ensureDemoSchema(): void
    {
        $database = db_connect();
        if ($database->fieldExists('es_demo', 'empresas') && $database->fieldExists('demo_expira_at', 'empresas')) {
            return;
        }

        $migrations = service('migrations');
        if (! $migrations->latest()) {
            throw new RuntimeException('No se pudieron aplicar las migraciones pendientes necesarias para la empresa demo.');
        }

        $database = db_connect();
        if (! $database->fieldExists('es_demo', 'empresas') || ! $database->fieldExists('demo_expira_at', 'empresas')) {
            throw new RuntimeException('La estructura requerida para la empresa demo todavía no está disponible.');
        }
    }

    private function createDemoSeeder(): DemoCompanySeeder
    {
        return new DemoCompanySeeder(config('Database'), db_connect());
    }
}
