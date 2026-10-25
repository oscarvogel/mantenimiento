# Deploy a producción del PR #494 (semirremolques y composición del viaje)

**Fecha:** 25 de octubre de 2026
**PR:** #494 → mergeado a `main` en `fb12dd75`
**Destino:** producción (Ferozo, FTPS) — `https://vogelconsultoria.com.ar/mantenimiento/`
**Alcance del release:** `app/` completo de `main` + `assets/dashboard/`

---

## 1. Qué se publicó

El PR #494 (carga de los 27 semirremolques de TSA + acoplado preseleccionado y
composición completa del bitrén) mergeado a `main` con CI en verde.

Pero el release **no es sólo el PR**: al auditar producción antes de subir, se vio que
producción estaba 63 archivos atrás de `main`:

| Medida | Resultado |
|---|---|
| Archivos de `app/` idénticos | 999 |
| Archivos distintos | 37 |
| Archivos que faltaban en producción | 26 |
| Archivos que producción tenía y `main` no | **0** |

Producción no tenía ningún archivo que `main` no tuviera: estaba estrictamente atrás, no
divergido. Por eso el release es el `app/` completo de `main` y no un parche quirúrgico.

Lo que entra además del PR:

- El refactor de la flota (`EquipmentFleetLookup` como puerto del contexto de Activos) que
  todavía no había llegado a producción.
- El informe de lecturas a gerencia por WhatsApp, **opt-in por empresa y con el interruptor
  en 0 al aplicar la migración**: no envía nada hasta que alguien lo habilite.
- La liquidación del chofer (`viajes.liquidar`) y los otros ingresos del viaje.
- La integración con Autotrac: **no queda activa**; necesita credenciales de ATIC, una fila
  en la tabla de integraciones y una prueba contra la API real.

---

## 2. Cómo se verificó antes de subir

1. **Auditoría de drift por FTPS**: se descargó el `app/` de producción (1036 archivos) y se
   comparó archivo por archivo contra `main`. Resultado en la tabla de arriba.
2. **Archivos de raíz**: `index.php`, `.htaccess`, `spark`, `composer.json` y `composer.lock`
   son **idénticos** entre producción y `main`. No hay riesgo de pisar un `.htaccess` con
   reglas de otro sitio.
3. **Portón de migraciones**: se comprobó (sin imprimir valores) que el `.env` de producción
   tiene `CI_ENVIRONMENT=production` y `MIGRATE_TOKEN`. La base es `a0110632_mant` en
   `localhost` y las rutas privadas de adjuntos están fuera del webroot.
4. **Respaldo**: `app/` completo y `assets/dashboard/` (124 archivos) quedaron respaldados
   en `.deploy/pr494-backup/` y `.deploy/audit-pr494/`, por si hay que volver atrás.

## 3. Orden de subida (por qué no fue "subir todo y listo")

Hay pares de archivos de los que uno depende del otro. Subidos en el orden equivocado, una
petición puede caer con una versión de cada lado:

| Fase | Qué | Por qué en ese orden |
|---|---|---|
| 1 | Las 26 clases nuevas y las 37 actualizadas, salvo el par de viaje y el cableado | Son aditivas: no cambian firmas que el código viejo invoque. La implementación nueva de la flota sube **antes** que la interfaz que declara el método (una clase con un método de más es legal; al revés, no). |
| 2 | `CreateTripCommand.php` y `CreateTrip.php`, seguidos | El comando nuevo se pasa por lista y el caso de uso lo consume. Entre los dos hay una ventana de milisegundos: es el único momento en que una alta de viaje podía devolver 500. |
| 3 | `Config/Routes.php` y `Config/Services.php` | El cableado ya puede referenciar todas las clases. |
| 4 | Los bundles y, al final, el manifest | El manifest se sube último: nunca apunta a un archivo que todavía no está en el servidor. |

No se tocaron `.env`, `vendor/`, `writable/` ni los archivos de raíz.

## 4. Migraciones

Aplicadas por el portón HTTP (`migrate.php` subido por FTPS, invocado con `X-Migrate-Token`
y borrado después; se verificó que la URL responde 404 al terminar):

| Migración | Qué hace | Efecto |
|---|---|---|
| `2026-09-18-083000_RetireDuplicateActiveExpirations` | Da de baja vencimientos duplicados | Limpieza de datos |
| `2026-10-23-140000_AddManagementReadingReportSettings` | Agrega columnas de configuración del informe a gerencia | El interruptor de WhatsApp **nace en 0** |
| `2026-10-23-150000_AddDriverSettlement` | Agrega la liquidación del chofer y `pagado_por` | Los gastos de caja quedan marcados `pagado_por = CAJA` |

Ninguna de las tres activa un canal de envío por sí sola.

## 5. Verificación posterior

| Comprobación | Resultado |
|---|---|
| `GET /login` | 200 |
| `GET /.env`, `/app/Config/Services.php`, `/vendor/autoload.php`, `/writable/logs/...` | 403 (protegidos) |
| `GET /migrate.php` | 404 (el portón quedó borrado) |
| Bundles referenciados por el manifest | 25 de 25 responden 200 |
| `GET /viajes/operativos/nuevo` | 302 (redirige al login) |
| Log de producción | Sin errores nuevos. El único ERROR es de las 05:02, anterior al deploy, y es un timeout de la API de Wialon. |
| Huérfanos del build | 10 borrados (los que referenciaba el manifest viejo y el nuevo no) |

## 6. Lo que quedó sin verificar

- **La pantalla con sesión real**: no se pudo entrar al sistema desde esta máquina (no hay
  credenciales de usuario en el entorno). La verificación funcional de la preselección y de
  la composición se apoya en la suite (1518 tests) y en el CI del PR, no en una prueba
  visual en producción.
- **Staging**: `fasa_189` no se pudo alcanzar desde esta máquina. La LAN `192.168.0.0/24`
  entra por la placa Ethernet y la PC estaba por Wi-Fi. El release se subió a producción con
  la verificación local y el CI como evidencia, **sin la validación en Docker/Coolify**.
- Quedó residuo de builds anteriores en `assets/dashboard/` (98 archivos de deploys viejos).
  No se tocaron: no pertenecen a este cambio.

## 7. Seguimiento: el panel de relaciones no se encontraba

Al usar la pantalla, el usuario avisó que **no existía la ficha de "Relaciones entre
equipos"**. Existía, pero estaba en la pestaña **Mantenimiento** y no en *Resumen*: era
un error de mi explicación anterior y, además, una mala ubicación. Enganchar un
semirremolque a un camión es parte de la ficha del equipo, no del mantenimiento preventivo.

| Paso | Qué |
|---|---|
| PR #496 | Mueve el panel a la pestaña *Resumen*, junto a los datos del equipo. Sin tocar contratos de datos. |
| Test | `OperationsPages.test.js`: "deja las relaciones entre equipos en la pestaña Resumen". |
| Release | Sólo assets: el bundle nuevo y el manifest, más 4 huérfanos borrados. El PHP no cambió. |
| Verificación | 25 de 25 bundles del manifest responden 200 · `/login` 200 · `/.env` 403 · `/migrate.php` 404 · 431 tests del frontend en verde |

## 8. Pendientes

- [ ] Confirmar con el cliente la marca de los 13 argentinos (inferida del WMI del chasis) y el
      chasis de AE611RN (`docs/CARGA_SEMIS_2026-10-25.md` §8).
- [ ] Crear las marcas `Vulcano`, `Randon` y `Facchini` en Maestros antes de cargar los CSV.
- [ ] Decidir si el informe de lecturas a gerencia por WhatsApp se habilita para TSA: el
      interruptor queda en 0, es una decisión comercial.
- [ ] El atributo explícito de rol en `tipos_equipo`: hoy la clasificación depende del nombre.
- [ ] Validar en `fasa_189` el release completo (staging quedó atrás de `main`).
