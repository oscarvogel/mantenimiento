# Vinculación manual de unidades Wialon — plan

**Objetivo:** permitir que Administración vincule las unidades de una cuenta Wialon guardada con los equipos de la empresa, sin volver a cargar el token.

**Arquitectura:** la pantalla pide al caso de uso un catálogo acotado por empresa; Infrastructure descifra las credenciales, consulta Wialon y persiste los vínculos existentes en `equipo_telemetria`. Las asociaciones se validan contra la flota de la empresa y las unidades realmente devueltas por el proveedor.

**Límites:** CodeIgniter 4 con vista Vue renderizada por servidor; sin migración si las restricciones actuales permiten reasignar de forma segura.

## Tareas

1. **Caso de uso y adaptador:** prueba primero para carga del catálogo y validación de vínculos duplicados/IDs fuera de empresa; implementar puerto de aplicación y adaptador Wialon + persistencia.
2. **HTTP y pantalla:** exponer consulta y guardado bajo `administracion/integraciones/telemetria/{id}`; añadir en cada cuenta un panel con equipos, unidades buscables y acción de guardar; probar render e interacciones.
3. **Regresión del alta:** conservar vínculos manuales válidos cuando se vuelve a guardar el token; mostrar feedback accionable cuando no hubo coincidencias exactas.

## Verificación

- PHPUnit dirigido en WSL, luego suite PHPUnit.
- Vitest para la página de integraciones y suite frontend.
- `npm run build` y comprobación de sincronización de assets.
- No acceder ni cambiar staging/producción durante esta tarea.
