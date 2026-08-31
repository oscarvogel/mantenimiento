# Migraciones en producción Ferozo

> **Separación canónica:** `staging = fasa_189 / Docker / Coolify`; `producción = Ferozo / FTPS / sin CLI`.
>
> Este documento sólo describe producción Ferozo. No usar `fasa_195` para este
> proyecto salvo instrucción explícita; el staging canónico es `fasa_189`.

En producción **no se ejecuta `php spark migrate`**. El mecanismo soportado es el helper HTTP `scripts/migrate.php`.

## Flujo

1. Subir `scripts/migrate.php` a la raíz del release con el nombre `migrate.php`.
2. Verificar que `.env` contenga `CI_ENVIRONMENT=production` y un `MIGRATE_TOKEN` de al menos 32 caracteres.
3. Consultar primero el estado con `?status=1` y el header `X-Migrate-Token`.
4. Ejecutar el script sin `status=1` para aplicar las migraciones pendientes mediante el `MigrationRunner` de CodeIgniter.
5. Verificar la salida `OK: migraciones aplicadas.`.
6. Eliminar `migrate.php` del hosting inmediatamente después.

El script reutiliza exactamente las migraciones versionadas del proyecto; no requiere SSH ni CLI y no debe exponer credenciales en el repositorio.

## Issue #131

La migración `2026-08-20-193000_AddDemoFieldsToEmpresas.php` se conserva como marcador histórico, pero su `up()` y `down()` son no-op. La única migración efectiva que administra `empresas.es_demo` y `empresas.demo_expira_at` es `2026-08-20-140000_AddDemoFieldsToEmpresas.php`.

Esto cubre instalaciones nuevas y bases existentes:

- instalación nueva: `140000` crea los campos y `193000` no altera nada;
- base que ya ejecutó `140000`: `193000` no intenta recrear columnas;
- base que ya ejecutó ambas: no cambia el historial ni el esquema;
- rollback de `193000`: no elimina columnas que pertenecen a `140000`.
