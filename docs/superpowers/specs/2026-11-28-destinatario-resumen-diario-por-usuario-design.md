# Diseño — Destinatario del resumen diario por usuario, separado del login

**Fecha:** 28 de noviembre de 2026
**Estado:** Aprobado e implementado el 28/11/2026. Ejecución verificada localmente (PHPUnit/Vitest/build locales; sin HTTP en staging, sin verificación visual/móvil, sin piloto con usuarios reales); pendiente de verificación en `fasa_189` y de carga de las direcciones confirmadas por el usuario.
**Bounded contexts:** Notifications (dueño del despacho) e Identidad/Organización (dueño de `usuarios`).
**Decisión del usuario:** los correos con los que Mariela y Carlos ingresan deben permanecer tal cual. El resumen diario debe poder enviarse a otras direcciones sin modificar esas credenciales.

---

## 1. Problema y causa raíz

El resumen diario por usuario toma el destinatario de `usuarios.email`:

- `app/Infrastructure/Notifications/CodeIgniterNotificationRecipientResolver.php:25,45` selecciona `u.email` y construye `NotificationRecipient` con ese valor.
- `app/Infrastructure/Notifications/CodeIgniterNotificationDeliveryQueue.php:113-122` vuelve a leer `u.email` al resolver entregas pendientes.
- `app/Controllers/Login.php:50-54` usa `UsuarioModel::findByEmail()` para autenticar.

Por lo tanto, cambiar el correo para arreglar el resumen cambia también el usuario de ingreso. No existe hoy un correo de notificación por usuario.

Los otros dos destinatarios ya están separados y **no se modifican**:

- Alertas operativas de empresa: `empresas.email_notificaciones`, con fallback a `empresas.email` (`CompanyNotificationRecipientPolicy`).
- Informes gerenciales: `empresas.emails_informes` → `email_notificaciones` → `email` (`ScheduleManagementReports::recipients()`).

Tampoco interviene `sucursales.email_alertas`: hoy solo se edita en administración de sucursales y no participa del despacho.

---

## 2. Alcance

### Incluido

- Nuevo campo opcional de correo de resumen por usuario.
- Política única para resolver el destino, con fallback al email de ingreso.
- Snapshot del destinatario en las entregas de correo de usuario.
- Alta y edición del campo en el ABM de usuarios del tenant.
- Pruebas unitarias, de contrato y de regresión HTTP.
- Migración reproducible y documentación operativa.

### Explícitamente excluido

- Cambiar `usuarios.email` de Mariela, Carlos o cualquier otro usuario como parte de este trabajo.
- Crear una tabla de destinatarios múltiples por usuario.
- Modificar informes gerenciales, alertas de empresa, SMTP global, WhatsApp ni preferencias de canal.
- Modificar el motor de vencimientos, órdenes, lecturas ni ninguna otra regla de dominio.
- Tocar producción Ferozo en esta etapa de diseño/implementación local.

---

## 3. Arquitectura y dependencias

Se mantiene la dirección `Presentation / Infrastructure → Application → Domain`.

- **Application (Notifications):** nueva política `UserNotificationEmailPolicy`, con la regla de precedencia. Es una decisión de entrega, no una regla de mantenimiento, por lo que no entra en `Domain`.
- **Application (port):** `NotificationDeliveryQueue::schedule()` recibe un parámetro opcional `?string $deliveryEmail = null` para poder guardar el snapshot sin romper implementaciones existentes.
- **Infrastructure (Notifications):** `CodeIgniterNotificationRecipientResolver` y `CodeIgniterNotificationDeliveryQueue` consumen la política y persisten/leen el destinatario.
- **Presentation:** `TenantAdmin` y `UsersAdminPage.vue` exponen el campo nuevo con lenguaje diferenciado.
- **Composition root:** `app/Config/Services.php` inyecta la política en el resolver.

No se agrega un evento de dominio, CQRS ni consistencia eventual: el correo sigue siendo parte del mismo ciclo transaccional de notificaciones.

---

## 4. Datos

### 4.1 Migración nueva correctiva

No se editan migraciones ya aplicadas.

| Tabla | Columna | Tipo | Regla |
|---|---|---|---|
| `usuarios` | `email_notificaciones` | `VARCHAR(255) NULL` | Correo de entrega del resumen. No es credencial. |
| `notificacion_entregas` | `destinatario` | `VARCHAR(255) NULL` | Foto del correo efectivo al encolar. `NULL` significa “resolver con la política vigente”. |

`down()` elimina ambas columnas sin borrar filas ni modificar `usuarios.email`.

### 4.2 Política de destino

`UserNotificationEmailPolicy::resolve(?string $notificationEmail, string $loginEmail): string`:

1. Normaliza ambos valores con `trim()` y `mb_strtolower()`.
2. Si `email_notificaciones` pasa `FILTER_VALIDATE_EMAIL`, devuelve ese valor normalizado.
3. Si no, devuelve el `email` de ingreso normalizado (comportamiento actual).
4. Nunca escribe sobre el correo de ingreso.

La validación de formato en la capa web es `permit_empty|valid_email|max_length[255]`. La política es la única fuente de la precedencia; los adaptadores no repiten el `if`.

---

## 5. Flujo de extremo a extremo

1. Un evento notificable se publica con `PublishNotifiableEvent::execute()`.
2. `CodeIgniterNotificationRecipientResolver::resolve()` obtiene `u.email_notificaciones` y `u.email`, aplica la política y entrega un `NotificationRecipient` cuyo `email` significa **correo de entrega**.
3. `PublishNotifiableEvent` pasa ese correo a `NotificationDeliveryQueue::schedule()`.
4. `CodeIgniterNotificationDeliveryQueue::enqueue()` guarda `destinatario` junto con la clave de entrega existente. La clave lógica no cambia: sigue siendo `eventKey:usuario:{id}:email`, por lo que no se generan duplicados.
5. `due()` lee `COALESCE(d.destinatario, <resultado de la política>)` y entrega el correo al despachador. Las entregas antiguas con `destinatario IS NULL` siguen resolviéndose en vivo con la política; no se reescriben ni se reenvían.
6. `RunNotificationDispatch` agrupa por destinatario + sucursal y llama a `EmailNotificationGateway::sendDigest()` como hasta ahora.
7. El teléfono del resumen diario por WhatsApp continúa saliendo de `usuarios.telefono`.

---

## 6. Pantalla y mensajes

En `Administración → Usuarios` (tenant) se muestran:

- **Email de ingreso**: campo actual, obligatorio. Conserva su ayuda sobre inicio de sesión.
- **Correo para resúmenes**: campo nuevo, opcional. Ayuda exacta: *“Si se deja vacío, los resúmenes llegan al email de ingreso. Cambiar este correo no modifica el ingreso.”*

Archivos: `TenantAdmin.php` (reglas y alta/edición), `AdministrationPayload.php` (payload), `UsersAdminPage.vue` (formulario) y `UsuarioModel::$allowedFields`.

El mensaje de éxito distingue los dos casos:

- Si cambió solo `email_notificaciones`: *“Usuario actualizado. El correo de resumen cambió; el ingreso no se modificó.”*
- Si cambió `email`: se mantiene la advertencia actual de que desde ahora se ingresa con el nuevo correo.

---

## 7. Manejo de errores y trazabilidad

- Un correo de resumen inválido se rechaza en la validación web con mensaje comprensible y no se persiste.
- Si llega inválido a la política por datos heredados, se aplica fallback al correo de ingreso y se registra un `log_message('notice', ...)` con el `usuario_id` y el motivo; nunca se lanza una excepción que detenga el ciclo.
- El destinatario efectivo queda guardado por entrega, cumpliendo la regla de la especificación de registrar destinatario y contenido lógico enviado.
- El fallo SMTP, los reintentos y la idempotencia no cambian.

---

## 8. Pruebas

1. **Política pura:** correo propio válido; vacío; inválido; fallback; normalización de espacios y mayúsculas.
2. **Resolver con dobles:** el correo del `NotificationRecipient` es el de resumen cuando existe, y el de ingreso cuando no.
3. **Cola con dobles:** `enqueue()` persiste `destinatario`; `due()` lo usa y cae a la política cuando está `NULL`.
4. **Contrato de puertos:** la nueva firma opcional no rompe adaptadores ni dobles existentes.
5. **Regresión HTTP:** cambiar `email_notificaciones` no altera `findByEmail()`; cambiar `email` sí.
6. **Esquema SQLite de tests:** replica los tipos reales de `VARCHAR(255) NULL`, según la regla obligatoria de `AGENTS.md`.
7. **Suite completa:** lint, PHPUnit sin cobertura y verificación HTTP real en el entorno de prueba autorizado.

---

## 9. Datos operativos y despliegue

Antes de cargar las direcciones de Mariela y Carlos se debe confirmar por escrito:

1. Correo de ingreso que debe quedar vigente para cada usuario (no se modifica).
2. Correo al que debe llegar el resumen diario de cada uno.

Con esos valores se actualiza solo `usuarios.email_notificaciones` mediante el ABM o una sentencia acotada por `usuario_id` y `empresa_id`. No se usa el correo de ingreso como destino nuevo.

La verificación en entorno de prueba se hace exclusivamente en el stack `mantenimiento-staging` de Docker/Coolify sobre `fasa_189`, previa identificación del contenedor y sin reutilizar credenciales históricas. Producción Ferozo no tiene CLI: la migración se aplica por el panel y se valida con un smoke HTTP. No se despliega nada a producción sin aprobación explícita.

---

## 10. Criterios de aceptación

- El resumen de Mariela y Carlos llega a las direcciones confirmadas.
- Sus correos de ingreso siguen siendo los mismos y pueden iniciar sesión con ellos.
- Cualquier otro usuario sin correo de resumen sigue recibiendo el diario en su email de ingreso.
- El destinatario de cada entrega queda registrado.
- Dos ejecuciones del ciclo no duplican correos.
- La suite automatizada pasa y la pantalla es comprensible en escritorio y móvil.

---

## 11. Seguimiento

Una vez revisado y aprobado este documento, el siguiente paso es el plan de implementación (`writing-plans`). No se escribe código de producción hasta que ese plan exista y sea aprobado.
