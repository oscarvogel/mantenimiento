<?php

declare(strict_types=1);

namespace App\Application\Audit;

enum AuditAction: string
{
    case EMPRESA_CREADA = 'empresa.creada';
    case EMPRESA_ACTUALIZADA = 'empresa.actualizada';
    case SUCURSAL_CREADA = 'sucursal.creada';
    case SUCURSAL_ACTUALIZADA = 'sucursal.actualizada';
    case USUARIO_CREADO = 'usuario.creado';
    case USUARIO_ACTUALIZADO = 'usuario.actualizado';
    case USUARIO_EMPRESA_CAMBIADA = 'usuario.empresa_cambiada';
    case USUARIO_ROLES_ASIGNADOS = 'usuario.roles_asignados';
    case USUARIO_ACCESO_ASIGNADO = 'usuario.acceso_asignado';
    case USUARIO_PASSWORD_RESTABLECIDA = 'usuario.password_restablecida';
}
