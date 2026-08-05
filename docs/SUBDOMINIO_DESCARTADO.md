# Subdominio `mantenimiento.vogelconsultoria.com.ar` (descartado)

Estado: **descartado el 5 de agosto de 2026**.

## Que se intento

Se creo el subdominio `mantenimiento.vogelconsultoria.com.ar` apuntando via
CNAME al apex `vogelconsultoria.com.ar` (IP `200.58.111.111`), con la
intencion de mover la app a una URL dedicada fuera de
`https://vogelconsultoria.com.ar/mantenimiento/`.

## Por que se descarto

Ferozo emite el certificado Let's Encrypt para un subdominio **cuando se
configura el vhost en el panel** y se marca la opcion de HTTPS con LE. Sin
esa configuracion, Ferozo sirve el certificado wildcard generico
`CN=*.ferozo.com` (Sectigo), que tecnicamente verifica OK pero los
navegadores modernos lo rechazan con `NET::ERR_CERT_COMMON_NAME_INVALID`
para cualquier hostname que no sea `*.ferozo.com`.

Ademas:

- La autodeteccion de `baseURL` en `app/Config/App.php` funciona bien con
  el subdirectorio (`SCRIPT_NAME = /mantenimiento/index.php`) y la URL
  actual ya es estable.
- Mantener dos URLs activas (subdirectorio + subdominio) sin redirect 301
  desde el subdominio al subdirectorio duplica superficie de ataque y de
  mantenimiento sin beneficio claro para el cliente.
- El subdominio es util cuando se quiere separar deploy, cache o branding;
  nada de eso aplica en esta primera version.

## Decision

La URL canonica del sistema es:

```
https://vogelconsultoria.com.ar/mantenimiento/
```

El subdominio `mantenimiento.vogelconsultoria.com.ar` queda
**abandonado**: los CNAMEs asociados deben sacarse desde el panel de
NIC.ar y no se debe configurar el vhost en Ferozo. Si en el futuro se
quiere revivir el subdominio, partir de cero con la seccion "Migracion
futura a subdominio" que solia estar en `docs/DEPLOY_FEROZO.md` (commit
previo a este cambio).

## Acciones de cleanup

- **pendiente Oscar**: sacar los CNAMEs `mantenimiento` y
  `www.mantenimiento` desde el panel NIC.ar para
  `vogelconsultoria.com.ar`.
- **hecho**: `docs/DEPLOY_FEROZO.md` actualizado. La seccion 10 deja de
  ser "Migracion futura a subdominio" y ahora es "Subdominio descartado"
  con la nota de revisar este documento si alguna vez se quiere reactivar.
- **hecho**: `README.md` actualizado. La seccion 0 deja de mencionar el
  subdominio como destino alternativo.
- **hecho**: `.env.example` actualizado. El comentario sobre
  autodeteccion menciona solo el subdirectorio canonico.
- **hecho**: CHANGELOG v0.3 documenta la decision.

## Lecion para futuras sesiones

Antes de configurar un subdominio en Ferozo, confirmar que el vhost esta
listo y que el cert LE esta emitido. Si Ferozo no emite el cert en 30
minutos, no insistir: el panel no tiene la opcion de forzarlo desde fuera
y la friccion no compensa salvo que la URL dedicada sea un requisito
explicito del cliente.