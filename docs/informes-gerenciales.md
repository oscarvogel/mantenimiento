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

El informe separa dos dominios que antes se confundían bajo una sola tarjeta de "Vencimientos":

- **Documentación**: `Documentación vencida` y `Documentación próxima (30 días)`, calculadas sobre la tabla de vencimientos (`/mantenimiento/vencimientos`).
- **Mantenimiento preventivo**: `Preventivos vencidos` y `Preventivos próximos`, calculadas con `EvaluadorVencimiento` sobre los planes activos, es decir la misma regla de dominio que usa la pantalla de planes (`/mantenimiento/planes`). Un documento vencido nunca cuenta como preventivo.

El diario incluye además equipos activos, OT abiertas/demoradas, OT creadas/cerradas y equipos sin lectura reciente.

El semanal agrega preventivos pendientes/finalizados, costo registrado de OT cerradas y los equipos con más OT del período.

## Informe vigente en la cola

Un informe gerencial es una foto del estado actual. Si la cola acumula varias entregas del mismo tipo para la misma empresa y destinatario, el despacho envía únicamente la más reciente y deja las anteriores en `OMITIDA`. Así el correo nunca muestra un informe perimido con métricas que ya no aplican, y la sustitución queda auditada.

## Ejecución

El scheduler se ejecuta dentro del ciclo existente de notificaciones. El cron puede seguir corriendo con la frecuencia actual; la clave lógica por empresa + período + tipo + destinatario impide duplicados.

Los informes gerenciales se agrupan aparte de las alertas empresariales operativas.

La prueba manual y el envío programado usan el mismo generador: ambas entradas delegan en `queueCompany()` y construyen el resumen en un único `buildReport()`, de modo que no pueden divergir.

## Prueba controlada en Demo

1. Desplegar la rama del issue en Coolify.
2. Entrar como Superadmin y aplicar migraciones pendientes.
3. Editar la empresa Demo.
4. Indicar un correo de informes.
5. Habilitar diario y/o semanal y guardar.
6. Usar **Probar informe diario** o **Probar informe semanal**.
7. Confirmar recepción y verificar las cuatro tarjetas: documentación vencida, documentación próxima, preventivos vencidos y preventivos próximos.
8. Ejecutar nuevamente el ciclo normal y confirmar que un período ya generado no se duplica.

La prueba manual genera una clave específica de prueba para permitir repetir el smoke cuando sea necesario.
