# Evidencia de importación de clientes — 16/10/2026

## Entorno y alcance

Implementación en worktree `clientes-importacion`, rama `codex/importacion-clientes`. Sin modificar código o documentación del checkout original del usuario; allí solo se guardaron capturas ignoradas por Git. La rama se rebasó después sobre el commit desplegado `93fa80a987ff086a3e91f094b19a5c2eaf0b38e6`, conservando los maestros de Viajes y perfiles visuales vigentes. Los conflictos fueron aditivos en Superadmin/registro de páginas; el manifest se regeneró con build remoto.

SSH confirmó `192.168.0.189` y hostname `fasa-PowerEdge-T40`. Coolify identifica aplicación `bdqictyu4q5xkfuh6aoh7sr8`, servicio `mantenimiento-staging`, entorno `staging`, puerto interno 80 y commit `93fa80a987ff086a3e91f094b19a5c2eaf0b38e6`. Se inspeccionó el Compose persistido mediante el contenedor Coolify, bajo `storage/app/applications/<id>/docker-compose.yaml`.

Las pruebas usaron un checkout temporal `/tmp/clientes-importacion` dentro del contenedor identificado y esquemas descartables `clientes_test_20261016` y `clientes_http_test_20261016` en su MariaDB de staging. El segundo recibió solo estructura del esquema existente, sin filas reales. Apache de prueba separado escuchó en el puerto interno 8086, accedido por túnel SSH. No se reemplazaron archivos del DocumentRoot activo ni se tocaron los volúmenes persistentes del sistema. No se operó `fasa_195` ni Ferozo.

## Verificaciones del cambio

- PHPUnit final: **25 pruebas, 55 aserciones, sin errores**, incluyendo parser, reglas puras, aplicación, MariaDB y acceso HTTP anónimo.
- Vue: **3 pruebas nuevas aprobadas** y suite final tras integrar sobre staging: **392 pruebas aprobadas en 69 archivos, sin fallos**.
- Lint PHP de todos los archivos PHP nuevos y configuración modificada: sin errores.
- Build Vue/Vite remoto y controles de encoding: aprobados; advertencia de tamaño de chunks del build existente.
- Migraciones nuevas: `up()` → `down()` → `up()` aprobados en MariaDB descartable; países iniciales comprobados.
- Regresión con dos conexiones: la confirmación ve los clientes comprometidos por otro lote después de cargar su cabecera; no mantiene un snapshot previo al bloqueo empresarial.
- Equivalencia geográfica pendiente frente a confirmada: solo la confirmada vincula `localidad_id`, conservando el texto original. Vista previa y exportación retiran la advertencia resuelta.

HTTP real con datos sintéticos comprobó: login, carga multipart, vista previa, confirmación, resultado, reenvío idempotente, rechazo de usuario no global (403), rechazo sin CSRF (403), lote inaccesible a otro actor, bloqueo por email inválido y empresa falsificada ignorada en confirmación. Dos sesiones del mismo actor confirmaron lotes diferentes simultáneamente: una insertó el cliente y otra lo omitió. Dos ejecuciones iniciales y otra posterior a la integración completaron sin duplicados.

Revisión visual en escritorio y móvil 390 × 844. En móvil: ancho del documento 390 px, sin desbordamiento de página; la tabla tiene su propio desplazamiento horizontal. Capturas y scripts de evidencia viven fuera de Git, bajo `output/playwright/clientes` del checkout original y `tmp/clientes-evidence` del worktree. Se observó un 403 preexistente del widget global de chatbot al solicitar su briefing; no pertenece al importador.

## Suites generales previas a integrar y límites de aceptación

No se declara verde el repositorio completo:

- PHPUnit general en MariaDB: **1193 pruebas, 4226 aserciones, 22 errores, 3 fallos y 22 omitidas**. Los errores corresponden a fixtures/adaptadores existentes (`DocumentImportedWorkOrderPartsTest`, `TelemetrySamplesMigrationTest`, `AvisosVencimientoConsistencyTest`, `ProximoAtencionRequeridaTest`) preparados para otra semántica/esquema de pruebas. Fallos: `ExpirationDuplicateValidationContractTest::testEquipmentUiMakesExpirationDateCorrectionExplicit`, `ExpirationRenewalFlowContractTest::testListUsesRenewModalAndKeepsFullRecordAsSecondaryAction`, `CodeIgniterCircuitOverviewCompatibilityTest::testReadsLegacyServiceCatalogWithoutTenantColumn`. Logs completos privados en `tmp/clientes-evidence/phpunit-full.log`. La suite general modifica su esquema de fixtures; por eso las verificaciones finales del cambio se aislaron después en el segundo esquema.
- Vitest inicial: **372 pruebas aprobadas y 4 fallidas**, en `AppHeader.test.js` (menú) y tres casos de `TheoreticalCostPage.test.js` (supuestos/selección de equipos). Se repitieron esos archivos contra el frontend original del primer HEAD: **los mismos cuatro fallos**, confirmando que antecedían a esta implementación. La integración sobre el commit de staging incorporó sus correcciones existentes y la suite frontend final quedó **392/392 verde**. Logs privados `vitest-full.log` e `integrated-vitest.log`.
- Migración de todo el repositorio desde cero: falla previamente con `Table 'marcas' already exists`. Las migraciones nuevas se ensayaron por separado sobre un esquema descartable. No se alteraron migraciones históricas para ocultar ese problema.

La revisión independiente detectó y se corrigieron la lectura obsoleta entre lotes concurrentes y la falta de aplicación de equivalencias. Queda diferida la gestión visual de catálogos geográficos, fuera de esta entrega. No se hizo piloto con usuarios ni despliegue a producción. La integración final y el despliegue FTPS quedan pendientes, junto con la resolución o aceptación explícita de los gaps generales registrados.

## Cierre y bloqueo de entorno

La integración de código sobre staging se completó en la rama aislada y volvió a aprobar 25 pruebas PHP, 392 pruebas frontend, build, HTTP real y revisión escritorio/móvil. Al repetir después la suite general de PHP, el host siguió accesible por SSH pero el daemon Docker dejó de responder (`Cannot connect to the Docker daemon`). Esa repetición final no se considera ejecutada satisfactoriamente; no se atribuye una causa al cambio y no se intentó reiniciar infraestructura compartida.

Queda pendiente recuperar Docker y completar la validación general, además del ensayo completo de migraciones desde cero ya bloqueado por el problema histórico de `marcas`. El código y los assets están conservados en la rama; existe un ZIP **candidato** incremental contra `93fa80a...` bajo `dist/clientes-importacion-20261016/`, con manifest SHA-256 privado. Debe contrastarse con producción antes de usarlo por FTPS. No se subieron archivos a Ferozo ni se importaron clientes reales.
