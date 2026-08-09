# Fases 2C y 2D - Activos, adjuntos e importaciones

Fecha de implementación local: **8 de agosto de 2026**.

## Alcance entregado

### Fase 2C - Registro de activos

- catálogos por empresa de marcas y modelos, con inactivación histórica;
- ficha técnica del equipo: marca, modelo, año, chasis y motor;
- listado paginado con filtros por texto, tipo, marca, sucursal y estado;
- relaciones temporales entre equipos y conservación del historial;
- bloqueo de baja cuando hay una relación activa;
- QR SVG autorizado que apunta a la ficha del equipo;
- scoping explícito por empresa y sucursal en comandos y consultas.

### Fase 2D - Archivos e importaciones

- adjuntos privados PDF/JPEG/PNG/WebP, máximo configurable;
- detección del MIME real, nombre opaco, descarga autenticada y retiro lógico;
- plantillas e importación de equipos y lecturas mediante CSV/XLSX;
- validación y vista previa por fila antes de persistir;
- confirmación o cancelación explícita, detección de duplicados y trazabilidad;
- confirmación transaccional con rollback completo;
- archivos de staging eliminados al confirmar o cancelar;
- permisos `importaciones.ver` e `importaciones.cargar`.

Las pantallas de esta fase son una superficie Bootstrap funcional para pruebas.
El rediseño se hará después con Vue y el archivo Tailwind provisto por el usuario.

## Rutas para probar

| Función | Ruta |
|---|---|
| Circuito preventivo y alta de equipo | `/mantenimiento` |
| Listado, filtros y catálogos | `/mantenimiento/equipos` |
| Ficha, relaciones y adjuntos | `/mantenimiento/equipos/{id}` |
| QR del equipo | `/mantenimiento/equipos/{id}/qr.svg` |
| Importaciones | `/mantenimiento/importaciones` |

Todas requieren sesión. Las mutaciones usan POST, CSRF, permisos y validación de
empresa/sucursal dentro del caso de uso.

## Configuración privada

Los directorios configurados deben ser rutas absolutas fuera de la raíz pública
del proyecto:

```dotenv
uploads.privatePath = '/ruta/privada/adjuntos'
uploads.maxSizeMB = 10
imports.privatePath = '/ruta/privada/importaciones'
imports.maxSizeMB = 10
```

Si no se configuran, el entorno local usa por defecto un directorio hermano
`Mantenimiento_camiones-private/`. El sistema rechaza una ruta dentro de
`ROOTPATH`, incluso si se intenta alcanzar mediante un symlink o junction.

## Dependencias y ejecución local

Composer incluye `endroid/qr-code` para SVG y `phpoffice/phpspreadsheet` para
CSV/XLSX. XLSX necesita `ext-zip` y `ext-gd`; CSV funciona sin ellas.

En Windows/XAMPP, iniciar con:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts\run-local-server.ps1
```

Para ejecutar todas las pruebas sin modificar `php.ini`:

```powershell
php -d extension=zip -d extension=gd -d extension=sqlite3 vendor\bin\phpunit --no-coverage
```

## Migraciones

- `110060-110064`: catálogos, ficha técnica, relaciones e invariantes.
- `110070`: adjuntos privados.
- `110080-110083`: importaciones, filas, permisos y vínculo con lecturas.
- `110084`: refuerzo de claves compuestas empresa-sucursal en adjuntos e importaciones.

No editar estas migraciones después de publicarlas. Cualquier ajuste posterior
debe entregarse como una migración nueva.
