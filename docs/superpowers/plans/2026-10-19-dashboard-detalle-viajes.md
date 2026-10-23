# Plan: mejorar dashboard y consulta de viajes

## Alcance confirmado

- Promover Dashboard de Viajes al primer nivel del menú, antes de Maestros.
- Corregir y cubrir los indicadores dependientes de filtros (calidad y agrupación por unidad/dominio), preservando los filtros aplicados.
- Agregar listado y ficha de solo lectura de un viaje con tramos, combustible y gastos existentes.
- Mantener el formato de fecha nativo del navegador; no se modifica.

## Límites

- Contexto propietario: Viajes. Sin nuevas reglas de escritura ni migraciones.
- Cada lectura valida empresa, permiso y sucursales autorizadas; un viaje ajeno se trata como no encontrado.
- No completar campos ausentes ni cambiar el alcance del borrador de operaciones de viajes.

## Ejecución y validación

1. Escribir primero regresiones para menú y filtros; confirmar que detectan los defectos.
2. Introducir listado/detalle mediante la ruta y el patrón de lectura existentes en CI4, con pruebas de acceso y datos relacionados.
3. Ejecutar pruebas frontend y PHPUnit apropiadas; verificar diff y que no se requiera migración.
4. No operar Coolify ni producción sin una solicitud de despliegue. La validación de staging deberá seguir el procedimiento canónico fasa_189.
