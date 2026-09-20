# Informes gerenciales de mantenimiento

La rama `feat/management-daily-weekly-reports` agrega informes automáticos para dueño/gerencia sin mezclar esos correos con las alertas operativas.

## Configuración por empresa

Desde **Superadmin > Empresas** se puede configurar:

- uno o varios destinatarios (separados por coma);
- resumen diario y hora;
- resumen semanal, día y hora.

Si no se configura un correo específico de informes, se usa primero `email_notificaciones` y luego el email general de la empresa.

Los informes nacen deshabilitados para no activar envíos al desplegar la migración.

## Contenido

El diario incluye equipos activos, vencimientos, OT abiertas/demoradas, OT creadas/cerradas y equipos sin lectura reciente.

El semanal agrega preventivos pendientes/finalizados, costo registrado de OT cerradas y los equipos con más OT del período.

## Ejecución

El scheduler se ejecuta dentro del ciclo existente de notificaciones. El cron puede seguir corriendo con la frecuencia actual; la clave lógica por empresa + período + tipo + destinatario impide duplicados.

Los informes gerenciales se agrupan aparte de las alertas empresariales operativas.

## Prueba controlada en Demo

1. Desplegar `feat/management-daily-weekly-reports` en Coolify.
2. Entrar como Superadmin y aplicar migraciones pendientes.
3. Editar la empresa Demo.
4. Indicar un correo de informes.
5. Habilitar diario y/o semanal y guardar.
6. Usar **Probar informe diario** o **Probar informe semanal**.
7. Confirmar recepción y revisar los indicadores.
8. Ejecutar nuevamente el ciclo normal y confirmar que un período ya generado no se duplica.

La prueba manual genera una clave específica de prueba para permitir repetir el smoke cuando sea necesario.
