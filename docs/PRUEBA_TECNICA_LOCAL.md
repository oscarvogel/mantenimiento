# FASE 0A - Prueba tecnica local

Fecha de validacion: **7 de agosto de 2026**.

Estado: **aprobada en entorno local**. Esta fase no publica archivos, no modifica
Ferozo y no sustituye la validacion posterior del hosting (FASE 0B).

## Objetivo

Comprobar, antes de desarrollar los modulos de negocio, que el proyecto puede:

- ejecutar CodeIgniter desde web y CLI;
- conectarse a MariaDB/MySQL;
- escribir logs y sesiones;
- enviar correo por SMTP;
- ejecutar una tarea programada;
- disponer de las extensiones PHP requeridas;
- instalarse, actualizarse y volver a una version anterior de forma manejable.

## Herramientas agregadas

| Componente | Funcion |
|---|---|
| `spark` | Entrada CLI adaptada al layout plano del repositorio. |
| `php spark hosting:check` | Diagnostica PHP, extensiones, directorios, sesiones, DB, logs y SMTP opcional. |
| `php spark cron:probe` | Escribe una marca verificable para probar cron o el Programador de tareas. |
| `scripts/smtp_capture.py` | Servidor SMTP local de una sola captura, sin enviar correo a Internet. |
| `scripts/run-phase0a.ps1` | Ejecuta la prueba local completa, incluido HTTP, sesion, correo y tarea programada. |
| `scripts/rehearse-local-deploy.ps1` | Simula instalacion limpia, actualizacion conservando datos y rollback. |

## Requisitos locales

- PHP 8.2 o superior.
- Composer.
- MariaDB o MySQL en ejecucion y configurado mediante `.env`.
- Python 3 para la captura SMTP local.
- PowerShell en Windows.

Las extensiones verificadas son:

```text
curl, dom, fileinfo, gd, intl, json, mbstring, mysqli, openssl y zip
```

`gd` y `zip` son necesarias para leer XLSX con PhpSpreadsheet. En XAMPP las
DLL pueden estar disponibles aunque el CLI no las cargue. Para iniciar el
servidor local sin modificar `php.ini`, usar:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts\run-local-server.ps1
```

## Ejecucion recomendada

Con la base configurada en `.env` y MariaDB/MySQL iniciado:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts\run-phase0a.ps1
```

La prueba crea procesos y una tarea programada temporales con identificadores
unicos, y los elimina al finalizar. Si el equipo no permite usar el Programador
de tareas, puede comprobarse el resto con:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts\run-phase0a.ps1 -SkipTaskScheduler
```

Comprobaciones individuales:

```powershell
php spark hosting:check
php spark hosting:check --json
php spark cron:probe --id prueba-manual
composer test
```

Para comprobar un SMTP real se puede indicar un destinatario. Las credenciales
y el cifrado se toman de `.env`:

```powershell
php spark hosting:check --email destinatario@ejemplo.com
```

`--smtp-plaintext` existe solo para el capturador SMTP local. No debe usarse con
un proveedor real.

## Ensayo de instalacion y actualizacion

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts\rehearse-local-deploy.ps1
```

El ensayo trabaja en una carpeta y una base temporales. Realiza lo siguiente:

1. prepara una release limpia e instala dependencias de produccion;
2. crea una base temporal, ejecuta migraciones y el seeder inicial;
3. inicia la aplicacion y verifica el login por HTTP;
4. prepara una segunda release y conserva `.env` y `writable` al actualizar;
5. vuelve a la primera release y repite la verificacion HTTP;
6. elimina la base y la carpeta temporales aunque ocurra un error.

El ZIP generado queda ignorado por Git en `writable/cache/` para poder
inspeccionarlo localmente.

## Resultado obtenido

| Prueba | Resultado local |
|---|---|
| PHP 8.2+ y extensiones requeridas | PASS |
| Conexion MySQLi a MariaDB | PASS |
| Escritura en cache, logs, sesiones y uploads | PASS |
| Sesion real mediante login HTTP | PASS |
| Escritura y lectura de log | PASS |
| Envio a capturador SMTP local | PASS |
| Comando de cron manual | PASS |
| Programador de tareas de Windows | PASS, codigo de salida 0 |
| PHPUnit normal | PASS: 7 ejecutadas y 2 ejemplos SQLite omitidos |
| Instalacion limpia con migraciones y seeder | PASS |
| Actualizacion conservando `.env` y `writable` | PASS |
| Rollback | PASS |

Tiempos orientativos del equipo de prueba:

- circuito tecnico completo: **6,69 segundos**;
- ensayo de instalacion, actualizacion y rollback: **31,83 segundos**;
- paquete de produccion resultante: aproximadamente **1,4 MB**.

Los dos tests omitidos por la ejecucion normal son ejemplos heredados del
appstarter que usan SQLite. No son pruebas del dominio ni un requisito del
runtime MySQL. Para ejecutarlos en este entorno sin cambiar `php.ini`:

```powershell
php -d extension=sqlite3 vendor\bin\phpunit --no-coverage
```

La cobertura se mantiene separada porque necesita Xdebug o PCOV:

```powershell
composer test:coverage
```

## Limite de aceptacion

La FASE 0A demuestra que el proyecto y el procedimiento funcionan en esta
maquina. No demuestra compatibilidad efectiva con PHP-FPM, permisos, cron,
SMTP, rutas ni restricciones reales de Ferozo. Esos puntos pertenecen a la
FASE 0B y deben verificarse luego de que el encargado publique la version.
