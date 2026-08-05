<?php

namespace App\Controllers;

use App\Models\UsuarioModel;
use CodeIgniter\Controller;

class Dashboard extends Controller
{
    public function index()
    {
        $session = session();
        $usuarioId = $session->get('usuario_id');

        $model  = new UsuarioModel();
        $roles      = $model->roles($usuarioId);
        $permisos   = $model->permisos($usuarioId);
        $sucursales = $model->sucursales($usuarioId);

        return view('dashboard', [
            'usuario'    => [
                'id'         => $usuarioId,
                'nombre'     => $session->get('usuario_nombre'),
                'email'      => $session->get('usuario_email'),
                'empresa_id' => $session->get('empresa_id'),
            ],
            'roles'      => $roles,
            'permisos'   => $permisos,
            'sucursales' => $sucursales,
        ]);
    }
}