# Importación de clientes por empresa — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** importar clientes desde CSV mediante Superadmin para una empresa elegida, con vista previa, confirmación e idempotencia.

**Architecture:** Clientes conserva el padrón empresarial. Importaciones coordina lotes y su confirmación transaccional mediante puertos; Infraestructura implementa CSV y MariaDB. Geografía es un catálogo compartido con equivalencias legacy explícitas.

**Tech Stack:** PHP 8.2+, CodeIgniter 4, MariaDB/MySQL, Vue del frontend vigente, PHPUnit y Vitest.

**Spec:** `docs/superpowers/specs/2026-10-16-importacion-clientes-superadmin-design.md`.

## Global Constraints

- Clientes de una empresa disponibles para todas sus sucursales; otra empresa conserva registros independientes.
- CSV UTF-8 con/sin BOM, coma/punto y coma, máximo 5 MB y 10.000 filas.
- No importar contraseñas, saldos, créditos ni viajes; no fusionar ni actualizar clientes existentes.
- Datos reales fuera de Git y rutas públicas; dominio sin framework ni I/O.
- Ejecutar pruebas únicamente en staging Docker/Coolify de `fasa_189`, preservando volúmenes. Identificar previamente stack, Compose, puerto y commit.
- No tocar archivos locales ajenos a esta tarea ni producción Ferozo.

## Review Focus

- Archivos con comillas y saltos de línea dentro de un domicilio se leen como un único registro CSV.
- Códigos con ceros iniciales permanecen cadenas y no colisionan por conversión numérica.
- Cambiar la empresa después de previsualizar no cambia el destino del lote.
- Un CUIT extranjero o incompleto no impide recuperar al cliente ni se convierte en país de domicilio.
- Dos confirmaciones simultáneas no duplican clientes ni auditoría.

---

### Task 1: Parser y reglas de filas

**Files:** crear `app/Infrastructure/Customers/CustomerCsvReader.php`, `app/Domain/Customers/CustomerImportRow.php`, `tests/unit/Infrastructure/Customers/CustomerCsvReaderTest.php`, `tests/unit/Domain/Customers/CustomerImportRowTest.php`.

**Interfaces:** `CustomerCsvReader::read(string $path): array` devuelve registros con número, datos, errores y advertencias. `CustomerImportRow::validate(array $fields): array` aplica reglas puras; no consulta DB. Encabezados exactos del CSV entregado en la spec.

- [ ] Escribir pruebas para BOM, ambos separadores, comillas/saltos de línea, UTF-8 inválido, columnas repetidas/faltantes, tamaños y límites, códigos `001`/`1`, email inválido y activo fuera de 0/1.
- [ ] Verificar en staging que fallen por clases ausentes.
- [ ] Implementar lectura con `fgetcsv`, detección acotada de separador y límites previos a crear lotes; validar nombre/origen/código obligatorios y advertencias de documentos sin modificarlos.
- [ ] Ejecutar PHPUnit filtrado a `CustomerCsvReaderTest|CustomerImportRowTest`; exigir PASS.

### Task 2: Persistencia de padrón, lotes y geografía

**Files:** crear nuevas migraciones `CreateGeographyCatalogs` y `CreateCustomerImports` con timestamp libre posterior a las migraciones existentes; `app/Application/Customers/Port/CustomerImportStore.php`, `app/Infrastructure/Customers/CodeIgniterCustomerImportStore.php`, `tests/database/CustomerImportStoreTest.php`.

**Interfaces:** puerto `activeCompanies(): array`, `preview(int $companyId, int $actorId, string $hash, array $rows): int`, `get(int $batchId, int $actorId, int $page): array`, `confirm(int $batchId, int $actorId): array`, `cancel(int $batchId, int $actorId): void`. Adaptador encapsula persistencia y bloqueo; caso de uso delimita transacción con el puerto transaccional existente del proyecto.

- [ ] Escribir integración MariaDB para empresa inexistente/inactiva, datos aislados entre empresas, FK geográficas, misma clave origen/código por empresas distintas, rollback y confirmación concurrente.
- [ ] Ejecutar y observar fallos antes de migrar.
- [ ] Crear `paises`, `provincias`, `localidades`, equivalencias legacy, `clientes`, lotes y filas privadas con FK, índices, fechas e inactivación/baja lógica. Clave única en clientes `(empresa_id, origen, codigo_origen)`. Ubicación normalizada opcional; conservar texto original.
- [ ] Exponer catálogo inicial revisable y plantilla de equivalencias sin publicar datos de clientes. No copiar las 45 provincias legacy como geografía validada. No deducir país de domicilio desde país fiscal.
- [ ] Implementar almacenamiento, bloqueo del lote y empresa, revalidación del destino, omisión de claves existentes, auditoría transaccional y resultado persistido.
- [ ] Probar migraciones up/down/up en base descartable remota; PHPUnit `CustomerImportStoreTest` debe pasar sin tocar volúmenes de staging.

### Task 3: Casos de uso de importación

**Files:** crear `app/Application/Customers/PreviewCustomerImport.php`, `ConfirmCustomerImport.php`, `GetCustomerImport.php`, `CancelCustomerImport.php`, `tests/unit/Application/Customers/CustomerImportHandlersTest.php`; registrar servicios en `app/Config/Services.php`.

**Interfaces:** cada caso recibe `ActorContext`; preview recibe empresa y archivo validado, devuelve ID de lote; get recibe lote/página, devuelve DTO para pantalla; confirm recibe únicamente ID de lote y devuelve resultado guardado; cancel recibe ID de lote.

- [ ] Probar con puertos falsos rechazo de actor no global, códigos repetidos dentro del CSV, lote con errores, lote ajeno, cancelado o confirmado y empresa inactiva después de la vista previa.
- [ ] Verificar fallos y luego implementar autorización en aplicación, transacción, duplicados y binding inmutable actor/empresa/lote. Una confirmación repetida devuelve el resultado sin nuevas escrituras.
- [ ] Verificar PASS de `CustomerImportHandlersTest`, incluidos ceros iniciales e identificación fiscal extranjera.

### Task 4: Pantalla y rutas de Superadmin

**Files:** crear `app/Controllers/CustomerImports.php`, `frontend/src/pages/admin/CustomerImportsPage.vue` y `.test.js`; modificar `app/Config/Routes.php`, `frontend/src/pages/admin/index.js` y entrada real de Superadmin (`SuperAdminDemoPage.vue` o su componente, tras inspección de composición).

**Interfaces:** GET `/superadmin/clientes/importar`, GET plantilla, POST preview, GET lote, POST confirmar y POST cancelar; todas bajo filtro `superadmin` y protección CSRF global. Controller usa casos de uso y `renderApp`, sin queries transversales.

- [ ] Escribir tests HTTP de auth, no superadmin, CSRF, archivo/extensión/tipo/tamaño, escape HTML, cambio de empresa falsificado y confirmación repetida. Escribir Vitest para selector vacío obligatorio, empresa visible en preview y bloqueo de confirmación con errores.
- [ ] Implementar carga multipart, selector de empresas activas completo, enlace desde Superadmin, paginación de filas y resumen nuevos/existentes/advertencias/errores. Ofrecer descarga de errores con protección ante fórmulas CSV y retorno a Superadmin y `/inicio`.
- [ ] Mantener selección y lote al navegar; mostrar errores accionables sin trazas ni datos privados en logs. Confirmar destino por nombre de empresa antes del envío.
- [ ] Verificar tests HTTP y Vitest en runtime remoto identificado; build del frontend sin errores.

### Task 5: Revisión integral y entrega

**Files:** actualizar spec y agregar `docs/IMPORTACION_CLIENTES.md` con columnas, límites, duplicados, recuperación de errores, ubicación de evidencia y límites geográficos.

- [ ] Revisión independiente mediante herramienta de code review, enfocada en aislamiento, concurrencia, rollback, seguridad de archivos y dependencias.
- [ ] Ejecutar lint PHP, PHPUnit, Vitest, build y migraciones desde cero en el entorno remoto de prueba, conservando evidencia y separando fallos preexistentes.
- [ ] Verificar HTTP real y escritorio/móvil del circuito completo con datos sintéticos, sin publicar los 357 clientes reales para probar.
- [ ] Revalidar CSV privado de 357 registros contra encabezados finales. No cargarlo a una empresa hasta seleccionar y confirmar ese destino en la interfaz.
- [ ] Entregar cambios y evidencia; declarar explícitamente cualquier verificación pendiente. Commits españoles por contexto; no incluir archivos ajenos ni datos reales.

## Preflight observado

El alias SSH `fasa_189` responde; hostname real `fasa-PowerEdge-T40`, interfaz `eno1` con `192.168.0.189/21`. El usuario identificó la aplicación Coolify `bdqictyu4q5xkfuh6aoh7sr8`. Docker inspect confirma `coolify.serviceName=mantenimiento-staging`, `coolify.environmentName=staging` y proyecto Compose `bdqictyu4q5xkfuh6aoh7sr8`.

Contenedor observado: `bdqictyu4q5xkfuh6aoh7sr8-20261016T201006`, commit de imagen y `SOURCE_COMMIT=93fa80a987ff086a3e91f094b19a5c2eaf0b38e6`, puerto interno 80 detrás de Traefik. Volúmenes persistentes: `bdqictyu4q5xkfuh6aoh7sr8-writable` y `bdqictyu4q5xkfuh6aoh7sr8-priv`. Base URL observada: `https://bdqictyu4q5xkfuh6aoh7sr8.186.5.245.12.sslip.io/`. Runtime CI4 configurado `development`; la etiqueta Coolify confirma staging, no producción Ferozo.

La etiqueta de Compose apunta a `/artifacts/bbb04c06-dd80-4eeb-83a5-b5e1b6668fa3/docker-compose.yaml`, no encontrado como archivo accesible del host durante esta revisión. Antes de mutar o probar, recuperar e inspeccionar el Compose efectivo desde Coolify. El checkout local observado está en `a4e3eecb27ef70067f9a543a7b31568263ff7e16`, distinto del desplegado; contrastar diferencias antes de sincronizar. Solo se hicieron inspecciones de lectura y no se alteró ningún contenedor o volumen.

## Resultado de ejecución

Se recuperó e inspeccionó el Compose persistido desde el contenedor Coolify (`/var/www/html/storage/app/applications/<id>/docker-compose.yaml`) antes de crear el runtime temporal de pruebas. Implementados los cinco entregables; evidencia en `docs/EVIDENCIA_IMPORTACION_CLIENTES_2026-10-16.md`. Se consolidaron los cuatro casos de uso en `Application/Customers/CustomerImports.php`, manteniendo puertos y transacción de aplicación, y se añadió auditoría propia porque el esquema ejecutable no tiene una tabla general `auditoria`.

La implementación quedó integrada sobre el commit desplegado de staging, con 25 pruebas PHP del cambio y 392 pruebas frontend aprobadas; build y smoke HTTP real aprobados. CSV final: 356 filas importables y una fila (334) separada por nombre vacío, sin alterar los 357 originales. La suite general PHP y el ensayo de todas las migraciones tienen los gaps documentados. No hubo despliegue FTPS ni carga de datos reales. Los checkboxes originales se conservan como plan histórico; el registro de evidencia contiene los resultados efectivamente ejecutados.
