<?php

namespace App\Controllers;

use CodeIgniter\HTTP\RedirectResponse;

class Home extends BaseController
{
    public function index(): RedirectResponse
    {
        return redirect()->to(session()->has('usuario_id') ? base_url('inicio') : base_url('login'));
    }
}
