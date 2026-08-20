<?php

declare(strict_types=1);

/**
 * Diagnóstico web mínimo para hosting compartido.
 * NO ejecuta cambios. Verifica requisitos de la empresa demo.
 * Debe incluirse/ejecutarse únicamente desde un contexto autenticado de superadmin.
 */

$db = db_connect();

$checks = [
    'empresas.es_demo' => $db->fieldExists('es_demo', 'empresas'),
    'empresas.demo_expira_at' => $db->fieldExists('demo_expira_at', 'empresas'),
    'rol Administrador' => $db->tableExists('roles') && $db->table('roles')->where('nombre', 'Administrador')->countAllResults() > 0,
    'usuario_roles' => $db->tableExists('usuario_roles'),
    'usuario_sucursales' => $db->tableExists('usuario_sucursales'),
    'equipos' => $db->tableExists('equipos'),
    'lecturas_equipo' => $db->tableExists('lecturas_equipo'),
    'tipos_servicio' => $db->tableExists('tipos_servicio'),
    'ordenes_trabajo' => $db->tableExists('ordenes_trabajo'),
];

return $checks;
