<?php

namespace App\Controllers;

use App\Application\Identity\Port\LoginAttemptLimiter;
use App\Infrastructure\Identity\CodeIgniterActorContextProvider;
use App\Infrastructure\Identity\SessionActorContext;
use App\Models\UsuarioModel;
use CodeIgniter\Controller;

class Login extends Controller
{
    public function index()
    {
        // Si ya esta logueado, redirigir al dashboard
        if (session()->get('usuario_id')) {
            return redirect()->to('/dashboard');
        }
        return $this->loginView();
    }

    public function authenticate()
    {
        $rules = [
            'email'    => 'required|valid_email',
            'password' => 'required|min_length[4]',
        ];
        if (! $this->validate($rules)) {
            return $this->loginView(['errors' => $this->validator->getErrors(), 'email' => $this->request->getPost('email')]);
        }

        $email    = mb_strtolower(trim((string) $this->request->getPost('email')));
        $password = $this->request->getPost('password');
        $ipAddress = $this->request->getIPAddress();

        /** @var LoginAttemptLimiter $limiter */
        $limiter = service('loginAttemptLimiter');
        if (! $limiter->consume($email, $ipAddress)) {
            $retryAfter = $limiter->retryAfterSeconds();

            return $this->response
                ->setStatusCode(429)
                ->setHeader('Retry-After', (string) $retryAfter)
                ->setBody($this->loginView([
                    'error' => 'Demasiados intentos. Espere antes de volver a intentar.',
                    'email' => $email,
                ]));
        }

        $model  = new UsuarioModel();
        $usuario = $model->findByEmail($email);

        if (! $usuario || ! password_verify($password, $usuario['password_hash'])) {
            return $this->loginView(['error' => 'Email o contraseña incorrectos.', 'email' => $email]);
        }

        // Evita conservar el identificador de una sesión anónima autenticada.
        $session = session();
        $session->regenerate(true);

        $actor = (new CodeIgniterActorContextProvider($model))->load((int) $usuario['id']);
        if ($actor === null) {
            return $this->loginView([
                'error' => 'La cuenta no tiene un alcance de acceso válido.',
                'email' => $email,
            ]);
        }

        $limiter->clear($email, $ipAddress);

        $session->set('usuario_id',     $usuario['id']);
        $session->set('usuario_nombre', $usuario['nombre']);
        $session->set('usuario_email',  $usuario['email']);
        $session->set('empresa_id',     $actor->companyId());
        $session->set('es_superadmin',  $actor->isSuperAdmin());

        $session->set('roles',      $actor->roles());
        $session->set('permisos',   $actor->permissions());
        $session->set('sucursales', $model->sucursales((int) $usuario['id'], in_array('Administrador', $actor->roles(), true)));
        (new SessionActorContext())->store($actor);

        $model->touchLastAccess($usuario['id']);

        $redirect = $session->get('redirect_after_login') ?? '/dashboard';
        $session->remove('redirect_after_login');
        return redirect()->to($redirect);
    }

    public function logout()
    {
        (new SessionActorContext())->clear();
        session()->destroy();

        return redirect()->to('/login')->with('msg', 'Sesion cerrada.');
    }

    /** @param array<string,mixed> $data */
    private function loginView(array $data = []): string
    {
        $error = $data['error'] ?? session()->getFlashdata('error');
        $success = session()->getFlashdata('msg');
        $backgroundUrl = base_url('assets/login/maintenance-workshop.webp');

        return view('app', [
            'appPayload' => [
                'page' => 'login',
                'data' => [
                    'action' => base_url('login/authenticate'),
                    'backgroundUrl' => $backgroundUrl,
                    'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()],
                    'email' => (string) ($data['email'] ?? ''),
                    'errors' => is_array($data['errors'] ?? null) ? $data['errors'] : [],
                    'alert' => $error !== null
                        ? ['type' => 'error', 'message' => (string) $error]
                        : ($success !== null ? ['type' => 'success', 'message' => (string) $success] : null),
                ],
            ],
            'pageTitle' => 'Ingreso - Mantenimiento',
            'preloadImage' => $backgroundUrl,
        ]);
    }
}
