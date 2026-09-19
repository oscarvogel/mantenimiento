# Evidencias privadas de vencimientos

Las evidencias adjuntas al renovar vencimientos se almacenan fuera del document root y nunca deben quedar accesibles por URL directa.

## Configuración obligatoria

Definir en cada entorno:

```ini
expiration.evidenceStorageRoot = <ruta absoluta privada>
```

La ruta debe ser:
- absoluta;
- escribible por PHP;
- persistente entre deploys;
- externa al directorio público del proyecto.

El sistema falla de forma explícita si la variable no está configurada.

## Staging / Coolify

Usar:

```ini
expiration.evidenceStorageRoot = '/data/priv/vencimientos'
```

Coolify debe montar almacenamiento persistente en:

```text
/data/priv
```

El Dockerfile ya prepara `/data/priv` para `www-data` y lo declara como volumen.

## Producción / Ferozo

Usar una carpeta fuera de `public_html`. Ejemplo:

```ini
expiration.evidenceStorageRoot = '/home/a0110632/vogelconsultoria.com.ar/private/mantenimiento/vencimientos'
```

Antes del deploy productivo, verificar en Ferozo la ruta real del home y que PHP tenga permisos de escritura.

No usar:
- `/public_html/mantenimiento`;
- `writable/uploads` bajo el webroot para estas evidencias;
- rutas temporales del sistema;
- rutas internas del contenedor de staging.

## Seguridad

Los archivos se guardan con nombre opaco y se descargan sólo a través del controlador, que valida el `empresa_id` del usuario autenticado.

## Checklist de deploy

Staging:
1. montar volumen persistente `/data/priv`;
2. configurar `expiration.evidenceStorageRoot=/data/priv/vencimientos`;
3. redeploy;
4. renovar un vencimiento con evidencia;
5. abrir historial y descargar el archivo.

Producción:
1. crear/verificar carpeta privada fuera de `public_html`;
2. configurar `expiration.evidenceStorageRoot` en el `.env` productivo;
3. desplegar código;
4. aplicar migraciones;
5. hacer smoke con una evidencia controlada.
