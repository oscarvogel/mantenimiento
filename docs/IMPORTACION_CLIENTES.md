# Importar clientes desde Superadmin

## Uso

1. Entrar a Administración global y abrir **Importar clientes**.
2. Elegir explícitamente una empresa activa y subir el CSV.
3. Revisar empresa de destino, filas nuevas, existentes, errores y advertencias. El padrón pertenece a la empresa y queda disponible para sus sucursales.
4. Si hay errores, descargar el detalle, corregir el archivo y volver a cargarlo. Cancelar conserva el lote para trazabilidad y no crea clientes.
5. Confirmar solo después de revisar el destino. El sistema guarda el resultado y permite volver a verlo.

La dirección es `superadmin/clientes/importar`, relativa a la URL base. En Ferozo será `https://vogelconsultoria.com.ar/mantenimiento/superadmin/clientes/importar` una vez desplegado.

El backup de Mario tiene 357 filas. La extracción final entrega 356 clientes con nombre en `clientes_mario_para_importar.csv`; el código `334`, cuyo nombre está vacío en origen, se conserva en `clientes_mario_pendientes.csv`. La extracción completa de 357 filas queda resguardada en `clientes_mario_extraidos_completos.csv`, fuera del repositorio. No inventar un nombre ni perder ese código al resolverlo para viajes.

## Formato

CSV UTF-8 con o sin BOM; coma o punto y coma; hasta 5 MB y 10.000 filas. Los campos entrecomillados pueden incluir separadores y saltos de línea.

Encabezados obligatorios (todos deben existir, aunque los valores opcionales queden vacíos):

```csv
origen,codigo_origen,nombre,identificacion_fiscal,identificacion_fiscal_pais,direccion,provincia,localidad,contacto,telefono,email,activo
```

Origen, código y nombre son obligatorios. Activo admite `0` o `1`. Email, si se informa, debe tener formato válido. Los códigos se conservan como texto: `001` y `1` son diferentes. No se importan contraseñas, saldos, límites de crédito ni reglas de facturación.

La identificación fiscal se conserva como texto: un documento ausente, genérico, extranjero o sin validación genera advertencias. No habilita facturación. El país de identificación fiscal nunca se transforma automáticamente en país de domicilio.

## Duplicados y recuperación

Identidad de importación: **empresa + origen + código**. Dos filas con esa identidad dentro del mismo archivo bloquean la confirmación. Si el cliente ya existe en la empresa, se omite sin modificarlo; otra empresa puede importar el mismo origen y código independientemente. Nombres o documentos repetidos son advertencias; no se fusionan personas automáticamente.

También se omiten identidades de clientes inactivos o dados de baja: su historia no se reemplaza. Reconfirmar el mismo lote devuelve su resultado guardado. Dos lotes concurrentes de la misma empresa se serializan antes de decidir cuáles clientes existen.

Clientes, resultado y auditoría se guardan dentro de una transacción. Un fallo revierte el lote completo. La empresa se fija al crear el lote; no puede cambiarse falsificando el formulario de confirmación. El archivo crudo no se conserva: se guardan hash, actor, empresa y filas validadas en tablas privadas.

## Geografía

Tablas globales: `paises` → `provincias` → `localidades`. La migración inicial carga Argentina, Brasil, Chile y Uruguay con códigos internos explícitos; no copia las ciudades que el sistema anterior había guardado como provincias.

Cada combinación de origen/provincia/localidad del CSV genera una equivalencia pendiente, sin inferir un país. La referencia es un SHA-256 del JSON de `[origen, provincia, localidad]` tal como lo calcula `CustomerImportRow::locationKey()`; no es un código geográfico oficial. El origen fiscal no forma parte de esa referencia.

Una equivalencia `CONFIRMADO` con localidad/provincia/país activos se aplica al importar y retira la advertencia de ubicación pendiente. Las equivalencias pendientes conservan los textos originales y dejan `clientes.localidad_id` vacío. Una nueva carga nunca sobrescribe equivalencias confirmadas.

La pantalla global **Administración → Localidades** permite importar catálogos oficiales. La aprobación de equivalencias de domicilios de clientes sigue fuera de esa pantalla. Tampoco reescribe domicilios de clientes ya importados cuando se confirma una equivalencia después.

## Entrega en Ferozo

Staging: exclusivamente Docker/Coolify en `fasa_189`. Producción: FTPS a Ferozo, sin SSH, CLI ni `php spark`.

El cambio incluye dos migraciones nuevas, backend y frontend compilado; no agrega dependencias Composer. Mantener `vendor/` existente compatible con el lock del release. No subir `.env`, backups, CSV de clientes, tests ni evidencias privadas como contenido público.

La rama se integró sobre `93fa80a987ff086a3e91f094b19a5c2eaf0b38e6`, el commit de staging identificado, conservando maestros de Viajes y perfiles visuales por empresa. Se repitieron pruebas del importador, build, suite frontend y HTTP real sobre esa integración. Antes de desplegar a Ferozo, contrastar también la versión de producción: no reemplazar archivos completos de otra versión sin integrar sus diferencias. El ZIP candidato es incremental contra ese commit base; no es una actualización universal para cualquier versión de Ferozo.

Aplicar las migraciones mediante el mecanismo HTTP autenticado existente en Superadmin, o el runner efímero documentado en `DEPLOY_FEROZO.md`; retirar ese runner si se utiliza. Preservar `.env`, adjuntos, sesiones y datos existentes. No existe despliegue a producción implícito por preparar un paquete.
