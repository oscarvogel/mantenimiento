# Migración del padrón de Viajes al padrón común

La migración `2026-10-17-130000_MoveTripCustomersToSharedCustomers` lleva cada
cliente anterior de `viajes_clientes` a `clientes` con origen `viajes_legacy` y
código igual al ID anterior. No combina clientes por nombre ni identificación
fiscal. Conserva los IDs, campos y autoría de los domicilios operativos; cambia
únicamente su `cliente_id` y su FK compuesta para referenciar al padrón común
dentro de la misma empresa.

`viajes_clientes_legacy_autoria` conserva exclusivamente la equivalencia de IDs
y `created_by`/`updated_by`, porque esas columnas no existen en el padrón común.
No es un segundo catálogo de clientes ni se consulta desde la aplicación. Sus
claves y FKs mantienen el vínculo con empresa, cliente canónico y usuarios. Se
retira en el rollback después de restaurar la autoría anterior.

## Ejecución y recuperación

Ejecutar con escrituras de la aplicación detenidas y copia previa verificable.
La prueba y el ensayo `up/down/up` corresponden exclusivamente al stack
Docker/Coolify de staging en `fasa_189`, después del protocolo de identificación
del entorno de `AGENTS.md`. Esta entrega no ejecuta cambios en Ferozo.

MariaDB/MySQL confirma DDL implícitamente. Esta migración no promete un rollback
transaccional del esquema. Antes de cambiar la FK verifica que cada domicilio
tenga cliente de destino y que ninguna otra tabla dependa del catálogo anterior.
Las columnas temporales `cliente_id_legacy` y `cliente_id_canonico` permiten
retomar una ejecución interrumpida sin interpretar un ID ya convertido como ID
de origen. Se retiran al terminar. Ante un estado parcial incompatible, detener
y revisar el log privado; no eliminar columnas ni tablas para forzar el avance.

Una interrupción de `up()` se termina reejecutando `up()`; una interrupción de
`down()` se termina con `down()`. No invertir el sentido con una conversión de
IDs incompleta. No habilitar escrituras hasta completar y verificar el esquema.

## Alcance del rollback

`down()` reconstruye los IDs anteriores a partir del origen reservado, restaura
la FK y conserva todos los clientes canónicos y su historial de importación.
Usa los valores comunes actuales para conservar ediciones hechas después de la
migración. Los anchos reconstruidos son los del padrón común; no reduce teléfono
de 60 a 50 caracteres, porque ello podría truncar información.

Si un domicilio nuevo apunta a un cliente canónico que no tiene origen
`viajes_legacy`, el rollback se bloquea antes de cambiar datos o esquema: no
existe un ID anterior inequívoco para ese cliente. También se bloquea ante IDs
reservados inválidos/ambiguos o falta de metadatos de autoría. En ese caso debe
mantenerse el esquema canónico o definirse una conversión explícita adicional.

Las pruebas `TripCustomerMigrationTest` requieren MariaDB real configurada en
un esquema descartable `clientes_test_*` de `fasa_189`. Usan tablas y nombres de
constraints con un prefijo aleatorio propio, y retiran solo esas tablas. No
ejecutarlas sobre la base de la aplicación ni reemplazarlas por una DB local.
