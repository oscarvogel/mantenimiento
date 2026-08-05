<?php

namespace Config;

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'Home::index');

// Login publico (sin filtro)
$routes->get('login', 'Login::index');
$routes->post('login/authenticate', 'Login::authenticate');

// Logout (protegido)
$routes->get('logout', 'Login::logout', ['filter' => 'auth']);

// Dashboard (protegido)
$routes->get('dashboard', 'Dashboard::index', ['filter' => 'auth']);