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
    case ASIGNACION_CHOFER_CERRADA = 'asignacion.chofer_cerrada';
    case NOTIFICACION_WHATSAPP_CANCELADA = 'notificacion.whatsapp_cancelada';
    case CLIENTE_CC_CARGO_REGISTRADO = 'cliente_cc.cargo_registrado';
    case CLIENTE_CC_CARGO_ANULADO = 'cliente_cc.cargo_anulado';
    case CLIENTE_CC_TRAMO_NO_COBRABLE = 'cliente_cc.tramo_no_cobrable';
    case CLIENTE_CC_TRAMO_REABIERTO = 'cliente_cc.tramo_reabierto';
    case CLIENTE_CC_COBRANZA_REGISTRADA = 'cliente_cc.cobranza_registrada';
    case CLIENTE_CC_COBRANZA_ACREDITADA = 'cliente_cc.cobranza_acreditada';
    case CLIENTE_CC_COBRANZA_ANULADA = 'cliente_cc.cobranza_anulada';
}
