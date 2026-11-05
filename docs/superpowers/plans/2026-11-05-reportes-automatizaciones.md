# Plan de implementación de Reportes y Automatizaciones

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. No iniciar implementación de producto por la sola existencia de este documento.

**Goal:** habilitar dos módulos útiles desde `/inicio`, aprovechando reportes y procesos existentes, con datos verificables y aislamiento por empresa y sucursal.

**Architecture:** monolito modular CI4; Presentation/Infrastructure → Application → Domain. Reportes consume contratos de lectura y Automatizaciones coordina capacidades existentes de Notifications y Telematic. No duplicar motores ni trasladar reglas de negocio a controladores o componentes Vue.

**Tech Stack:** PHP 8.2+, CodeIgniter 4.7.4, MariaDB/MySQL, Vue 3 con Composition API, Vite y Tailwind existentes. La UI ejecutable usa Vue; conservar el montaje actual dentro de las vistas CI4.

**Spec:** `docs/ESPECIFICACION_SISTEMA_MANTENIMIENTO.md`, especialmente §§8, 11, 13 y 18; `docs/CONTRATOS_ENTRE_CONTEXTOS.md`; propuesta por etapas aceptada como base en el chat del 05/11/2026. Las ampliaciones posteriores de este plan siguen siendo propuestas, no autorización para implementar todos los módulos dependientes.

## Global Constraints

- `staging = fasa_189 / Docker / Coolify`; `producción = Ferozo / FTPS / sin CLI`.
- Coolify, Docker, SSH, Composer por consola y `php spark` son herramientas exclusivamente de staging/desarrollo. Ninguna entrega puede requerirlas en producción Ferozo.
- El release productivo se prepara y compila fuera de Ferozo y se transfiere por FTPS. Las migraciones productivas usan el procedimiento HTTP autenticado y efímero documentado en `docs/DEPLOY_FEROZO.md`, retirando el helper al terminar; nunca `php spark` remoto. Los procesos programados usan el endpoint HTTPS protegido y el panel Ferozo; no un worker Docker ni SSH.
- El portal autenticado `/inicio` es el punto de entrada y origen de navegación de los módulos.
- Los reportes deben respetar permisos y sucursales autorizadas.
- Los indicadores no deben calcularse si faltan los datos mínimos; deben mostrar `sin datos suficientes` en lugar de inventar un valor.
- No eliminar físicamente información histórica de mantenimiento.
- No generar órdenes masivas sin revisión.
- Cambios incrementales; migraciones nuevas, sin editar las aplicadas; preservar volúmenes y cambios locales.
- Distinguir seguimiento de procesos de ejecución con efectos externos. Este plan no autoriza envíos a destinatarios reales.
- No incorporar constructor libre de reportes o automatizaciones, brokers, Event Sourcing ni nueva plataforma de scheduling.

## Evidencia obtenida el 05/11/2026

Inspección por SSH al alias `fasa_189`, configurado a `192.168.0.189`. El hostname real responde `fasa-PowerEdge-T40`; el alias no es el hostname del SO.

| Elemento | Resultado observado |
|---|---|
| Aplicación Coolify | `mantenimiento-staging`, proyecto `mantenimiento`, entorno `staging` |
| Stack | `bdqictyu4q5xkfuh6aoh7sr8` |
| Contenedor | `bdqictyu4q5xkfuh6aoh7sr8-20261105T140601` |
| Imagen / SOURCE_COMMIT | `eedc249f869bd591274edafc69b9cf32861bac60` |
| Checkout local al relevamiento anterior | `79378405`; no asumir igualdad completa de árboles |
| Entrada | HTTPS mediante proxy Coolify; puerto interno 80, sin publicación directa 8090 |
| Host de staging | `bdqictyu4q5xkfuh6aoh7sr8.186.5.245.12.sslip.io` |
| Volúmenes | `…-writable` → `/var/www/html/writable`; `…-priv` → `/data/priv` |
| Entorno CI4 | `development`, coherente con staging; etiqueta Coolify `staging` |
| Compose | leído desde el mount de Coolify: `/var/www/html/storage/app/applications/bdqictyu4q5xkfuh6aoh7sr8/docker-compose.yaml` |
| Migraciones | `php spark migrate:status` respondió correctamente y muestra aplicada `2026-11-05-090000 AddTripEconomicsPermission` |
| HTTP sin sesión | petición a `/login` por HTTP con Host de staging respondió 302; no equivale a validación HTTPS ni login autenticado |
| Runner de tests | carpeta `tests` presente; `vendor/bin/phpunit` no detectado por la comprobación de archivo |

SHA-256 iguales entre local y contenedor para `GetPlatformModuleCatalog.php`, `GetMaintenanceReport.php` y `RunNotificationCycle.php`. Esto verifica esos tres archivos, no el despliegue completo.

No se hicieron despliegues, migraciones de escritura, ejecuciones de notificaciones ni modificaciones de datos. No se validaron todavía pantallas autenticadas, entregabilidad ni scheduler activo. Los cambios locales no relacionados se preservaron.

## Estado funcional y decisiones

1. `GetPlatformModuleCatalog` conserva `reports` y `automations` como futuras. Sin embargo `/reportes` y `/reportes/exportar` ya existen con `reportes.ver`.
2. Reportes ya dispone de resumen, costos, calidad y órdenes en `frontend/src/pages/reports/ReportsPage.vue`. No reemplazarlo por una pantalla vacía.
3. El read model combina ventanas distintas: abiertas actuales, distribución por apertura y costos/finalizaciones por cierre. La UI y el CSV deben explicar qué universo usa cada uno; no forzar que sumen lo mismo.
4. MTTR actual usa inicio y finalización del correctivo. Antes de llamarlo tiempo real de reparación, revisar el tratamiento de pausas/esperas contra la definición de la especificación.
5. Notifications ya resuelve detección, eventos, programación de informes, colas y despacho. Su bloqueo visible en `RunNotificationDispatch` cubre el despacho, no demuestra exclusión del ciclo global completo.
6. `ejecuciones_programadas` tiene control global por proceso. No tiene por sí sola detalle tenant suficiente para mostrarla a una empresa.
7. `ScheduleManagementReports` accede actualmente a DB e infraestructura desde Application. Corregir ese límite cuando se reutilice su información para nuevas pantallas; evitar un refactor masivo incidental.
8. Gerencial conserva análisis y escenarios de costo teórico; Reportes reúne consultas verificables y exportaciones. Automatizaciones muestra y configura procesos; la campana conserva las notificaciones del usuario.

## Review Focus

- Usuario con permiso de reportes sin permiso de viajes: no obtiene enlaces ni datos de viajes; probar en entrega 1.
- Misma fecha de apertura/cierre en límites del período: resultados y CSV siguen su criterio temporal documentado; probar en entrega 1.
- Usuario de empresa A o sucursal restringida: no ve ejecuciones globales ni entregas ajenas; probar en entrega 2.
- Ejecución interrumpida antes del despacho o tras aceptar el proveedor un mensaje: estado parcial visible y reintento sin duplicación evitable; probar en entrega 3.
- Costos de repuestos, adelantos y viajes importados: no se suman dos veces ni se mezclan con el operativo; probar en entrega 4.

## Entrega 0 — completar aceptación de la base

**Propietario:** Plataforma y entrega. **Integrador:** Arquitectura e integración. Calidad verifica evidencias. Son responsabilidades; este documento no crea agentes ni chats.

**Archivos de referencia:** `app/Config/Routes.php`, `app/Config/Services.php`, `app/Application/Platform/GetPlatformModuleCatalog.php`, `docs/notificaciones-cron-ferozo.md`.

- [ ] Verificar HTTPS y login con una cuenta de prueba existente autorizada; no crear cuentas o resetear contraseñas para eludir acceso.
  - **Pendiente.** Se verificó HTTPS y que `/login` responde 200; `/inicio`, `/reportes`, `/reportes/exportar` y `/notificaciones` redirigen a login cuando no hay sesión. No se obtuvo una cuenta autorizada en este chat, así que no se validó login autenticado ni se capturaron pantallas. No se crearon cuentas ni se resetearon contraseñas.
- [ ] Recorrer `/inicio`, `/reportes`, exportación y configuración de notificaciones; capturar escritorio y móvil sin datos sensibles.
  - **Pendiente.** Requiere la cuenta del punto anterior y un navegador autenticado.
- [x] Relevar solo lectura de configuraciones efectivas y resultados agregados de procesos, sin imprimir secretos ni destinatarios reales.
  - Se consultó la DB de staging en solo lectura: 3 empresas, 6 usuarios, 4 sucursales, 357 notificaciones y agregados de entregas por canal/estado. No se imprimieron teléfonos, correos, tokens ni claves.
- [x] Registrar si existe scheduler y cuál es su frecuencia real. No inferir que cron está activo por existir el endpoint o una variable habilitada.
  - Coolify tiene la tarea `Notificaciones diarias` (`php spark notifications:dispatch`, `0 10 * * *` UTC = 07:00 ART), habilitada. La DB muestra ejecuciones diarias a las 07:00; faltó el 04/11/2026. No hay tarea de cinco minutos en este staging. La documentación del cron se actualizó con este dato real y con la separación entre frecuencia configurada y evidencia observada.
- [x] Verificar aislamiento de canales de staging antes de cualquier prueba de envío. El nombre exacto de la política piloto debe obtenerse del código, no adivinarse.
  - `CodeIgniterGlobalNotificationSettingsStore::forcesPilotMode()` mantiene el piloto encendido fuera de producción. En staging `CI_ENVIRONMENT=development`, por lo que el piloto no se puede apagar; en la DB `whatsapp_pilot_enabled=1` y hay teléfono piloto configurado. SMTP está deshabilitado y WhatsApp está deshabilitado; Web Push figura habilitado. No se disparó ningún envío.
- [x] Preparar runner de calidad en Docker remoto de prueba con dependencias de desarrollo, DB y volúmenes desechables distintos del stack persistente. No ejecutar migraciones desde cero sobre la DB del staging compartido.
  - Runner en `fasa_189` sobre `/home/fasa/mantenimiento-test-runner` con imagen `mantenimiento-test-runner:local`, MariaDB descartable `mantenimiento-test-db` (volumen `mantenimiento-test-db-data`) y runner Node 22 para frontend. El staging persistente no se migró ni se recreó.
- [x] Registrar baseline de pruebas actual; el número histórico de 1497 no acredita el checkout de noviembre.
  - Baseline al iniciar: 1631 pruebas, 6533 aserciones, 44 omitidas, 0 fallos. Después de las entregas 1–3: 1669 pruebas, 6686 aserciones, 45 omitidas, 0 fallos. El test MariaDB adicional del lector corre aparte contra la DB descartable.

**Salida:** evidencia de navegación y matriz existente / configurable / pendiente / bloqueado. La falta de credenciales o runner se registra como pendiente, sin declarar aceptación completada.

## Entrega 1 — Reportes operativo desde el portal

**Propietario:** Reportes y auditoría; contrato de acceso coordinado con Identidad.

**Modificar:** `app/Application/Platform/GetPlatformModuleCatalog.php`, `app/Controllers/Reports.php`, `frontend/src/pages/reports/ReportsPage.vue`, `frontend/src/pages/platform/CommandCenterPage.test.js` si corresponde al contrato del catálogo.

**Crear:** `app/Application/Reports/GetReportCatalog.php`, `frontend/src/pages/reports/ReportCatalog.vue`, `tests/unit/Application/Reports/GetReportCatalogTest.php`, `frontend/src/pages/reports/ReportsPage.test.js`.

**Ampliar tests:** `tests/unit/Application/Platform/GetPlatformModuleCatalogTest.php`, `tests/unit/Application/Reports/GetMaintenanceReportTest.php`, `tests/unit/Infrastructure/Reports/CodeIgniterMaintenanceReportReadModelTest.php`, `tests/feature/RouteResolutionTest.php`.

**Interfaces:** `GetReportCatalog::execute(ActorContext $actor): array` devuelve entradas `{key,label,description,url,state}`. URLs construidas por servidor. Mantener firmas de `GetMaintenanceReport::execute(...)` y `export(...)` y las rutas actuales.

- [x] Agregar pruebas que fallen: `reportes.ver` habilita la tarjeta, sin permiso queda restringida, superadmin no adquiere acceso tenant implícito, viajes se ofrece solo con su permiso.
  - `GetReportCatalogTest`, ampliación de `GetPlatformModuleCatalogTest` y `ReportsPage.test.js`.
- [x] Ejecutar los tests específicos y comprobar el fallo esperado antes del cambio.
  - Faltaba `GetReportCatalog` y la tarjeta de reportes seguía en `not_implemented`; se observó el fallo antes de implementar.
- [x] Habilitar la entrada existente `/reportes`; agregar catálogo y retorno a `/inicio` conservando el informe actual. Mostrar inicialmente Mantenimiento y accesos autorizados a consultas existentes de Viajes; categorías aún no disponibles se indican como pendientes.
  - `GetReportCatalog` publica Mantenimiento, Historial y Rentabilidad de Viajes sólo con `viajes.ver`; las categorías pendientes no tienen URL.
- [x] Agregar regresión de fechas límite, CSV y scoping; indicar “abiertas actualmente”, “abiertas en el período” y “finalizadas en el período”. No cambiar silenciosamente la semántica histórica.
  - Se agregaron pruebas de borde del período, exportación por sucursal y etiquetas explícitas en la UI. El CSV conserva su universo: órdenes con apertura en el período.
- [x] Verificar estados vacíos y MTTR: si no se demuestra tiempo real descontando esperas, explicar su limitación y no presentarlo como indicador validado de reparación efectiva.
  - MTTR se rotula como referencia desde inicio hasta cierre, con limitación explícita de que no descuenta esperas ni pausas.
- [ ] Ejecutar pruebas específicas, build y verificación de rutas; validar escritorio/móvil en staging.
  - Pruebas específicas, suite completa, build y `verify:build-sync` en el runner remoto: OK. La validación visual autenticada en escritorio/móvil queda pendiente por falta de credenciales.
- [ ] Entregar cambio acotado con documentación y commit propuesto `feat(reportes): habilitar acceso desde el portal`.
  - Cambio implementado y documentado. No se creó commit ni se desplegó por instrucción expresa.

**Aceptación:** usuario autorizado entra desde `/inicio`, consulta, conserva filtros al navegar y exporta solo su alcance. Ninguna tarjeta anuncia un informe que no se puede consultar.

## Entrega 2 — Automatizaciones como centro de seguimiento

**Propietario:** Notificaciones y procesos programados; Identidad revisa alcance.

**Crear:** `app/Application/Notifications/GetAutomationOverview.php`, `app/Application/Notifications/Port/AutomationOverviewReader.php`, `app/Infrastructure/Notifications/CodeIgniterAutomationOverviewReader.php`, `app/Controllers/Automations.php`, `frontend/src/pages/automations/AutomationsPage.vue`, `frontend/src/pages/automations/index.js`.

**Modificar:** `app/Config/Routes.php`, `app/Config/Services.php`, `app/Application/Platform/GetPlatformModuleCatalog.php`, registro de assets/montajes existente. Agregar migración nueva `AddAutomationViewPermission` con timestamp del momento de implementación.

**Tests nuevos:** `tests/unit/Application/Notifications/GetAutomationOverviewTest.php`, `tests/unit/Infrastructure/Notifications/AutomationOverviewReaderTest.php`, `frontend/src/pages/automations/AutomationsPage.test.js`; ampliar cobertura HTTP de rutas y permisos.

**Interfaces:** `GetAutomationOverview::execute(ActorContext $actor): array`; `AutomationOverviewReader::forCompany(int $companyId, ?array $branchIds): array`. DTO con `processes`, `deliveries`, `limitations`; cada proceso incluye `key,label,enabled,lastRunAt,lastOutcome,nextRunAt`. Valores desconocidos son `null`, nunca éxito supuesto.

- [x] Crear pruebas que fallen por acceso sin `automatizaciones.ver`, empresa ajena, sucursal restringida y ausencia de historial atribuible al tenant.
  - `GetAutomationOverviewTest`, `AutomationOverviewReaderTest`, `AutomationOverviewReaderMariaDbTest` y `AutomationAccessContractTest`.
- [x] Crear permiso de lectura con asignación explícita a Administrador y Responsable; no derivar capacidad de ejecutar de `notificaciones.ver`. Probar migración up/down en DB desechable.
  - Migración `2026-11-05-120000_AddAutomationViewPermission` probada up/down en SQLite y en MariaDB descartable; asignó sólo a `Administrador` y `Responsable de mantenimiento`.
- [x] Implementar GET `/automatizaciones` exclusivamente de lectura. Catálogo inicial: vencimientos, recordatorios de lectura, informes y sincronización de telemetría.
  - Ruta GET con filtros `auth` + `permission:automatizaciones.ver`; no hay POST ni acciones de envío/ejecución/reintento.
- [x] Mostrar programación configurada separada de ejecución observada y de estado de entrega. “Sin evidencia de ejecución” cuando solo existe configuración.
  - La UI separa “Programación”, “Última evidencia”, “Resultado observado” y conteos; `nextRunAt` queda `null` porque el programador del hosting no se consulta desde la app.
- [x] No mostrar filas de `ejecuciones_programadas` globales a tenants. Derivar evidencia tenant de fuentes con empresa/sucursal explícitas y ocultar detalle no atribuible. Supervisión global queda en administración global.
  - El lector usa `notificaciones`, `notificacion_entregas`, `notificacion_whatsapp_entregas`, `notificacion_empresa_entregas` e `integraciones_telemetria`; con sucursales restringidas excluye lo no atribuible y lo declara en limitaciones.
- [x] Mostrar cantidades agregadas pendientes/fallidas y mensajes accionables; evitar errores crudos, teléfonos, correos y tokens en la respuesta.
  - La respuesta sólo expone contadores, fechas y estados normalizados. Los tests verifican que no se filtran correos/teléfonos y que los errores se registran como texto seguro.
- [ ] Probar paginación, vacíos, aislamiento, navegación y responsive. Registrar que esta entrega no añade botones de enviar, ejecutar ni reintentar.
  - Vacíos, aislamiento y contrato sin botones cubiertos por tests. Paginación no aplica porque el volumen es agregado; validación visual responsive en staging queda pendiente por falta de credenciales.
- [ ] Entregar commit propuesto `feat(notificaciones): agregar seguimiento de automatizaciones`.
  - Cambio implementado y documentado. No se creó commit ni se desplegó por instrucción expresa.

**Aceptación:** el usuario puede saber qué está configurado, qué evidencia existe y qué necesita atención sin disparar procesos.

## Entrega 3 — trazabilidad del ciclo y recuperación

**Propietario:** Notificaciones y procesos programados; Plataforma valida compatibilidad Ferozo.

**Modificar:** `app/Application/Notifications/RunNotificationCycle.php`, `RunNotificationDispatch.php`, `Port/NotificationProcessControl.php`, `app/Infrastructure/Notifications/CodeIgniterNotificationProcessControl.php`, `app/Config/Services.php` y tests correspondientes.

**Crear:** puerto `AutomationExecutionRecorder`, adaptador persistente y migración incremental de etapas con tenant nullable exclusivamente para etapa global. El vínculo empresa/sucursal se obtiene en el productor de la etapa, nunca por inferencia del texto del resumen.

**Contrato propuesto:** `begin(string $process,string $executionKey,?int $companyId): int`; `finish(int $id,array $summary): void`; `fail(int $id,string $safeError): void`. Definir su relación con el control existente antes de agregar tablas; reutilizarlo cuando alcance.

- [x] Probar dos ciclos concurrentes, repetición de clave y caída antes/después del despacho. Incluir lock expirado con ejecución aún `EN_PROCESO` y proveedor que acepta pero no confirma localmente.
  - `RunNotificationCycleStagesTest` cubre concurrencia, clave repetida y fallo posterior al despacho; `NotificationProcessControlRecoveryTest` cubre lock vencido con `EN_PROCESO`; la reconciliación de WhatsApp ya tenía contrato y pruebas propias.
- [x] Definir un único dueño de la exclusión del ciclo completo y compatibilidad del despacho directo; evitar adquisición anidada del mismo lock.
  - `notificaciones:ciclo` es el lock del ciclo; `notificaciones:despachar` sigue siendo el lock del despacho directo. Son locks distintos y no se anidan.
- [x] Registrar etapas completadas, fallidas y omitidas. Un despacho finalizado no convierte al ciclo entero en exitoso si luego falla telemetría.
  - `automatizacion_etapas` registra cada etapa; el ciclo devuelve `status: parcial` y `ejecuciones_programadas` queda `FALLIDA` cuando corresponde.
- [x] Mantener claves idempotentes por evento/destinatario y reconciliación de entregas; no prometer exactamente una entrega ante incertidumbre del proveedor.
  - No se cambió el circuito de idempotencia/reconciliación; la documentación y la UI de seguimiento explicitan la incertidumbre del proveedor.
- [ ] Probar migración, recuperación controlada y permisos; ejecutar smoke con canales de prueba aislados y evidencia de ausencia de duplicados.
  - Migración up/down y recuperación controlada probadas en DB descartable. El smoke con canales aislados no se ejecutó porque implicaría envíos externos; queda pendiente y no se declara validado.
- [x] Actualizar documentación del cron: frecuencia real, claves por minuto, límites efectivos y estados parciales. No depender de que `set_time_limit(0)` elimine límites externos del hosting.
  - `docs/notificaciones-cron-ferozo.md` registra la frecuencia real observada, la separación entre staging y producción, los locks, las etapas, la recuperación y el límite de 60 s de Ferozo.

**Aceptación:** una falla parcial se detecta y el operador recibe una acción concreta; repetir el proceso conserva controles y no reenvía indiscriminadamente entregas.

## Entrega 4 — reportes por equipo y período

**Propietarios:** Reportes integra; Viajes aporta costos operativos; WorkOrders aporta mantenimiento. Coordinar contratos antes de modificar reglas de otro contexto.

**Base:** `app/Application/Trips/Port/OperationalCostByEquipmentQuery.php`, `app/Application/Reports/Port/MaintenanceReportReadModel.php`, `docs/CONTRATOS_ENTRE_CONTEXTOS.md`.

- [ ] Contrastar estado de `OperationalCostByEquipmentQuery` y decisiones D5/D7. Ampliar su scope para sucursal o validar explícitamente equipo y sucursal antes de consumir; empresa sola no basta para un usuario restringido.
- [ ] Definir y probar contrato de lectura de mantenimiento por equipo. El controlador llama un coordinador de Reportes, no repositorios de múltiples contextos.
- [ ] Agregar informe con actividad, combustible, gastos y mantenimiento desglosados. No sumar repuestos una segunda vez al costo total de la OT.
- [ ] Probar adelantos fuera del margen, costos sin tramo visibles sin prorrateo automático, moneda/cotización, cero kilómetros y períodos sin actividad.
- [ ] Mantener historial ERP separado; ingresos históricos solo según vínculos aceptados y reglas existentes. No presentar flete previsto como cobrado ni emitir un saldo de cobranzas inexistente.
- [ ] Validar cifras contra casos controlados y exportación con los mismos filtros. Documentar reconocimiento por fecha antes de calcular margen combinado.

**Salida:** especificación de fórmulas y contratos revisada antes de implementar. Esta entrega requiere plan técnico propio después de resolver las dependencias; no queda lista para ejecución por este documento.

## Entrega 5 — configuración y reportes programados

**Dependencias:** entregas 2 y 3 aceptadas; reportes seleccionados validados.

- [ ] Definir permisos distintos para ver, configurar y ejecutar; administrador tenant nunca dispara un ciclo global mediante una acción presentada como local.
- [ ] Reutilizar configuración y programación existentes. Vista previa sin efectos antes de guardar horarios, destinatarios y canales.
- [ ] Resolver política de destinatarios y alcance al generar y entregar: cambios de permisos, baja de usuario y traslado de empresa invalidan accesos anteriores.
- [ ] Registrar quién cambió parámetros y valores anterior/nuevo; operaciones POST con CSRF y auditoría.
- [ ] Incorporar ejecución manual solo con comando tenant explícito, confirmación de alcance y reconciliación. No reutilizar directamente la acción global del Superadministrador.
- [ ] Probar reinicios, horario local, frecuencia, permisos revocados y duplicación. Usar mecanismo HTTPS protegido en Ferozo, sin exigir CLI.

**Salida:** diseño y plan específico posterior; sin constructor libre ni automatización de decisiones de aprobación/cierre.

## Validación por entrega y operación

Los siguientes comandos son exclusivamente para staging en `fasa_189`; no son instrucciones de ejecución en Ferozo. Ejecutar en el runner Docker remoto identificado, desde el checkout de esa entrega:

1. Tests PHPUnit específicos primero: `php vendor/bin/phpunit --no-coverage tests/unit/Application/Platform/GetPlatformModuleCatalogTest.php` y los archivos afectados. Verificar PASS y agregar regresiones para cada corrección.
2. Suite completa: `php vendor/bin/phpunit --no-coverage`. Lint PHP de archivos modificados; tests puros sin DB y adaptadores sobre MariaDB/MySQL real.
3. Frontend: `npm --prefix frontend test`, `npm --prefix frontend run build`, `npm --prefix frontend run verify:build-sync` en un entorno de herramientas remoto preparado; no asumir Node dentro de la imagen runtime.
4. Migraciones desde cero y actualización/rollback en DB desechable; nunca borrar o recrear volúmenes persistentes para esta comprobación.
5. Verificación HTTP real autenticada, CSV, permisos, CSRF para mutaciones y regresión `RouteResolutionTest` incluyendo prefijo plano de producción.
6. Capturas de escritorio y móvil, navegación teclado y ausencia de overflow. Aceptación visual solo con evidencia.
7. Documentación y release pequeño. Producción es una entrega separada por FTPS; no cambiar scheduler ni datos productivos durante este trabajo de planificación.

## Orden recomendado y cierre del plan

Ejecutar 0 → 1 → 2 → 3. Son incrementos verificables que habilitan ambos módulos sin esperar integración económica. Detallar 4 y 5 después, usando los contratos y datos ya validados.

La planificación está basada en inspección real de infraestructura y código. No se ejecutó la suite, no se aceptó visualmente el sistema y no se comprobó todavía actividad efectiva del scheduler. Estos puntos son tareas explícitas de la entrega 0, no resultados declarados.

## Resultado de la ejecución del 05/11/2026

Se implementaron y verificaron en lo posible las entregas 0, 1, 2 y 3; la aceptación autenticada/visual de la entrega 0 sigue pendiente por falta de credenciales. Las entregas 4 y 5 no se implementaron porque el propio plan exige un diseño técnico posterior y dependencias aún no resueltas.

Evidencia principal:

- Runner remoto `fasa_189` con PHP 8.4, SQLite habilitado, MariaDB descartable y Node 22; staging persistente intacto.
- Baseline inicial: 1631 pruebas, 6533 aserciones, 44 omitidas, 0 fallos.
- Resultado PHPUnit final: 1669 pruebas, 6686 aserciones, 45 omitidas, 0 fallos.
- Frontend: 84 archivos, 456 pruebas, 0 fallos; build reproducible y `verify:build-sync` OK.
- Migraciones nuevas aplicadas, revertidas y reaplicadas en MariaDB descartable; el lector de automatizaciones también se probó contra MariaDB real.
- `RouteResolutionTest` cubre las rutas nuevas y el catálogo del portal.
- No se modificó producción, no se enviaron correos/WhatsApp/push reales y no se recrearon volúmenes persistentes.

Pendientes explícitos:

- Login autenticado y validación visual escritorio/móvil en staging: bloqueado por falta de una cuenta autorizada en este chat.
- Smoke de canales aislados y prueba de ausencia de duplicados con proveedor real: no ejecutado para no producir efectos externos.
- Commit y despliegue: no realizados por instrucción expresa.
- Entregas 4 y 5: requieren plan técnico y resolución de dependencias antes de implementar.
