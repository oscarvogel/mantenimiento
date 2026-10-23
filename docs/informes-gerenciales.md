# Informes gerenciales de mantenimiento

La rama `feat/management-daily-weekly-reports` agrega informes automáticos para dueño/gerencia sin mezclar esos correos con las alertas operativas.

## Configuración por empresa

Desde **Superadmin > Empresas** se puede configurar:

- uno o varios destinatarios (separados por coma);
- resumen diario y hora;
- resumen semanal, día y hora;
- el aviso de equipos sin lectura por WhatsApp (teléfono de gerencia, hora y umbral de días).

Si no se configura un correo específico de informes, se usa primero `email_notificaciones` y luego el email general de la empresa.

Los informes nacen deshabilitados para no activar envíos al desplegar la migración.

## Aviso de equipos sin lectura por WhatsApp

Es un canal **aparte** del correo, con su propio destinatario: el teléfono de gerencia. El problema que resuelve es el escalamiento: a los choferes ya se les reclama (recordatorio automático los lunes, miércoles y viernes, y el botón "Reclamar por WhatsApp" de la pantalla de control de lecturas), pero cuando no suben la lectura, seguir reclamando al chofer no alcanza. El aviso le llega a quien puede ordenar.

Reglas del circuito:

- **Universo**: solo equipos activos que **controlan kilometraje** y cuya última lectura vigente es anterior al umbral. Un semirremolque no controla km: no tiene lectura que informar y nunca se cuenta.
- **Umbral**: por empresa (`informe_lecturas_dias`, 7 días por defecto). Es el mismo valor que usa el tablero y el mismo que usa el informe por correo, para que los dos canales hablen del mismo universo de equipos.
- **Frecuencia**: como máximo **un aviso por empresa por día**, con clave de entrega `informe_lecturas_gerencia:empresa:{id}:fecha:{YYYYMMDD}`. Si el problema sigue, la gerencia lo ve todos los días; si se resuelve, el aviso deja de aparecer solo.
- **Silencio**: si no hay equipos atrasados **no se manda nada**. Un aviso vacío entrena a la gerencia a ignorar el canal.
- **Modo piloto**: si está activo el piloto global de WhatsApp, el aviso sale al teléfono piloto y lleva el encabezado de prueba controlada, igual que el resto de las notificaciones del sistema.

El mensaje dice cuántos equipos son, cuántos días hace que no suben lectura, el último kilometraje registrado, el chofer asignado y un enlace al control de lecturas (con el filtro de antigüedad más cercano al umbral) para poder reclamar desde ahí.

Para activarlo: **Superadmin > Empresas > "Aviso de equipos sin lectura por WhatsApp"**, cargar el teléfono de gerencia en formato internacional (solo dígitos, por ejemplo `5491133333333`), habilitar el checkbox y guardar. Con **Probar aviso de lecturas** se manda uno de inmediato con los equipos atrasados de ese momento.

El teléfono se valida con la misma regla que el resto de los teléfonos de WhatsApp (`WhatsAppPhone::normalize`): si no es un móvil válido, la empresa no se guarda.

## Contenido

El informe separa dos dominios que antes se confundían bajo una sola tarjeta de "Vencimientos":

- **Documentación**: `Documentación vencida` y `Documentación próxima (30 días)`, calculadas sobre la tabla de vencimientos (`/mantenimiento/vencimientos`).
- **Mantenimiento preventivo**: `Preventivos vencidos` y `Preventivos próximos`, calculadas con `EvaluadorVencimiento` sobre los planes activos, es decir la misma regla de dominio que usa la pantalla de planes (`/mantenimiento/planes`). Un documento vencido nunca cuenta como preventivo.

El diario incluye además equipos activos, OT abiertas/demoradas, OT creadas/cerradas y equipos sin lectura reciente.

Estos últimos cuentan solo equipos que controlan kilometraje. Antes se contaban todos los equipos activos de la empresa: un semirremolque sin control de km entraba como "sin lectura" para siempre. Con el corte de 30 días era ruido menor; al bajar el umbral a 7 días el informe se llenaba de falsos positivos.

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
