# Bitácora — Validación de los 27 semirremolques contra la base real y release en staging

**Fecha:** 26/10/2026
**Motivo:** `fasa_189` volvió a estar accesible y el usuario ya creó las tres marcas
(`Vulcano`, `Randon`, `Facchini`). Quedaban dos huecos abiertos:

1. comprobar contra la base real que los CSV de los 27 semirremolques entran limpios;
2. validar el release en staging, que nunca se había desplegado (el hueco que dejó
   `fasa_189` caído).

**Alcance:** staging (`fasa_189` / Docker / Coolify) y **solo lectura** en producción.
**Producción no se modificó**: no se aplicaron migraciones ni se escribieron equipos.

---

## 1. Método: la sonda usa el validador real de la aplicación

La comprobación no reimplementa las reglas: reutiliza el código que corre en la app.

| Pieza | Archivo |
|---|---|
| Lector del archivo | `app/Infrastructure/Importations/NativeCsvSpreadsheetReader.php` |
| Reglas por fila | `app/Application/Importations/ImportRowValidator.php` |
| Consultas de catálogo | `app/Infrastructure/Importations/CodeIgniterImportReferenceGateway.php` |
| Alcance del usuario | `app/Infrastructure/Identity/CodeIgniterActorContextProvider.php` |

La sonda (`scripts/sonda-importacion.php`) lee el CSV con
el lector real, valida cada fila con `ImportRowValidator` y después **compara fila a
fila** el CSV contra lo que está en la base (`patente`, `tipo`, `marca`, `sucursal`,
`anio`, `chasis`, `motor`, `fecha_alta`, `observaciones`).

- En **producción** corre por el portón HTTP (`CI_ENVIRONMENT=production` +
  header `X-Migrate-Token`), como el `migrate.php` ya conocido.
  `scripts/sonda-produccion.py` hace el ciclo completo: sube la sonda y los CSV
  (a `writable/sonda/`, que es privado), corre y borra todo.
- En **staging** corre por `docker exec` en modo CLI (`--sonda [--csv-dir=...]`).
- Sólo hace `SELECT`. No escribe nada.

## 2. Resultado en producción

### 2.1 Catálogo

| Objeto | Estado |
|---|---|
| Empresa | TSA = `empresa_id` 4, activa |
| Tipo `Semirremolque` | id 10, activo, `controla_km=1`, clasifica como SEMIRREMOLQUE |
| Marca `Vulcano` | id 20, empresa 4, activa |
| Marca `Randon` | id 23, empresa 4, activa |
| Marca `Facchini` | id 26, empresa 4, activa |
| Sucursal `TSAARG` | id 4, empresa 4, activa |
| Sucursal `TSABR` | id 7, empresa 4, activa |

El matching de tipo y marca es exacto pero **no distingue mayúsculas**, así que
`Vulcano` en el CSV encuentra `Vulcano` y `Randon`/`Facchini` también. Verificado.

### 2.2 Las 27 unidades ya están cargadas y coinciden con el CSV

```
RESUMEN: 27 coinciden | 0 con diferencias | 0 no cargadas
```

| CSV | Patentes | Resultado |
|---|---|---|
| `semis_argentina.csv` | 14 | 14/14 idénticas al CSV |
| `semis_brasil.csv` | 13 | 13/13 idénticas al CSV |

En producción quedaron con ids 119…197 (`codigo` = `patente`, estado `ACTIVO`).
En staging, ids 121…147.

> **Consecuencia práctica:** volver a subir los CSV al importador **no** rompe nada
> (las 27 filas caen como `DUPLICADA`, que es una advertencia, no un error, y se
> omiten al confirmar) pero tampoco aporta: la carga ya está hecha. Si igual se
> sube, la vista previa muestra `27 duplicadas` y conviene confirmarlo en vez de
> asumir que se creó algo nuevo.

### 2.3 Quién puede importarlas

Los tres usuarios activos de TSA tienen el permiso `importaciones.cargar` y acceso a
**las dos** sucursales, así que las 13 unidades de Brasil no quedan afuera:

| Usuario | Rol | Sucursales |
|---|---|---|
| `mariela@tsa.com.ar` | Administrador | todas (TSAARG + TSABR) |
| `gimenezcarlosgabriel@gmail.com` | Responsable de mantenimiento | TSAARG + TSABR |
| `ventas@webtsa.com.ar` | Responsable de mantenimiento, Técnico u operador, Solicitante, Consulta | TSAARG + TSABR |

### 2.4 Relaciones tractor-acoplado: **cero**

`equipo_relaciones` con `tipo_relacion = TRACTOR_ACOPLADO` → **0** en producción y
**0** en staging. Es el único hueco que queda para que la preselección del
semirremolque en *Viajes → Nuevo viaje* tenga efecto: sin relación, el formulario no
tiene con qué preseleccionar.

La relación se crea **desde la ficha del camión**
(`POST /mantenimiento/equipos/{id}/relaciones`): la regla de incompatibilidad mira al
equipo relacionado, y desde la ficha de la caja el segundo enganche se rechaza. El
panel "Relaciones entre equipos" quedó en la pestaña **Resumen** de la ficha (PR #496).

> `docs/SEMI.docx` **no** define la composición: lista chasis, año y póliza, pero no
> dice qué tractor va con qué semirremolque. Esa asignación es dato del negocio:
> hay que cargarla con el cliente.

## 3. Hallazgo: el nombre del tipo decide si la caja aparece en Viajes

La clasificación tractor/semirremolque sale del **nombre** de `tipos_equipo.nombre`
(`/acoplado|semirremolque|remolque|batea/` y
`/camion|tractor|chasis|cabina|utilitario|pickup/`), en
`CodeIgniterEquipmentFleetLookup.php:83,88` y `CodeIgniterTripFormReadModel.php:82-87`.

El documento llama a las 13 de Brasil **"CARRETA"**. Un tipo llamado `Carreta` no
entra en ninguno de los dos patrones: quedaría `NINGUNO` y esas unidades **no
aparecerían** en el selector de semirremolques. Por eso las 27 se cargaron con el
tipo único `Semirremolque` (id 10), que sí clasifica.

Corolario pendiente: hoy la clasificación depende de una convención de nombre, no de
un atributo. Un tipo mal nombrado deja unidades invisibles.

## 4. Release en staging

Staging nunca había recibido el release (el hueco que dejó `fasa_189` caído). Se
desplegó `main` por el procedimiento de siempre: PAT temporal de Sanctum dentro del
contenedor `coolify` → `PATCH /applications/bdqictyu4q5xkfuh6aoh7sr8` con
`git_branch=main` y `git_commit_sha` → `POST /start` con `force` + `instant_deploy` →
borrado del PAT. **La rama `feat/telemetria-autotrac-atc` no se borró** y, además,
ya es ancestro de `main`: su contenido está en el release, así que staging no pierde
nada de lo que tenía.

| Dato | Valor |
|---|---|
| Rama / SHA | `main` / `3d6dec055c9130535810a45666ea8e81306e1b81` |
| Contenedor | `bdqictyu4q5xkfuh6aoh7sr8-20261026T183913` |
| Imagen | `bdqictyu4q5xkfuh6aoh7sr8:3d6dec05…` |
| Salud | `healthy` |
| `SOURCE_COMMIT` dentro del contenedor | `3d6dec05…` |
| `COOLIFY_BRANCH` | `main` |
| PAT temporales restantes | 0 |

### Gates (`DEPLOY_COOLIFY.md` §5)

| Chequeo | Resultado |
|---|---|
| `GET /login` | **200** |
| `GET /.env` · `/app` · `/vendor` · `/writable` | **403** en los cuatro |
| `GET /notificaciones/resumen` | **302** |
| `GET /mantenimiento/ordenes` · `/mantenimiento/ordenes/importar` · `/mantenimiento/lecturas/control` | **302** |
| `GET /viajes/operativos/nuevo` · `/viajes/operativos` | **302** |
| `php spark migrate:status` | **0 pendientes** (última: `2026-10-23-150000 AddDriverSettlement`) |
| Log | sin entradas del día; ni CRITICAL ni ERROR |

La comparación CSV ↔ base corrió **dentro del contenedor nuevo**, contra la base
`mantenimiento_staging`: `27 coinciden | 0 con diferencias | 0 no cargadas`.

## 5. Estado real de la preselección

Con la sonda se pudo medir lo que ve el formulario de viajes en producción:

```
las 27 semirremolques cargadas: 27 como SEMIRREMOLQUE | 0 como TRACTOR | 0 sin clasificar
tractores activos de la empresa: 27
tractores que ya tienen semirremolque asignado: 0
semirremolques ya enganchados a algun tractor: 0
```

Las 27 cajas **ya se clasifican como semirremolque**, así que aparecen en el selector
del formulario. Lo que falta para la preselección automática es la relación: hoy hay
27 tractores y **ninguno** tiene una caja asignada.

## 6. Limpieza

- Producción: sonda y CSV subidos por FTPS y **borrados** (verificado por listado del
  webroot); `writable/sonda/` eliminado.
- Staging: sonda y CSV copiados dentro del contenedor y **borrados** al terminar.
- El PAT de Coolify se borró al terminar el deploy (`personal_access_tokens` con
  `mavis-deploy-temporal-%` → 0).

> **Pendiente operativo en Coolify:** el `PATCH` + `POST /start` dejó **dos despliegues
> extra** del mismo commit en la cola (uno `in_progress` sin actividad de build desde
> las 18:50 y uno `queued` detrás). No son un bucle — la cola no creció — y son
> inocuos: reconstruyen la misma imagen `3d6dec05`. Se dejaron así y no se tocó el
> estado interno de Coolify; si se quieren cancelar, es desde la UI de Coolify.

## 7. Pendientes

1. **Crear las relaciones tractor ↔ semirremolque** (0 hoy). Es lo que falta para
   que en *Viajes → Nuevo viaje* el semirremolque se preseleccione al elegir el
   camión, y para poder armar un bitrén/tridem (tope de 3 cajas).
2. **Confirmar con el cliente** la marca de los 13 argentinos (inferida del WMI) y el
   chasis de AE611RN (`8B8C SRA 3CM 009053` en el documento; el plan de carga
   proponía `8B8GSRA3CMM009053`).
3. **La UI sigue sin evidencia con sesión real**: no hay credenciales de usuario en
   el entorno, así que la evidencia de la preselección y de la composición es la
   suite automatizada, no una prueba visual.
4. El informe de lecturas a gerencia por WhatsApp sigue **apagado** (interruptor en 0).
5. **Atributo explícito de rol en `tipos_equipo`**: la clasificación depende del
   nombre; un tipo mal nombrado deja unidades invisibles (ver §3).
