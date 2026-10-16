# Clientes por empresa e importación desde Superadmin

Estado: diseño aprobado e implementación en rama aislada; validación específica aprobada, integración y despliegue pendientes.

## Objetivo confirmado

Extraer el padrón del backup de Mario y permitir que el Superadministrador seleccione una empresa, suba un CSV, revise el resultado y confirme la importación para esa empresa. Los clientes pertenecen a la empresa y están disponibles para sus sucursales. Otra empresa tiene sus propios registros independientes. Esta entrega no importa viajes.

## Evidencia del origen

El backup `transporte_mario_pawluk-20261006.backup` contiene 357 filas en `a03_clientes`, incluida la fila genérica «Clientes Varios». Los backups de Ariel y Gisela tienen únicamente esa fila genérica. Mario no tiene filas en `a04_telefonos` ni `a05_mails`; no se inventan teléfonos ni correos.

La extracción privada se entrega en Downloads, fuera del repositorio. Conserva los 357 códigos sin fusionar clientes. Resuelve 283 provincias y 270 localidades contra los catálogos del mismo backup; los valores sin correspondencia quedan vacíos. Excluye contraseñas, saldos, límites de crédito y reglas financieras del sistema anterior.

## Alternativas y decisión propuesta

1. Padrón propio por empresa con referencias de origen: recomendado, respeta la independencia solicitada y prepara la asociación futura de viajes.
2. Padrón global compartido: descartado por la decisión del usuario de mantener empresas separadas.
3. Carga directa por SQL: no satisface la selección de empresa ni la vista previa solicitadas.

## Flujo

Desde Superadmin, «Importar clientes» abre una pantalla con selector de empresa activa, descarga de plantilla y carga CSV de hasta 5 MB y 10.000 filas. No se preselecciona una empresa silenciosamente. La empresa se muestra por nombre e identificación fiscal.

Al subir se crea una importación privada ligada al actor y empresa seleccionados. La vista previa muestra esa empresa, archivo, totales de nuevos/ya existentes/advertencias/errores y todas las filas paginadas. Permite cancelar o confirmar. Con errores bloqueantes no se confirma; se entrega detalle por fila para corregir y volver a subir.

La confirmación usa el identificador de importación, no una empresa enviada nuevamente desde el navegador. Revalida autorización, empresa activa, lote pendiente y duplicados actuales. Inserta clientes y auditoría en una transacción; cualquier fallo revierte el lote completo. Al terminar informa insertados y omitidos. Confirmaciones repetidas devuelven el resultado guardado sin insertar de nuevo.

## CSV y reglas

Columnas: `origen,codigo_origen,nombre,identificacion_fiscal,identificacion_fiscal_pais,direccion,provincia,localidad,contacto,telefono,email,activo`.

Se admite UTF-8 con o sin BOM y delimitador coma o punto y coma, con lectura CSV real para campos entrecomillados. No se interpreta contenido como código ni se ejecuta SQL del backup. Se validan UTF-8, encabezados, cantidad de columnas, límites, campos obligatorios, longitudes, email y activo 0/1. Nombre, origen y código son obligatorios; origen y código son cadenas, preservando ceros iniciales. Los errores se identifican por número de registro.

Clave de idempotencia: empresa + origen + código de origen, con restricción única en base. Un código repetido dentro del CSV bloquea el lote. Una clave ya importada se omite y se informa; esta primera versión no actualiza datos existentes. Nombres y documentos repetidos generan advertencias y no se fusionan automáticamente.

La identificación fiscal original se conserva como texto, separada del país fiscal de origen. Sin documento, documento genérico cero, CUIT inválido o identificación extranjera no bloquean la recuperación histórica: generan advertencias. No se habilita facturación ni se declara validada la situación fiscal. «Clientes Varios» se conserva y señala como genérico.

## Arquitectura y persistencia

### Catálogos geográficos: revisión del checkout y backup

La búsqueda en migraciones, modelos, dominio, aplicación e infraestructura del checkout no encontró catálogos geográficos implementados. Se propone crear tablas globales `paises`, `provincias` (con `pais_id`) y `localidades` (con `provincia_id`), con claves foráneas y estado activo. Clientes y domicilios mantienen su pertenencia empresarial; los catálogos no exponen información comercial de empresas.

El backup contiene 45 filas en `a01_provincias` y 174 en `a02_localidades`. Son catálogos legacy sin normalizar: en provincias aparecen ciudades como Vicente López, Porto Xavier e Itajaí. No deben convertirse automáticamente en provincias nuevas. Los nombres resueltos en el CSV significan coincidencia con el catálogo de origen, no validación geográfica.

Existen dos catálogos distintos de cuatro países: `t23_paises` contiene códigos del sistema anterior; `t14_cuit_paises` contiene identificadores fiscales por país. Ninguno justifica interpretar el país fiscal como país del domicilio. Conservar ambos espacios de códigos separados, junto con referencias geográficas originales del backup.

Agregar al diseño una tabla de equivalencias de origen hacia ubicación normalizada, con estado pendiente/confirmado. Solo vincular automáticamente equivalencias confirmadas. Las inconsistencias quedan advertidas en la vista previa y conservan los textos originales; no bloquean recuperar el cliente ni crean ubicaciones inventadas. La normalización inicial se prepara como catálogo revisable separado, no como cambios silenciosos de los domicilios.

Propietario: contexto de Clientes; Importaciones coordina formato, lote y resultado. Organización aporta el catálogo de empresas y autorización. La implementación debe respetar `Presentation/Infrastructure -> Application -> Domain` y composición en Services; el controlador no consulta tablas de otros contextos.

Agregar migraciones nuevas para clientes y lotes/filas de importación, utilizando las convenciones verificadas del repositorio. Clientes incluye empresa obligatoria, referencia de origen, datos del CSV, fechas y baja lógica; lotes conserva empresa, actor, hash de archivo, estado y resultado. No se versionan datos reales ni se almacenan en rutas públicas. Auditoría registra actor, empresa, lote y cantidades, sin volcar el padrón completo a logs.

La pantalla se integra con el frontend vigente del Superadmin, con regreso visible a Administración global y al portal `/inicio`. No se habilita una tarjeta funcional de Viajes antes de que exista el módulo. No se incorpora aún CRUD empresarial de clientes ni cuentas corrientes.

## Verificación requerida antes de dar por terminado

- Pruebas puras del parser y reglas: BOM, delimitadores, comillas, columnas inválidas, longitudes, códigos con ceros y advertencias fiscales.
- Casos de uso: actor no global rechazado, empresa inexistente/inactiva, empresa del lote inmutable, cancelación, reenvío, duplicados y rollback.
- MariaDB real: unicidad por empresa/origen/código, independencia entre empresas, confirmaciones concurrentes y auditoría atómica.
- HTTP: autenticación, Superadmin, CSRF, archivo inválido, vista previa escapada y confirmación protegida.
- Lint, PHPUnit, ensayo de migraciones en base descartable y flujo HTTP real; escritorio y móvil con evidencia.

Toda ejecución del sistema o prueba se realiza exclusivamente en el stack de staging de `fasa_189`, verificando primero por SSH hostname, Docker/Compose, stack, puerto y commit. Preservar volúmenes y no usar `fasa_195`, PHP/DB local ni producción Ferozo para pruebas. Si no puede identificarse staging, informar el bloqueo.

## Límites

La funcionalidad web y las migraciones están implementadas en una rama aislada, con evidencia en `docs/EVIDENCIA_IMPORTACION_CLIENTES_2026-10-16.md`. El CSV final contiene 356 clientes aptos; una fila de origen (código 334) carece de nombre y queda separada para revisión, conservando la extracción completa de 357. La correspondencia entre sucursales y backups será necesaria para viajes, pero no bloquea importar clientes a una empresa elegida explícitamente. No se importaron datos reales ni se desplegó a producción.
