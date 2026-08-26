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

// Logout mutante y protegido por CSRF.
$routes->post('logout', 'Login::logout', ['filter' => 'auth']);

// Dashboard (protegido)
$routes->get('dashboard', 'Dashboard::index', ['filter' => 'auth']);

// Compatibilidad con enlaces antiguos emitidos antes de conocer el prefijo
// interno del grupo `mantenimiento` en el deploy plano.
$routes->get('planes', static function () {
    $query = service('request')->getUri()->getQuery();
    $target = base_url('mantenimiento/planes');
    return redirect()->to($query === '' ? $target : $target . '?' . $query);
}, ['filter' => ['auth', 'permission:planes.ver']]);

$routes->get('equipos/(:num)', static function (string $equipmentId) {
    $query = service('request')->getUri()->getQuery();
    $target = base_url('mantenimiento/equipos/' . (int) $equipmentId);
    return redirect()->to($query === '' ? $target : $target . '?' . $query);
}, ['filter' => ['auth', 'permission:equipos.ver']]);

// Administración global. El filtro también rechaza cuentas autenticadas no globales.
$routes->group('superadmin', ['filter' => 'superadmin'], static function ($routes): void {
    $routes->get('', 'SuperAdmin::index');
    $routes->post('empresas', 'SuperAdmin::createCompany');
    $routes->post('empresas/(:num)', 'SuperAdmin::updateCompany/$1');
    $routes->post('empresas/(:num)/notificaciones/prueba', 'SuperAdmin::testCompanyNotificationEmail/$1');
    $routes->post('administradores', 'SuperAdmin::createCompanyAdministrator');
    $routes->post('usuarios/(:num)/empresa', 'SuperAdmin::assignCompany/$1');
    $routes->post('usuarios/(:num)/roles', 'SuperAdmin::assignRoles/$1');
    $routes->post('demo', 'DemoAdmin::provision');
});

$routes->group('administracion', ['filter' => ['auth']], static function ($routes): void {
    $routes->get('sucursales', 'TenantAdmin::branches', ['filter' => 'permission:sucursales.ver']);
    $routes->post('sucursales', 'TenantAdmin::createBranch', ['filter' => 'permission:sucursales.editar']);
    $routes->post('sucursales/(:num)', 'TenantAdmin::updateBranch/$1', ['filter' => 'permission:sucursales.editar']);
    $routes->get('usuarios', 'TenantAdmin::users', ['filter' => 'permission:usuarios.ver']);
    $routes->post('usuarios', 'TenantAdmin::createUser', ['filter' => ['permission:usuarios.editar', 'permission:roles.editar']]);
    $routes->post('usuarios/(:num)', 'TenantAdmin::updateUser/$1', ['filter' => 'permission:usuarios.editar']);
    $routes->post('usuarios/(:num)/acceso', 'TenantAdmin::assignUserAccess/$1', ['filter' => ['permission:usuarios.editar', 'permission:roles.editar']]);
    $routes->post('usuarios/(:num)/password', 'TenantAdmin::resetUserPassword/$1', ['filter' => 'permission:usuarios.editar']);
});

$routes->group('mantenimiento', ['filter' => ['auth']], static function ($routes): void {
    $routes->get('', 'MaintenanceCircuit::index', ['filter' => 'permission:equipos.ver']);
    $routes->get('equipos', 'AssetManagement::index', ['filter' => 'permission:equipos.ver']);
    $routes->post('equipos', 'AssetManagement::createEquipment', ['filter' => 'permission:equipos.editar']);

    $routes->get('servicios', 'MaintenanceServices::index', ['filter' => 'permission:planes.ver']);
    $routes->post('servicios', 'MaintenanceServices::create', ['filter' => 'permission:planes.editar']);
    $routes->post('servicios/(:num)', 'MaintenanceServices::update/$1', ['filter' => 'permission:planes.editar']);
    $routes->post('servicios/(:num)/estado', 'MaintenanceServices::status/$1', ['filter' => 'permission:planes.editar']);
    $routes->get('servicios/tareas/buscar', 'LibraryTaskCatalog::search', ['filter' => 'permission:planes.editar']);
    $routes->post('servicios/(:num)/tareas', 'LibraryTaskCatalog::link/$1', ['filter' => 'permission:planes.editar']);
    $routes->post('servicios/(:num)/tareas/nueva', 'LibraryTaskCatalog::createAndLink/$1', ['filter' => 'permission:planes.editar']);
    $routes->post('servicios/(:num)/tareas/(:num)/estado', 'LibraryTaskCatalog::status/$2', ['filter' => 'permission:planes.editar']);
    $routes->post('servicios/(:num)/materiales', 'MaintenanceServices::createMaterial/$1', ['filter' => 'permission:planes.editar']);
    $routes->post('servicios/(:num)/materiales/(:num)', 'MaintenanceServices::updateMaterial/$1/$2', ['filter' => 'permission:planes.editar']);
    $routes->post('servicios/(:num)/materiales/(:num)/estado', 'MaintenanceServices::materialStatus/$1/$2', ['filter' => 'permission:planes.editar']);

    $routes->get('planes', 'PreventivePlans::index', ['filter' => 'permission:planes.ver']);
    $routes->post('planes', 'PreventivePlans::create', ['filter' => 'permission:planes.editar']);
    $routes->post('planes/desde-plantilla', 'PreventivePlans::createFromTemplates', ['filter' => 'permission:planes.editar']);
    $routes->post('planes/(:num)/editar', 'PreventivePlans::update/$1', ['filter' => 'permission:planes.editar']);
    $routes->post('planes/(:num)/orden', 'PreventivePlans::generateOrder/$1', ['filter' => 'permission:ordenes.editar']);
    $routes->get('lecturas/rapidas', 'QuickReadings::index', ['filter' => 'permission:equipos.ver']);
    $routes->post('lecturas/rapidas', 'QuickReadings::store', ['filter' => 'permission:lecturas.cargar']);
    $routes->post('lecturas/rapidas/fila', 'QuickReadings::storeRow', ['filter' => 'permission:lecturas.cargar']);
    $routes->post('lecturas/rapidas/avisos/(:num)/orden', 'QuickReadings::generateOrder/$1', ['filter' => 'permission:ordenes.editar']);
    $routes->get('equipos/(:num)', 'EquipmentManagement::show/$1', ['filter' => 'permission:equipos.ver']);
    $routes->get('equipos/(:num)/qr.svg', 'AssetManagement::qr/$1', ['filter' => 'permission:equipos.ver']);
    $routes->post('equipos/(:num)/editar', 'EquipmentManagement::update/$1', ['filter' => 'permission:equipos.editar']);
    $routes->post('equipos/(:num)/trasladar', 'EquipmentManagement::transfer/$1', ['filter' => 'permission:equipos.editar']);
    $routes->post('equipos/(:num)/baja', 'EquipmentManagement::decommission/$1', ['filter' => 'permission:equipos.editar']);
    $routes->post('equipos/(:num)/adjuntos', 'EquipmentManagement::uploadAttachment/$1', ['filter' => 'permission:equipos.editar']);
    $routes->post('equipos/(:num)/foto-principal', 'EquipmentManagement::uploadPrimaryPhoto/$1', ['filter' => 'permission:equipos.editar']);
    $routes->get('equipos/(:num)/foto-principal', 'EquipmentManagement::primaryPhoto/$1', ['filter' => 'permission:equipos.ver']);
    $routes->post('equipos/(:num)/foto-principal/retirar', 'EquipmentManagement::retirePrimaryPhoto/$1', ['filter' => 'permission:equipos.editar']);
    $routes->get('equipos/(:num)/adjuntos/(:num)/descargar', 'EquipmentManagement::downloadAttachment/$1/$2', ['filter' => 'permission:equipos.ver']);
    $routes->post('equipos/(:num)/adjuntos/(:num)/retirar', 'EquipmentManagement::retireAttachment/$1/$2', ['filter' => 'permission:equipos.editar']);
    $routes->post('equipos/(:num)/relaciones', 'EquipmentManagement::createRelation/$1', ['filter' => 'permission:equipos.editar']);
    $routes->post('equipos/(:num)/relaciones/(:num)/finalizar', 'EquipmentManagement::finishRelation/$1/$2', ['filter' => 'permission:equipos.editar']);
    $routes->post('catalogos/marcas', 'AssetManagement::createBrand', ['filter' => 'permission:equipos.editar']);
    $routes->post('catalogos/marcas/(:num)', 'AssetManagement::renameBrand/$1', ['filter' => 'permission:equipos.editar']);
    $routes->post('catalogos/marcas/(:num)/inactivar', 'AssetManagement::inactivateBrand/$1', ['filter' => 'permission:equipos.editar']);
    $routes->post('catalogos/modelos', 'AssetManagement::createModel', ['filter' => 'permission:equipos.editar']);
    $routes->post('catalogos/modelos/(:num)', 'AssetManagement::renameModel/$1', ['filter' => 'permission:equipos.editar']);
    $routes->post('catalogos/modelos/(:num)/inactivar', 'AssetManagement::inactivateModel/$1', ['filter' => 'permission:equipos.editar']);

    $routes->get('importaciones', 'ImportManagement::index', ['filter' => 'permission:importaciones.ver']);
    $routes->get('importaciones/biblioteca', 'ImportManagement::library', ['filter' => 'permission:importaciones.ver']);
    $routes->post('importaciones/biblioteca/items/(:num)', 'ImportManagement::updateLibraryItem/$1', ['filter' => 'permission:importaciones.cargar']);
    $routes->get('importaciones/biblioteca/tareas/buscar', 'LibraryTaskCatalog::search', ['filter' => 'permission:importaciones.cargar']);
    $routes->post('importaciones/biblioteca/tareas/(:num)', 'ImportManagement::updateLibraryTask/$1', ['filter' => 'permission:importaciones.cargar']);
    $routes->post('importaciones/biblioteca/tareas/(:num)/estado', 'LibraryTaskCatalog::status/$1', ['filter' => 'permission:importaciones.cargar']);
    $routes->post('importaciones/biblioteca/tareas/(:num)/desvincular', 'LibraryTaskLinks::detach/$1', ['filter' => 'permission:importaciones.cargar']);
    $routes->post('importaciones/biblioteca/servicios/(:num)/tareas', 'LibraryTaskCatalog::link/$1', ['filter' => 'permission:importaciones.cargar']);
    $routes->post('importaciones/biblioteca/servicios/(:num)/tareas/nueva', 'LibraryTaskCatalog::createAndLink/$1', ['filter' => 'permission:importaciones.cargar']);
    $routes->get('importaciones/plantilla/(:segment)', 'ImportManagement::template/$1', ['filter' => 'permission:importaciones.cargar']);
    $routes->post('importaciones', 'ImportManagement::upload', ['filter' => 'permission:importaciones.cargar']);
    $routes->get('importaciones/(:num)', 'ImportManagement::show/$1', ['filter' => 'permission:importaciones.ver']);
    $routes->post('importaciones/(:num)/confirmar', 'ImportManagement::confirm/$1', ['filter' => 'permission:importaciones.cargar']);
    $routes->post('importaciones/(:num)/cancelar', 'ImportManagement::cancel/$1', ['filter' => 'permission:importaciones.cargar']);

    $routes->post('equipos/(:num)/lecturas', 'MaintenanceCircuit::registerReading/$1', ['filter' => 'permission:lecturas.cargar']);
    $routes->post('equipos/(:num)/lecturas/(:num)/corregir', 'EquipmentManagement::correctReading/$1/$2', ['filter' => 'permission:lecturas.corregir']);
    $routes->post('equipos/(:num)/planes', 'MaintenanceCircuit::assignPlan/$1', ['filter' => 'permission:planes.editar']);
    $routes->post('vencimientos/detectar', 'MaintenanceCircuit::detectOverdue', ['filter' => 'permission:planes.editar']);
    $routes->post('avisos/(:num)/orden', 'MaintenanceCircuit::generateOrder/$1', ['filter' => 'permission:ordenes.editar']);
    $routes->get('ordenes', 'WorkOrders::index', ['filter' => 'permission:ordenes.ver']);
    $routes->get('ordenes/importar', 'WorkOrderDocumentImports::index', ['filter' => 'permission:ordenes.editar']);
    $routes->post('ordenes/importar', 'WorkOrderDocumentImports::upload', ['filter' => 'permission:ordenes.editar']);
    $routes->get('ordenes/importar/(:num)', 'WorkOrderDocumentImports::show/$1', ['filter' => 'permission:ordenes.editar']);
    $routes->post('ordenes/importar/(:num)/analizar', 'WorkOrderDocumentImports::analyze/$1', ['filter' => 'permission:ordenes.editar']);
    $routes->get('ordenes/importar/(:num)/documento', 'WorkOrderDocumentImports::document/$1', ['filter' => 'permission:ordenes.editar']);
    $routes->post('ordenes/correctivas', 'CorrectiveWorkOrders::create', ['filter' => 'permission:ordenes.editar']);
    $routes->get('ordenes/(:num)/imprimir', 'MaintenanceCircuit::printOrder/$1');
    $routes->post('ordenes/(:num)/iniciar', 'MaintenanceCircuit::startOrder/$1', ['filter' => 'permission:ordenes.editar']);
    $routes->post('ordenes/(:num)/esperar-repuestos', 'WorkOrderLifecycle::waitForParts/$1', ['filter' => 'permission:ordenes.editar']);
    $routes->post('ordenes/(:num)/reanudar', 'WorkOrderLifecycle::resume/$1', ['filter' => 'permission:ordenes.editar']);
    $routes->post('ordenes/(:num)/cancelar', 'WorkOrderLifecycle::cancel/$1', ['filter' => 'permission:ordenes.editar']);
    $routes->post('ordenes/(:num)/cerrar', 'MaintenanceCircuit::closeOrder/$1', ['filter' => 'permission:ordenes.cerrar']);
    $routes->post('ordenes/(:num)/cerrar-correctiva', 'CorrectiveWorkOrders::close/$1', ['filter' => 'permission:ordenes.cerrar']);
});

$routes->group('mantenimiento/chatbot', ['filter' => ['auth', 'permission:chatbot.usar']], function ($routes) {
    $routes->get('/',               'Chatbot::index');
    $routes->post('conversaciones', 'Chatbot::startConversation');
    $routes->post('mensajes',       'Chatbot::sendMessage');
    $routes->post('mensajes/stream','Chatbot::sendMessageStream');
    $routes->post('confirmar',      'Chatbot::confirmTool');
    $routes->get('historial',       'Chatbot::history');
});

// Auditoría administrativa: el controlador resuelve el alcance real desde el
// ActorContext. No se usa permission filter acá porque el superadmin global no
// hereda permisos de tenant; el permiso de empresa se valida dentro del caso de uso.
$routes->group('mantenimiento/chatbot/auditoria', ['filter' => ['auth']], static function ($routes): void {
    $routes->get('', 'ChatbotAudit::index');
    $routes->get('(:num)', 'ChatbotAudit::show/$1');
});

$routes->group('reportes', ['filter' => ['auth', 'permission:reportes.ver']], static function ($routes): void {
    $routes->get('', 'Reports::index');
    $routes->get('exportar', 'Reports::export');
});

$routes->group('', ['filter' => ['auth', 'permission:notificaciones.ver']], static function ($routes): void {
    $routes->get('notificaciones', 'Notifications::index');
    $routes->get('notificaciones/resumen', 'Notifications::summary');
    $routes->post('notificaciones/leer/(:num)', 'Notifications::read/$1');
    $routes->post('notificaciones/leer-todas', 'Notifications::readAll');
    $routes->post('perfil/notificaciones', 'Notifications::updatePreferences');
    $routes->post('perfil/notificaciones/webpush', 'Notifications::subscribe');
    $routes->post('perfil/notificaciones/webpush/eliminar', 'Notifications::unsubscribe');
    $routes->post('perfil/notificaciones/webpush/prueba', 'Notifications::testPush');
});