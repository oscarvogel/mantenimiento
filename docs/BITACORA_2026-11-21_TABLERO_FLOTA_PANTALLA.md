# Bitácora 2026-11-21 - Pantalla «Tablero de flota» en Gerencial

## Contexto

Último PR del tablero gerencial de flota. Los casos de uso ya estaban fusionados: conciliación de combustible
(#550), uso de la flota y excesos (#552) y services estimados por GPS (#553). Este PR los pone en pantalla y crea el
permiso que los tres exigen.

## Decisiones

1. **Permiso `gerencial.flota.ver`** por migración (`2026-11-21-120000_AddFleetBoardPermission`), para Administrador y
   Gerencial; también en `InitialSeeder`. El `down()` borra el permiso y sus asignaciones.
2. **Una ruta, tres pestañas.** `GET gerencial/flota?vista=uso|combustible|services&dias=7|15|30&sucursal_id=`. Es de
   solo lectura y se renderiza en servidor, igual que los reportes: no hay endpoints JSON.
3. **Se calcula solo la pestaña activa.** Cada caso de uso lee la serie de telemetría de toda la flota; calcular las tres
   juntas triplicaría el tiempo de respuesta. El coordinador `App\Application\Gerencial\GetTableroFlota` elige cuál
   correr y valida vista, período y sucursal. Cada caso de uso sigue validando su permiso y su alcance.
4. **Períodos de 7, 15 o 30 días.** Uso de la flota admite hasta 31 días; el tablero no ofrece un período que alguna
   pestaña no pueda calcular. Services no usa período: es a hoy.
5. **Sucursales del filtro** desde `FleetTelemetryBoardReader`, el lector del tablero de telemetría, recortado por las
   sucursales del actor. Una sucursal ajena se rechaza antes de leer nada.
6. **Portal y navegación.** El módulo Gerencial de `/inicio` se habilita también con este permiso. Si el usuario tiene el
   del costo teórico, la entrada sigue siendo el costo teórico; si solo tiene el del tablero, entra al tablero. Las dos
   pantallas comparten la navegación del módulo (claves `managerial-*`) y la pantalla tiene «Volver al inicio».
7. **«Sin datos» en lugar de cero** (regla 17), con el motivo que devuelve cada caso de uso: sensor del tanque que no
   reportó, sin lectura vigente, corte del proveedor.
8. **Errores.** Con filtros, un error vuelve al tablero sin filtros con el mensaje; sin filtros responde 403, para no
   entrar en un bucle de redirecciones. Un `dias=abc` se rechaza, no se reemplaza en silencio por 30.

## Pruebas

- Coordinador con los tres casos de uso reales sobre puertos falsos: `GetTableroFlotaTest` (9). Cubre permiso,
  Superadministrador, valores por defecto, que solo se calcule la pestaña activa, vista y período inválidos, sucursal
  ajena, sucursales autorizadas y actor sin sucursales.
- Portal y navegación: `GetPlatformModuleCatalogTest` (+2) y `GetAppShellContextTest` (+2).
- HTTP: `FleetBoardHttpTest` (anónimo al login, ruta con su permiso, servicio cableado) y `RouteResolutionTest`, que
  detectó una URL con `{id}` que ninguna ruta respondía.
- Frontend: `FleetBoardPage.test.js` (4): pestañas que conservan los filtros, uso con «Sin datos» y cortes, conciliación
  con motivo y services con estado real y estimado.
- Suites completas: PHPUnit 2.119 pruebas sin fallos (51 salteadas que piden MySQL); Vitest 535 sin fallos;
  `verify:build-sync` OK.

## Staging

Desplegado en `fasa_189` (stack `mantenimiento-staging`, `bdqictyu4q5xkfuh6aoh7sr8`). Antes corría
`staging/cc-unificado`, que no tenía nada que `main` no tenga. Rollback: reapuntar la aplicación a
`staging/cc-unificado` / `HEAD` y redesplegar.

### Evidencia

| Control | Resultado |
|---|---|
| Contenedor | `healthy` con la imagen del commit desplegado |
| `php spark migrate` | `AddFleetBoardPermission` aplicada; también `AddCancellationToOperationalTripLegs`, que estaba en `main` y faltaba en staging |
| Permiso en la base | `gerencial.flota.ver` asignado a Administrador (1) y Gerencial (6) |
| `GET /login` | 200 |
| `GET /.env` · `/app` · `/vendor` · `/writable` | 403 en los cuatro |
| `GET /gerencial/flota` sin sesión | 302 al login |
| `GET /gerencial/flota` como Responsable de mantenimiento | **403** «No podés entrar a esta sección» |
| `GET /gerencial/flota` como Administrador | 200, las tres pestañas |

**Las tres pestañas contra la base real** (empresa 4, MariaDB, 30 días):

- **Uso:** 14 unidades, 58.002 km, 1.304 h de motor, 0 excesos. Las 14 aparecen en «Corte del proveedor»: en staging
  la ingesta de Wialon está cortada desde el 23/10 (688 h, abierto), y el tablero lo muestra como corte del proveedor
  y no como camiones sin reportar. Tardó **9,7 s**: está holgado frente al límite de 60 s de Ferozo, pero hay que
  medirlo en producción.
- **Combustible:** 2 viajes, ninguno con veredicto, cada uno con su motivo (hueco de 362 h; sin telemetría en el viaje).
  8 ms.
- **Services:** 26 planes, ninguno estimable hoy: sin odómetro de GPS, lectura de 65 días o sin GPS dentro de las 6 h de
  la lectura. 161 ms. Aparece `ITV9J84` con 11.515.914 km, el odómetro corrupto ya registrado el 09/10
  (`BITACORA_2026-10-09_INTEGRACION_WIALON_TELEMETRIA.md` §3.1).

### Correcciones de la revisión visual

En escritorio y en móvil (375 px) aparecieron cuatro problemas, que se corrigieron con test y se redesplegaron:

1. Las pestañas desbordaban 44 px en móvil y el `-mb-px` dejaba 1 px de scroll vertical: ahora son tres columnas que
   pueden pasar a dos líneas.
2. Un service vencido decía «Faltan -5.906 km»: ahora dice «Pasado por 5.906 km».
3. «1 siguen abiertos» pasó a concordar en singular.
4. La diferencia de combustible sin dato repetía «Sin datos» en el detalle.

## Producción (Ferozo)

Desplegado el 21/11/2026 de `cf750421` a `e58a163e` (merge de #554): incluye #552, #553 y #554.

1. **Auditoría previa, solo lectura.** Los 26 archivos runtime que tocaba el deploy coincidían byte a byte con
   `cf750421` en producción, `Routes.php` y `Services.php` incluidos: no había deriva que pisar.
2. **Backup quirúrgico** por helper efímero con `X-Migrate-Token`: `migrations`, `permisos` y `rol_permisos`, 50.516
   bytes, 3 tablas, 280 filas, SHA-256 `04bfa258b6b0a940775758ba55850753f7998cf33b69e8df0deebb88570beb36`, en
   `dist/ferozo-backup-tablero-20261121-085832.sql`. `backup.php` eliminado y verificado 404.
3. **Merge** de #554 con CI verde (el repo no admite auto-merge).
4. **Deploy incremental** con `scripts/deploy-ferozo.py --yes --skip-tests`: 55 archivos, sin `vendor/` ni `.env`; los
   bundles anteriores se conservan. `--skip-tests` porque el equipo local no tiene PHP de Windows: el árbol de `main`
   es idéntico al de la rama ya probada (PHPUnit 2.119, Vitest 536, `verify:build-sync` y CI).
5. **Migración** `AddFleetBoardPermission` aplicada por `migrate.php`, eliminado y verificado 404.

| Control | Resultado |
|---|---|
| Hash remoto de los 29 archivos publicados contra `e58a163e` | 29 iguales |
| `/login` · `favicon.ico` | 200 |
| `/.env` · `/.env.example` · `app/` · `vendor/` · `writable/` | 403 |
| `migrate.php` · `backup.php` | 404 |
| `/gerencial/flota` sin sesión | 302 al login |
| Bundles del manifest | 25, todos 200 |
| Log del día | sin errores posteriores al deploy (tres de las 00:05-00:15, previos: lock de notificaciones) |

**Con sesión de Administrador** (empresa 4, 30 días):

- **Uso:** 19 unidades, 113.746 km, 2.391 h de motor, **25 excesos** y los **dos cortes de Obersat** (47 h y 262 h,
  ambos cerrados), los mismos del análisis. 3 unidades sin reportar. **1,5 s** de respuesta: el temor por los 9,7 s
  de staging no se confirma en Ferozo.
- **Combustible:** 30 viajes, ninguno con veredicto todavía, cada uno con su motivo. 1,3 s.
- **Services:** 3 de 26 planes con estimación (AC532DD, BEN6G63, BEN5D63), como en la validación; «Pasado por» en los
  vencidos. 1,7 s.
- Sin desborde horizontal.

## Gráficos (#556)

La primera versión de la pantalla trajo solo tarjetas y tablas; el dashboard de análisis del 20/11 tenía gráficos. Se
sumaron, con los datos en vivo de cada pestaña y sin tocar el backend:

- **Uso:** línea de tiempo del período con los cortes del proveedor (tooltip por corte) y rankings por camión de km,
  motor encendido detenido y excesos. Los camiones sin dato se listan aparte, nunca como cero.
- **Combustible:** barra apilada con el resultado de la conciliación.
- **Services:** km al próximo service alrededor del cero, con el color del estado del plan. La escala sale del mayor
  valor normal: un odómetro corrupto (ITV9J84 en staging, millones de km) se recorta con aviso en lugar de achicar al
  resto. Se detectó en la revisión visual en staging.

Desplegado en producción de `e58a163e` a `90a69bb5`: 32 archivos, solo el build del dashboard; sin migraciones ni PHP.
Smoke tests OK y manifest más bundles remotos iguales a `main`. Con sesión de Administrador: los dos cortes de Obersat
en la línea de tiempo, BEN4G47 primero en excesos (15), los tres Autotrac sin odómetro listados aparte y AC532DD
vencido por 2.521 km según el GPS.

## Pendiente

- Corregir el desfase propio de cada GPS en los services estimados.
- Acelerar la recuperación del cursor de buckets, para que los cortes largos se rellenen antes.
