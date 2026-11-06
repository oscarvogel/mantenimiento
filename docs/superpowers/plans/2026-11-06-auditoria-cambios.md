# Plan de implementación de Auditoría de cambios

> **For agentic workers:** este documento es un plan. No iniciar implementación de producto por su sola existencia. Requiere aprobación explícita y debe ejecutarse por fases pequeñas y verificables.

**Goal:** registrar de forma append-only las operaciones sensibles del sistema, con alcance explícito por `empresa_id` y `sucursal_id`, actor, valores anteriores/nuevos y contexto de la petición, y exponerlas en una pantalla filtrable respetando permisos y aislamiento multi-tenant.

**Architecture:** monolito modular CI4; `Presentation / Infrastructure → Application → Domain`. La auditoría es un puerto de escritura de `Application`, implementado por un adaptador en `Infrastructure`. Los casos de uso escriben la auditoría dentro de su propia transacción. Nada de triggers de base ni callbacks de modelo.

**Tech Stack:** PHP 8.2+, CodeIgniter 4.7.4, MariaDB/MySQL, Vue 3 + Vite + Tailwind existentes. Migraciones nuevas, sin editar las ya aplicadas.

**Spec:** `docs/ESPECIFICACION_SISTEMA_MANTENIMIENTO.md` §5.11 y §11; `docs/CONTRATOS_ENTRE_CONTEXTOS.md`; `docs/PENDING_REFACTOR_MULTITENANCY.md`; `docs/ESPECIFICACION_VIAJES_CIRCUITO_LIQUIDACION.md` para las operaciones de viajes.

## Global Constraints

- `staging = fasa_189 / Docker / Coolify`; `producción = Ferozo / FTPS / sin CLI`.
- No usar `php spark` remoto en producción. Las migraciones productivas siguen el procedimiento HTTP documentado en `docs/DEPLOY_FEROZO.md`.
- Ningún acceso a datos auditados puede quedar sin scope explícito de empresa y sucursal.
- La auditoría es histórica y append-only: no se actualiza ni se borra físicamente.
- No registrar secretos, contraseñas, tokens, certificados ni adjuntos completos.
- No auditar automáticamente todas las tablas. Solo operaciones sensibles con intención de negocio.
- Cambios incrementales. No mezclar este trabajo con refactors arquitectónicos amplios.
- Una tarea no termina si solo compila: requiere migración, pruebas y verificación HTTP real.

## Evidencia relevada el 06/11/2026

| Elemento | Estado observado |
|---|---|
| Tabla `auditoria` de la spec §5.11 | **No existe** migración que la cree |
| Puerto/servicio transversal de auditoría | **No existe** |
| Callbacks de modelo o triggers | **No existen** |
| Permiso `auditoria.ver` | Sembrado en `InitialSeeder.php` pero sin uso en controladores, rutas ni vistas |
| Historial de usuarios/roles/empresa | `usuario_acceso_historial`, escrito por `CodeIgniterOrganizationAdministration` y `CodeIgniterTenantAdministration` |
| Historial de OT | `orden_estado_historial` |
| Historial de sucursal de equipos | `equipo_sucursal_historial` |
| Corrección/anulación de lecturas | `lecturas_equipo` con `motivo_correccion`, `anulada_por`, `anulada_at`, `motivo_anulacion` |
| QR público | `qr_lecturas_auditoria` |
| Importación de choferes | `driver_assignment_import_audit` |
| Auditoría del chatbot | `conversaciones`, `mensajes`, `ChatbotAudit` |
| Movimientos de viajes | `viaje_operativo_movimientos_auditoria` |
| Autoría básica | `created_by`, `updated_by`, `deleted_at` en varias tablas operativas |

**Conclusión:** hay historiales de dominio y autoría, pero no una bitácora transversal de cambios. La brecha principal es auditoría de operaciones sensibles fuera de identidad y mantenimiento.

## Decisiones de diseño

### 1. Una sola tabla `auditoria`, con alcance explícito

La spec define `id, usuario_id, accion, entidad, entidad_id, valores_anteriores, valores_nuevos, ip, user_agent, fecha`. Este proyecto **agrega** `empresa_id`, `sucursal_id`, `actor_empresa_id`, `alcance`, `motivo` y `request_id` porque el aislamiento multi-tenant es una invariante de seguridad.

Semántica de campos:

| Campo | Significado |
|---|---|
| `empresa_id` | Empresa **afectada** por la operación. `NULL` solo si el alcance es `GLOBAL`. |
| `sucursal_id` | Sucursal **afectada**. `NULL` si el alcance es `EMPRESA` o `GLOBAL`. |
| `actor_empresa_id` | Empresa del actor al momento de la acción. `NULL` para superadministrador global o proceso sin actor. |
| `actor_usuario_id` | Usuario que ejecuta. `NULL` solo para procesos automáticos/CLI sin actor humano. |
| `alcance` | `GLOBAL`, `EMPRESA` o `SUCURSAL`. Evita ambigüedad de `NULL`. |
| `entidad_id` | `VARCHAR(64)` para soportar claves numéricas y compuestas. |
| `valores_anteriores` / `valores_nuevos` | JSON serializado y sanitizado. En altas, anteriores `NULL`. En bajas, nuevos `NULL`. |
| `accion` | Catálogo controlado: `crear`, `actualizar`, `eliminar`, `anular`, `reabrir`, `corregir`, `importar`, `iniciar_sesion`, `cerrar_sesion`, `cambiar_permisos`, etc. |
| `motivo` | Obligatorio para anulaciones, reaperturas, correcciones y cambios de permisos. |

Reglas de asignación de alcance:

- Alta o edición de empresa: `empresa_id = empresa afectada`, `sucursal_id = NULL`, `alcance = EMPRESA`, `actor_empresa_id = NULL` si lo hace un superadmin.
- Alta o edición de sucursal: `empresa_id = empresa de la sucursal`, `sucursal_id = sucursal`, `alcance = SUCURSAL`.
- Usuario/rol/permiso: `empresa_id = empresa del usuario afectado`; si el usuario es global, `empresa_id = NULL` y `alcance = GLOBAL`.
- Entidad con sucursal (equipo, lectura, OT, viaje): usar la sucursal de la entidad afectada.
- Operación sobre varias sucursales: escribir **una entrada por entidad afectada**, no una fila con `sucursal_id` ambiguo.
- Configuración global: `empresa_id = NULL`, `sucursal_id = NULL`, `alcance = GLOBAL`.

### 2. Append-only real

- Sin métodos `update`/`delete` en el repositorio.
- Sin `deleted_at` en `auditoria`.
- FKs con `RESTRICT`, nunca `CASCADE`.
- La verificación de inmutabilidad es de código y pruebas. Una cadena de hash (`prev_hash`/`entry_hash`) queda como endurecimiento opcional de fase 4, no como requisito v1.

### 3. La auditoría participa de la transacción del caso de uso

- Los handlers escriben la auditoría dentro del `UnitOfWork`, después de la mutación y antes del commit.
- Si la escritura de auditoría falla, la operación de negocio hace rollback (fail-closed) para operaciones sensibles.
- El adaptador usa la misma conexión `BaseConnection` que el repositorio, por lo que participa de la transacción vigente.
- Para importaciones y procesos batch, se registra un resumen por archivo/lote y, si aplica, detalle por entidad afectada con un límite configurable.

### 4. Puertos y capas

```text
Application/Audit/
  AuditEntry.php              DTO inmutable y validado
  AuditAction.php             catálogo de acciones (constantes o enum)
  AuditScope.php              GLOBAL | EMPRESA | SUCURSAL
  AuditValueSanitizer.php     redacción de secretos y truncado
  Port/AuditTrail.php         escritura (driven port)
  Port/AuditTrailReadModel.php consulta paginada (driven port)
  GetAuditTrail.php           caso de uso de consulta con scope
Infrastructure/Audit/
  CodeIgniterAuditTrail.php        adaptador de escritura
  CodeIgniterAuditTrailReadModel.php adaptador de lectura
  AuditRequestContext.php          IP, user-agent y request-id desde CI4
Presentation/
  Controllers/AuditTrail.php
  Views / payload Vue
```

`AuditEntry` no depende de CodeIgniter. El adaptador de infraestructura enriquece `ip`, `user_agent` y `request_id` desde la petición cuando no vienen explícitos; en CLI/cron quedan `NULL`.

### 5. Sanitización obligatoria

Antes de serializar `valores_anteriores`/`valores_nuevos`:

- Reemplazar por `"***"` claves que contengan: `password`, `pass`, `token`, `secret`, `api_key`, `apikey`, `private_key`, `smtp_password`, `certificado`, `authorization`, `cookie`.
- No guardar binarios ni contenido de adjuntos; guardar solo nombre, tipo, tamaño y hash.
- Truncar el JSON a un máximo configurable (por ejemplo 8 KB) y guardar un resumen con `_truncated: true`.
- Para actualizaciones, guardar solo campos modificados (diff). Para altas y bajas, snapshot completo sanitizado.

### 6. Consulta y aislamiento

Reglas del read model:

- Superadministrador global: ve todo, incluidos los eventos `GLOBAL`.
- Usuario con todas las sucursales de su empresa: ve los eventos `empresa_id = su empresa`, incluyendo `alcance = EMPRESA`.
- Usuario limitado a sucursales: ve solo eventos con `sucursal_id IN (sucursales autorizadas)`. No ve eventos `EMPRESA` ni `GLOBAL`.
- Ningún usuario común puede ver eventos de otra empresa.
- Filtros: rango de fechas, empresa (solo superadmin), sucursal, actor, acción, entidad, entidad_id y texto.
- Paginación obligatoria; exportación CSV queda para fase posterior.

## Esquema de migración

Archivo nuevo: `app/Database/Migrations/2026-11-06-120000_CreateAuditoriaTable.php`.

| Columna | Tipo | Nulo | Notas |
|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | no | PK |
| `empresa_id` | INT UNSIGNED | sí | FK `empresas.id` `RESTRICT` |
| `sucursal_id` | INT UNSIGNED | sí | FK `sucursales.id` `RESTRICT` |
| `actor_usuario_id` | INT UNSIGNED | sí | FK `usuarios.id` `RESTRICT` |
| `actor_empresa_id` | INT UNSIGNED | sí | FK `empresas.id` `RESTRICT` |
| `alcance` | VARCHAR(10) | no | `GLOBAL`, `EMPRESA`, `SUCURSAL` |
| `accion` | VARCHAR(60) | no | catálogo controlado |
| `entidad` | VARCHAR(60) | no | nombre de tabla/agregado |
| `entidad_id` | VARCHAR(64) | sí | id afectado |
| `valores_anteriores` | LONGTEXT | sí | JSON sanitizado |
| `valores_nuevos` | LONGTEXT | sí | JSON sanitizado |
| `motivo` | VARCHAR(255) | sí | obligatorio según acción |
| `ip` | VARCHAR(45) | sí | IPv4/IPv6 |
| `user_agent` | VARCHAR(255) | sí | truncado |
| `request_id` | CHAR(36) | sí | correlación |
| `fecha` | DATETIME | no | momento de la acción |
| `created_at` | DATETIME | no | alta de la fila |

Índices:

- `(empresa_id, sucursal_id, fecha)` — consulta tenant principal.
- `(entidad, entidad_id)` — historial de una entidad.
- `(actor_usuario_id, fecha)` — actividad de un usuario.
- `(accion, fecha)` — filtros operativos.
- `(request_id)` — correlación de una request.

Integridad de sucursal: para garantizar que la sucursal pertenece a la empresa, agregar en la misma migración un índice único `(empresa_id, id)` en `sucursales` (si no existe) y una FK compuesta `(empresa_id, sucursal_id) → sucursales(empresa_id, id)`. Si se opta por no tocar `sucursales`, validar la pertenencia en el caso de uso y cubrirlo con prueba de integración.

`down()`: eliminar índices/FK compuesta agregados y luego `DROP TABLE auditoria`. No eliminar datos de negocio.

## Fases de implementación

### Fase 0 — Fundación transversal

**Propietario:** Arquitectura e integración. **Calidad:** pruebas de contrato y de scope.

- [ ] Crear migración `CreateAuditoriaTable` con esquema e índices indicados.
- [ ] Crear `AuditScope`, `AuditAction`, `AuditEntry` y `AuditValueSanitizer` en `Application/Audit`.
- [ ] Crear puerto `AuditTrail` y adaptador `CodeIgniterAuditTrail`.
- [ ] Registrar ambos en `app/Config/Services.php`.
- [ ] Implementar `AuditRequestContext` para IP, user-agent y request-id, con degradación segura en CLI.
- [ ] Probar: validación de `AuditEntry`, sanitización de secretos, truncado, inserción con FKs, rollback si falla la auditoría.
- [ ] Documentar el catálogo de acciones y entidades en `docs/CONTRATOS_ENTRE_CONTEXTOS.md`.

**Criterio de aceptación:** un caso de uso de prueba escribe una fila con `empresa_id`, `sucursal_id`, actor y diff; si la inserción falla, la transacción completa hace rollback.

### Fase 1 — Identidad y organización (prioridad alta)

**Propietario:** Organización, identidad y acceso.

- [ ] Auditar altas, ediciones, bajas y reactivaciones de `empresas`.
- [ ] Auditar altas, ediciones, bajas y cambios de estado de `sucursales`.
- [ ] Auditar usuarios: alta, baja, reactivación, reset de contraseña y cambio de email.
- [ ] Auditar asignación y revocación de roles (`usuario_roles`) y sucursales (`usuario_sucursales`).
- [ ] Auditar cambios de `rol_permisos`.
- [ ] Exigir `motivo` en cambios de permisos, bajas y resets.
- [ ] Consolidar la escritura de identidad en `auditoria` y dejar de escribir en `usuario_acceso_historial`. La tabla legacy se conserva intacta con sus filas históricas.
- [ ] No auditar login/logout en `auditoria`; ese circuito sigue en `usuario_acceso_historial`.
- [ ] Probar que un administrador de empresa no puede auditar ni ver eventos de otra empresa.

**Criterio de aceptación:** todo cambio de acceso queda registrado con actor, empresa afectada, valores anteriores/nuevos y motivo cuando corresponde.

### Fase 2 — Activos y mantenimiento

**Propietario:** Registro de activos, Medición de uso, Mantenimiento preventivo y Ejecución.

- [ ] Auditar bajas y transferencias de equipos (complementar `equipo_sucursal_historial`).
- [ ] Auditar cambios de datos técnicos de equipo y relaciones tractor-acoplado.
- [ ] Auditar creación, edición de bases y baja de planes preventivos.
- [ ] Auditar reapertura o anulación de OT, cambio de costos y cierre con datos sensibles.
- [ ] Auditar corrección y anulación de lecturas, reforzando el motivo y el vínculo con la lectura original.
- [ ] Probar que una corrección de lectura y la actualización del equipo quedan en la misma transacción.

**Criterio de aceptación:** las operaciones que la spec marca como sensibles en mantenimiento quedan trazadas sin duplicar los historiales de dominio existentes.

### Fase 3 — Viajes y dinero

**Propietario:** Viajes y costos operativos.

- [ ] Auditar cierre de viaje y cambios posteriores al cierre.
- [ ] Auditar alta, edición y anulación de gastos, combustible, caja y otros haberes.
- [ ] Auditar cambios de porcentajes de liquidación y de escenarios de costo teórico.
- [ ] Auditar confirmación y ajustes de importaciones de viajes/facturas.
- [ ] Reutilizar `viaje_operativo_movimientos_auditoria` para movimientos operativos y `auditoria` para la operación de negocio; documentar la frontera para no duplicar.

**Criterio de aceptación:** un cambio de costos o liquidación es reconstruible desde la auditoría sin consultar logs de aplicación.

### Fase 4 — Consulta, UI y endurecimiento

**Propietario:** Reportes y auditoría / Plataforma y entrega.

- [ ] Crear `GetAuditTrail` y `AuditTrailReadModel` con scope por actor.
- [ ] Crear controlador `AuditTrail` y rutas `mantenimiento/auditoria` (GET listado y detalle).
- [ ] Agregar la entrada "Auditoría" en `AppShellPayload` solo con `auditoria.ver`.
- [ ] Incorporar la entrada al catálogo de módulos si corresponde.
- [ ] Crear página Vue de consulta con filtros, paginación y detalle de diff.
- [ ] Excluir secretos y datos sensibles de la vista.
- [ ] Evaluar cadena de hash y política de retención/archivo como mejora posterior.

**Criterio de aceptación:** superadmin ve todo; administrador de empresa ve solo su empresa; usuario limitado a sucursal ve solo sus sucursales; sin acceso cruzado.

## Pruebas obligatorias

1. **Unidad:** `AuditEntry` rechaza `alcance`/campos incoherentes; sanitizador redacta secretos; diff solo incluye campos modificados.
2. **Unidad:** `GetAuditTrail` aplica scope correcto con un read model falso.
3. **Integración:** inserción con `empresa_id`/`sucursal_id` válidos; FK compuesta rechaza sucursal de otra empresa.
4. **Integración:** si la inserción de auditoría falla, la operación de negocio hace rollback.
5. **Feature:** ruta protegida por `auditoria.ver`; sin permiso devuelve 403/redirección.
6. **Feature:** superadmin ve `GLOBAL`; administrador de empresa no ve `GLOBAL` ni otras empresas; usuario de sucursal no ve otras sucursales.
7. **Regresión:** cada acción instrumentada en fases 1–3 genera exactamente una entrada, sin duplicados.
8. **Seguridad:** ninguna entrada contiene `password`, tokens, certificados ni rutas privadas.

## Qué no auditar

- Catálogos de alta frecuencia: `provincias`, `localidades`, `paises`, `marcas`, `modelos`, `tipos_equipo`, `tipos_servicio`, `tipos_vencimiento`.
- Muestras y buckets de telemetría (`telematia_muestras`, `telematia_buckets`).
- Notificaciones y mensajes individuales, salvo configuración global o cambios de destinatarios.
- Tablas de unión sin regla de negocio propia, salvo que representen permisos o asignaciones.
- Lecturas de solo consulta y navegación.

## Riesgos y bordes

| Riesgo | Mitigación |
|---|---|
| Auditoría incompleta por olvido de un caso de uso | Checklist por contexto + prueba de regresión por acción |
| Fuga de secretos en `valores_*` | Sanitizador central + pruebas de seguridad + revisión de PR |
| Crecimiento excesivo | Auditar solo operaciones sensibles; truncado; política de archivo posterior |
| Falla de auditoría tumba la operación | Decisión explícita fail-closed para operaciones sensibles; documentar excepciones |
| Superadmin sin empresa | `alcance = GLOBAL`, `empresa_id = NULL`, `actor_empresa_id = NULL`; nunca inferir tenant de la sesión |
| Actor de proceso automático | `actor_usuario_id = NULL`; registrar proceso en `motivo` o `request_id` |
| Evento multi-sucursal | Una fila por entidad afectada; nunca `sucursal_id` ambiguo |
| Consulta cruzada entre empresas | Scope obligatorio en el read model + pruebas de integración |
| Duplicar historiales existentes | Documentar frontera entre `auditoria` e historiales de dominio |

## Decisiones confirmadas el 06/11/2026

1. **Fail-closed:** aprobado. Si la auditoría no se puede escribir, la operación sensible hace rollback.
2. **Login/logout:** aprobado no duplicar. La autenticación sigue en `usuario_acceso_historial`; no se escribe en `auditoria` por login/logout.
3. **Consolidación de identidad:** las operaciones de usuarios, roles, empresas y sucursales dejan de escribir en `usuario_acceso_historial` y escriben únicamente en `auditoria`. La tabla legacy se conserva con sus filas históricas, sin borrarse.
4. **Ubicación UI:** `mantenimiento/auditoria`, siguiendo el patrón de módulos existentes (fase 4).
5. **Retención:** conservar todo en v1. El archivo y la cadena de hash se evalúan en fase 4.
6. **FK compuesta de sucursal:** se implementa. La migración agrega un índice único `(empresa_id, id)` en `sucursales` y una FK compuesta desde `auditoria` para impedir sucursales de otra empresa.

## Estado de implementación al 06/11/2026

**Fase 0 completada.** Migración `CreateAuditoriaTable`, `AuditScope`, `AuditAction`, `AuditEntry`, `AuditValueSanitizer`, puerto `AuditTrail`, adaptador `CodeIgniterAuditTrail` y `AuditRequestContext`. Registrado en `Services::auditTrail()`.

**Fase 1 parcial.** Instrumentadas altas y ediciones de empresas y sucursales; altas, ediciones, cambios de empresa, asignación de roles/acceso y restablecimiento de contraseña de usuarios. La escritura de identidad se consolidó en `auditoria`; `usuario_acceso_historial` queda como tabla legacy con sus filas históricas. No se audita login/logout.

En Organización la transacción vive en el adaptador de infraestructura, no en el handler. Por eso el adaptador invoca `AuditTrail` dentro de su propia transacción. No se movió la transacción a `Application` para no mezclar este corte con un refactor amplio.

**Pendiente de Fase 1:** cambios de `rol_permisos`.

**Fase 4 parcial (06/11/2026).** Implementados `GetAuditTrail`, `CodeIgniterAuditTrailReadModel`, el endpoint `GET /superadmin/auditoria` y la pestaña **Auditoría de cambios** en el menú global del superadmin. Por ahora la consulta es exclusiva del superadministrador global, sin filtro por permiso `auditoria.ver`. La generalización a administradores de empresa y usuarios por sucursal queda pendiente.

**Pruebas:** suite PHPUnit completa en verde (`1696` pruebas, `6751` aserciones, `45` omitidas). Frontend Vitest completo en verde (`457` pruebas). `npm run build` y la QA de encoding pasan. Nuevas pruebas: validación de `AuditEntry`, sanitización/truncado, persistencia del adaptador, fail-closed, caso de uso y read model de consulta, regresión de ruta/payload y pantalla Vue.

**Migración en staging:** aplicada el 06/11/2026 en el contenedor `bdqictyu4q5xkfuh6aoh7sr8-20261105T194620`, base `mantenimiento_staging`, registrada como `2026-11-06-120000 CreateAuditoriaTable`. Se verificó la tabla `auditoria`, sus índices y la FK compuesta `fk_auditoria_sucursal_tenant`. La migración usa DDL de MySQL (`AUTO_INCREMENT`, FKs compuestas) y no corre sobre SQLite.

**Verificación pendiente:** desplegar la rama con el código de la pantalla en staging y ejecutar un flujo HTTP real de alta/edición de usuario, verificando la fila en `auditoria`. La migración ya está aplicada, pero el contenedor actual todavía no tiene el código nuevo.

## Definition of Done

- Migración reproducible con `down()` seguro.
- Puerto y adaptador con pruebas unitarias e integración.
- Operaciones sensibles de las fases acordadas escriben auditoría en la misma transacción.
- Permiso `auditoria.ver` efectivo y scope multi-tenant probado.
- Pantalla de consulta con filtros y detalle, sin secretos.
- Suite PHPUnit en verde y verificación HTTP real en staging `fasa_189`.
- Documentación de contratos, catálogo de acciones y operación actualizada.
