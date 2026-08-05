<?php

namespace App\Controllers;

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
        return view('login');
    }

    public function authenticate()
    {
        $rules = [
            'email'    => 'required|valid_email',
            'password' => 'required|min_length[4]',
        ];
        if (! $this->validate($rules)) {
            return view('login', ['errors' => $this->validator->getErrors(), 'email' => $this->request->getPost('email')]);
        }

        $email    = $this->request->getPost('email');
        $password = $this->request->getPost('password');

        $model  = new UsuarioModel();
        $usuario = $model->findByEmail($email);

        if (! $usuario || ! password_verify($password, $usuario['password_hash'])) {
            return view('login', ['error' => 'Email o contrasena incorrectos.', 'email' => $email]);
        }

        // OK, guardar sesion
        $session = session();
        $session->set('usuario_id',   $usuario['id']);
        $session->set('usuario_nombre', $usuario['nombre']);
        $session->set('usuario_email',  $usuario['email']);
        $session->set('empresa_id',     $usuario['empresa_id']);

        $roles      = $model->roles($usuario['id']);
        $permisos   = $model->permisos($usuario['id']);
        $sucursales = $model->sucursales($usuario['id']);
        $session->set('roles',      array_column($roles, 'nombre'));
        $session->set('permisos',   $permisos);
        $session->set('sucursales', $sucursales);

        $model->touchLastAccess($usuario['id']);

        $redirect = $session->get('redirect_after_login') ?? '/dashboard';
        $session->remove('redirect_after_login');
        return redirect()->to($redirect);
    }

    public function logout()
    {
        session()->destroy();
        return redirect()->to('/login')->with('msg', 'Sesion cerrada.');
    }
}