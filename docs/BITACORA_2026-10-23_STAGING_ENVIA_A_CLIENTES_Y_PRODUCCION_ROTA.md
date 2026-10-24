# Bitácora — El aviso salió de staging (URL de prueba) y producción quedó rota

**Fecha:** 23/10/2026
**Origen:** mensaje de WhatsApp recibido por Carlos (TSA) con un enlace a
`https://bdqictyu4q5xkfuh6aoh7sr8.186.5.245.12.sslip.io/...`, más un audio suyo quejándose de que
el informe dice que un equipo lleva "18 días" sin lectura cuando el kilometraje fue confirmado esa
semana.

---

## 1. Conclusión (primero)

| Pregunta | Respuesta verificada |
|---|---|
| ¿El mensaje con URL de staging salió de producción? | **No.** Salió de **staging** (`fasa_189`, contenedor `bdqictyu4q5xkfuh6aoh7sr8-20261023T200614`). El `app.baseURL` de ese contenedor es exactamente el host del enlace. |
| ¿Producción está bien? | **No: está rota desde hoy 10:30.** El cron de notificaciones y la campana `notificaciones/resumen` tiran un fatal 148 veces. **Producción no mandó ningún aviso hoy.** |
| ¿Por qué el correo y el WhatsApp no coinciden? | Son **dos fuentes distintas dentro de la misma app**: el correo calcula en vivo sobre `lecturas_equipo`; el digest de WhatsApp reproduce la tabla `notificaciones` (bandeja). |
| ¿Tenía razón Carlos? | **Sí.** En el sistema real el km de `IWE2I14` quedó confirmado el 21-22/10. Staging tiene una **copia congelada al 08/10**. |

El resultado combined es lo peor de los tres: **el único canal que está vivo es el que manda datos
viejos y con enlaces a un entorno de prueba**, y el canal bueno (producción) está caído.

---

## 2. Hallazgo A — el mensaje salió de staging, no de producción

### 2.1 El enlace delataría al emisor

`app.baseURL` del contenedor de staging (leído del entorno, con secretos redactados):

```
CI_ENVIRONMENT=development
app.baseURL=https://bdqictyu4q5xkfuh6aoh7sr8.186.5.245.12.sslip.io/
email.SMTPHost=a0110632.ferozo.com
email.fromEmail=info@vogelconsultoria.com.ar
email.fromName=Mantenimiento Staging
```

Producción, en cambio, tiene lo correcto:

```
CI_ENVIRONMENT = production
app.baseURL = 'https://vogelconsultoria.com.ar/mantenimiento/'
email.fromName = 'Mantenimiento'
```

### 2.2 Los mensajes están en la base de staging, con el texto exacto

`notificacion_whatsapp_entregas` (base `mantenimiento_staging`), creados el 23/10:

| id | tipo_evento | teléfono | estado | created_at |
|---|---|---|---|---|
| 5959 | `informe.lecturas.gerencia` | 5493755632536 (empresa TSA) | `PENDIENTE_CONFIRMACI` / gateway `processing` | 17:31:48 |
| 5960 | `usuario.resumen_diario` | 5493755274301 (**carlos.tsa**) | `PENDIENTE_CONFIRMACI` / gateway `processing` | 17:33:12 |
| 5961 | `usuario.resumen_diario` | 5493743400547 (Camila Kunrath) | `PENDIENTE_CONFIRMACI` / gateway `processing` | 17:33:12 |

El `mensaje` guardado en la fila 5960 es **idéntico** al que pegó el usuario, y el de la fila 5959
contiene la línea que Carlos menciona en el audio:

```
• *IWE2I14* — hace 18 días
   Última lectura: 1.235.507 km · 05/10/2026 08:40
   Chofer: RICARDO FERREYRA
👉 *VER LOS EQUIPOS SIN LECTURA:*
https://bdqictyu4q5xkfuh6aoh7sr8.186.5.245.12.sslip.io/mantenimiento/lecturas/control?filter=gt_7
```

### 2.3 Staging está armado para hablar con clientes reales

`configuracion_canales_globales` de staging (id 1), actualizado **hoy 20:31:38 UTC**:

| clave | valor | veredicto |
|---|---|---|
| `whatsapp_enabled` | 1 | habilitado |
| `whatsapp_instance_id` | `oscar_claro_2` | **instancia real**, no de pruebas |
| `whatsapp_pilot_enabled` | **0** | **modo piloto apagado** → va al celular real |
| `whatsapp_pilot_phone` | 3743667526 | el piloto existe pero está desactivado |
| `smtp_enabled` | 1 | habilitado |
| `smtp_host` | `a0110632.ferozo.com` | **el relay de correo de producción** |
| `smtp_from_email` | `info@vogelconsultoria.com.ar` | **casilla de producción** |
| `smtp_from_name` | `Mantenimiento` | **idéntico a producción**: el cliente no puede distinguir el origen |

`docs/DEPLOY_COOLIFY.md` §3 dice que en staging van `email.SMTPUser` y `email.SMTPPass` **vacíos**
y el SMTP de prueba. La realidad es que staging usa **las credenciales de producción**.

Además, `empresas` (id 4 = TSA) en staging tiene:

```
notificaciones_whatsapp_habilitadas = 1
whatsapp_instance_id                = oscar_claro_2
telefono_gerencia                   = 5493755632536
emails_informes = oscarvogel@gmail.com,pawlukma@gmail.com,ventas@webtsa.com.ar
informe_lecturas_whatsapp_habilitado = 1
```

---

## 3. Hallazgo B — los datos de TSA en staging están congelados al 08/10

En `mantenimiento_staging`:

| dato | valor |
|---|---|
| última lectura de **cualquier** equipo de TSA (empresa 4) | `2026-10-08 10:37:43` (creada `2026-10-08 19:21:42`) |
| `IWE2I14` — última lectura | `2026-10-05 08:40:02` · **1.235.507 km** |
| `AD738EA` — última lectura | `2026-09-17 08:22` · 861.523 km |
| `OJG526` — última lectura | `2026-09-28 10:35:35` · 1.216.168 km |
| `GZD587` — km_actual | `NULL` (nunca registró lectura) |

El 23/10, `05/10 + 18 días` → "hace 18 días". El informe no inventó nada: **calculó bien sobre una
copia vieja**. Carlos tiene razón en que el km estaba confirmado el 21-22/10: ese dato está en
**producción**, que es donde trabaja TSA.

Consecuencia: mientras staging sea el que manda, **todo aviso a TSA es falso por definición**.

---

## 4. Hallazgo C — producción está rota desde hoy 10:30 (deploy parcial)

### 4.1 El síntoma

`writable/logs/log-2026-10-23.log` de producción (descargado por FTPS):

```
CRITICAL - 2026-10-23 10:30:01 --> ErrorException: Class
  App\Infrastructure\Notifications\CodeIgniterWhatsAppNotificationDeliveryQueue contains 1 abstract
  method and must therefore be declared abstract or implemented.
[Method: POST, Route: internal/cron/notifications/dispatch]
in APPPATH/Infrastructure/Notifications/CodeIgniterWhatsAppNotificationDeliveryQueue.php on line 19.
```

- **148 ocurrencias** hoy (una cada 5 minutos, el cron web).
- Rompe también `GET notificaciones/resumen` (la campana del dashboard) y
  `POST mantenimiento/lecturas/control/reclamar`.
- Único otro error del día: `Class "App\Application\Platform\PresentationProfiles\FrontendProfileCatalog" not found`
  (10:28:13) — el archivo **existe** hoy; fue la carrera de la subida en curso.

### 4.2 La causa

Producción tiene la **interfaz** del 15/10 y el **adaptador** subido hoy 13:29 UTC:

| archivo | mtime en producción | qué le falta |
|---|---|---|
| `app/Application/Notifications/Port/WhatsAppNotificationDeliveryQueue.php` | 20261015213427 | — (declara `previewUserDailyDigests`) |
| `app/Infrastructure/Notifications/CodeIgniterWhatsAppNotificationDeliveryQueue.php` | 20261023132919 | **no implementa `previewUserDailyDigests`** |

Los mtime de 13:28-13:29 de hoy cubren un conjunto de 16 archivos de `app/Application/Notifications`
y `app/Infrastructure/Notifications` + `app/Config/Services.php`: es un **deploy quirúrgico** hecho
desde la rama `codex/autotrac-ferozo-hotfix` (commit `0acc757`, "feat(telemetria): integrar ATIC
Autotrac"), que **nació antes** del fix `17606730`/`c5ae69b1` ("fix(notificaciones): corregir
resumen preventivo y previsualizar WhatsApp").

`git diff --stat c5ae69b1 0acc757 -- app/Application/Notifications app/Infrastructure/Notifications`:

```
 .../Port/WhatsAppNotificationDeliveryQueue.php     |   3 -
 ...odeIgniterWhatsAppNotificationDeliveryQueue.php  | 148 ++++++---------------
 .../CodeIgniterPreventiveNotificationRevalidator.php| 126 ------------------
 .../UserDailyDigestMessageBuilder.php               |  60 --------
 .../WeeklyReadingNotificationRevalidator.php        |  54 +++-----
```

La rama Autotrac no tiene el método en el adaptador; `main` sí lo tiene en los dos lados.
**Producción quedó con la interfaz de `main` y el adaptador de la rama Autotrac.**

Estado de coherencia por commit:

| commit | adapter tiene el método | interfaz tiene el método |
|---|---|---|
| `0acc757` (rama Autotrac) | no | no |
| `c5ae69b1` (`main`) | **sí** | **sí** |
| `05d6c63e` (HEAD de la rama actual) | **sí** | **sí** |

En producción **hoy** (mezcla): no / sí → **fatal**.

---

## 5. Por qué el correo y el WhatsApp no coinciden (el "dos lugares distintos")

No son dos ambientes: son **dos fuentes de datos dentro del mismo ambiente**.

| Canal | Cómo arma el contenido | Fuente |
|---|---|---|
| **Correo** (`Informe diario de mantenimiento · TSA`) | `ScheduleManagementReports` + `CodeIgniterReadingComplianceReadModel` | **SQL en vivo** sobre `lecturas_equipo` + `equipos` |
| **WhatsApp** (`Resumen diario`) | `CodeIgniterWhatsAppNotificationDeliveryQueue::scheduleUserDailyDigests` + `UserDailyDigestMessageBuilder` | **tabla `notificaciones`** (bandeja de avisos por usuario) |

Por qué la bandeja está vieja: el motor de alertas que la llenaba **moría por memoria** a las 07:00
(`log-2026-10-17/18/20/21.log` pesan ~325 bytes: solo el CRITICAL de memoria). Por eso el digest de
WhatsApp reproduce avisos del **29/09 al 17/10** y no incluye `IWE2I14` (el aviso de ese equipo
existe pero quedó `LEIDA` y no se regeneró). Con el deploy de hoy 17:06 el motor volvió a generar
avisos (3 filas nuevas a las 17:46:31), pero la bandeja sigue mezclando un mes de avisos viejos.

**El correo calcula; el WhatsApp reproduce.** Con eso queda explicado el mensaje de Oscar: "en el
correo llega ese mensaje de lectura antigua pero en el WhatsApp no".

---

## 6. El audio (transcripción)

> "Hola, buenas. Te hago una consulta, porque ahí me llegó un mensaje de mantenimiento y eh por
> ejemplo me dice Ricardo Ferreira hace 18 días. Pero fue esta semana, ayer, anteayer que habíamos
> visto y era al final como que había quedado confirmado ya lo de él, que eh el kilometraje actual"

Carlos (TSA) reclama por el aviso que dice **"RICARDO FERREYRA — hace 18 días"** (equipo
`IWE2I14`, id 82). Tiene razón: en el sistema real el kilometraje estaba confirmado el 21-22/10.
El aviso se calculó con el snapshot viejo de staging.

Dato menor, para evitar confusiones de patente: en la base **solo existe `IWE2I14`** (con "i"),
Carlos lo escribió `Iwe2l14` (con "l") en el chat. Es el mismo equipo.

---

## 7. Hallazgo adicional — el SMTP marca fallo aunque el correo se entregó

`log-2026-10-23.log` de staging, 17:17:30:

```
ERROR - Fallo SMTP al enviar resumen. protocol=smtp host=a0110632.ferozo.com port=587 crypto=tls
detail=... fwrite(): SSL operation failed with code 1. OpenSSL Error messages:
error:0A00010F:SSL routines::bad length ... in Email.php:2042
  #1 Email.php(2042): fwrite(Resource id #322, 'QUIT\r\n')
```

El `DATA` ya se había enviado y aceptado; el fallo ocurre al escribir `QUIT` al cerrar. CI4 lo
reporta como error → el envío queda en `REINTENTO` / "Revisá la configuración SMTP" y, con
`alerts.smtpMaxRetries=3`, **se puede reenviar el mismo correo al cliente**. El rechazo de
`mariela@tsa.com.ar` (550, esa casilla no existe) sí es definitivo y ese sí está bien marcado.

---

## 8. Correcciones (priorizadas)

### P0 — Devolver producción a un estado coherente (el cliente está sin servicio) — **HECHO 23/10 → §9.1**
1. Subir a producción el conjunto coherente de `app/Application/Notifications/`,
   `app/Infrastructure/Notifications/` y `app/Config/Services.php` tomado de **`main`**
   (o de `05d6c63e`), **nunca** de la rama Autotrac.
2. Verificar: `GET /mantenimiento/notificaciones/resumen` deja de dar error (responde 302 al login
   como cualquier ruta con sesión), y el cron `POST internal/cron/notifications/dispatch` no
   escribe CRITICAL en el log.
3. Si además se quiere el aviso nuevo de lecturas a gerencia en producción, hace falta la
   migración `2026-10-23-140000_AddManagementReadingReportSettings` (panel Superadmin: Ferozo no
   tiene CLI).

> **Desvío deliberado respecto de este plan:** no se subió "main" entero, sino **un solo archivo**
> parcheado (el adaptador). Motivo: el `Services.php` de producción es **más nuevo que cualquier
> rama local** y subir cualquiera de ellas lo hubiera roto. Ver §9.1.

### P0 — Cortar la fuga de staging — **HECHO 24/10 → §9.2**
1. `configuracion_canales_globales` en staging: `whatsapp_enabled=0` (o `whatsapp_pilot_enabled=1`)
   y `smtp_enabled=0`.
2. Quitar del contenedor de staging las credenciales de SMTP de producción (`email.SMTPUser`,
   `email.SMTPPass`) y dejar `email.fromName` distinguible ("Mantenimiento Staging").
3. Verificar que el cron de staging no vuelva a encolar nada a teléfonos o casillas reales.

### P1 — Que staging no vuelva a mandar datos de clientes
Elegir una de dos, y dejarlo escrito:
- **Sincronizar** los datos de TSA desde producción (dump de `lecturas_equipo` + `equipos`) hacia
  staging, o
- **Aislar** staging: dejar solo la empresa demo (hoy staging también le mandó el informe a
  `oscarvogel@gmail.com` con el asunto "Informe diario de mantenimiento · Empresa Demo").

### P1 — Correcciones de contenido (a revisar con calma)
- El digest de WhatsApp **no debería reemitir avisos de un mes**. Hoy reproduce del 29/09 al 17/10.
  Debería regenerar/refrescar la bandeja antes de resumir, o mostrar solo avisos vigentes.
- En el resumen salen a la vez "Última señal: 07/10/2026" y "sin reportar hace 2 días": el
  "sin reportar hace X" **no se calcula desde la fecha de la última señal**. Conviene unificarlo.
- 4 sensores reportan el mismo valor **-348.201,39 L**: con el separador de miles (`-348.201,39`) es
  348.201,39 litros negativos, idéntico en los 4. Huele a valor centinela del gateway Wialon, no a
  cuatro tanques reales. A revisar.

### P2 — Blindaje de código (para que no se repita)
1. **Guard de entorno**: con `CI_ENVIRONMENT != production`, el `base_url()` de los enlaces de
   notificación debe apuntar a un host de pruebas o el mensaje debe quedar marcado como prueba; y
   el envío a teléfonos/casillas reales debería quedar bloqueado salvo lista blanca.
2. **Test de contrato de puertos**: recorrer por reflexión cada interfaz de `app/Application/Notifications/Port`
   y sobre su adaptador, y fallar si al adaptador le falta un método. Con eso, un deploy parcial se
   rompe en la suite y no en producción.
3. **Deploy atómico**: el proceso de deploy debería subir siempre el conjunto completo de un
   contexto, nunca archivos sueltos de ramas distintas.

---

## 9. Acciones ejecutadas (23-24/10/2026)

### 9.1 Producción (Ferozo) — hotfix aplicado y verificado

**Qué se discovered primero.** El contexto de notificaciones de producción es una **mezcla de
cuatro orígenes**, y no solo de dos:

| archivo | origen real en producción |
|---|---|
| `Port/WhatsAppNotificationDeliveryQueue.php` | `main` (`c5ae69b1`) — declara `previewUserDailyDigests` |
| `CodeIgniterWhatsAppNotificationDeliveryQueue.php` | **rama `codex/autotrac-ferozo-hotfix`** (`0acc757`) — no lo implementa → **FATAL** |
| `CodeIgniterOperationalNotificationEventSource.php` | `HEAD` / working tree |
| `RunNotificationCycle.php` y `ScheduleManagementReports.php` | versión única (parches de ayer) |
| `app/Config/Services.php` | **más nuevo que cualquier rama local** (trae `customerImports`, `tripImports`, `CodeIgniterDashboardLatestReadings`) |

Ese último punto es la razón de no haber subido "main" entero: `Services.php` de producción es
**una plataforma más nueva** que `main`, `HEAD`, la rama Autotrac y el working tree. Reemplazarlo
por cualquiera de ellos rompería funciones que hoy funcionan.

**Qué se hizo.** Se parcheó **un solo archivo**: el adaptador que ya estaba en producción,
agregándole **el método que le faltaba** (`previewUserDailyDigests`, tomado de `main`) y los tres
`use` que ese método necesita. El constructor y el resto de la clase quedaron **byte a byte
igual** que la versión que producción ya tenía.

```
.deploy/hotfix-2026-10-23/backup/CodeIgniterWhatsAppNotificationDeliveryQueue.php   (62640 bytes, copia del estado previo)
.deploy/hotfix-2026-10-23/upload/app/Infrastructure/Notifications/…Queue.php          (67222 bytes)
.deploy/hotfix-2026-10-23/verify_upload.py                                           (verificación por hash)
```

Delta del parche: **+102 líneas y nada más** (3 `use` + 99 del método).

**Verificaciones:**

| chequeo | resultado |
|---|---|
| `php -l` del archivo parcheado | sin errores de sintaxis |
| contrato de puerto por reflexión (interfaz de producción ↔ adaptador parcheado) | 12/12 métodos, **0 faltantes** |
| upload por FTPS | `UPLOAD_OK files=1 bytes=67222` |
| hash remoto vs local | `VERIFY_OK` sha `fc5c6f51…6b27` |
| `GET /mantenimiento/notificaciones/resumen` | **302 → login** (antes: fatal) |
| log de producción | último CRITICAL **19:40:01** (previo al upload); **cero entradas nuevas** en los ticks siguientes |

En producción CI4 solo escribe ERROR/CRITICAL, así que un ciclo sano es silencioso: la ausencia de
entradas nuevas después de las 19:40 es la prueba de que el fatal terminó.

**Prueba de que el canal volvió (leída de la base de producción el 24/10):**

| id | tipo | teléfono | estado | gateway | enviada |
|---|---|---|---|---|---|
| 6263 | `preventivo.vencido` | 3741446823 | ACEPTADA | **delivered** | 23/10 20:15:02 |
| 6257 | `equipo.recordatorio_lectura_semanal` | 3741446823 | ACEPTADA | **delivered** | 23/10 19:50:02 |
| 6236 | `usuario.resumen_diario` | 5493755274301 (Carlos) | ACEPTADA | delivered | 23/10 08:05:02 |
| 6239 | `usuario.resumen_diario` | 5493743400547 (Camila) | ACEPTADA | delivered | 23/10 08:05:02 |

Las dos primeras son **posteriores al arreglo**: antes de las 19:40 el ciclo moría y no había
filas. Además, producción mandó 7 correos `ENVIADA` el 24/10 entre las 07:00:07 y las 07:00:18.

**Lo que NO se tocó:** `Services.php`, `RunNotificationCycle.php`,
`ScheduleManagementReports.php`, la interfaz del puerto y cualquier archivo de la rama Autotrac.

### 9.1.bis Estado real de los canales en producción (leído con la app, 24/10)

Ferozo no tiene SSH ni CLI y la base no está expuesta, así que se usó una sonda de solo lectura
subida por FTPS, con el mismo portón de `migrate.php` (`X-Migrate-Token` contra el
`MIGRATE_TOKEN` del `.env`), que arranca CodeIgniter y consulta la configuración **por la vía de
la app**. Se borró al terminar (verificado: la URL responde 404).

| canal | producción | qué implica |
|---|---|---|
| `whatsapp_enabled` | **1** | habilitado |
| `whatsapp_pilot_enabled` | **0** | **manda a teléfonos reales** (en producción es lo correcto) |
| `whatsapp_instance_id` | `oscar_claro_2` | la instancia real |
| `whatsapp_api_url` | `https://whatsapp.vogelconsultoria.com.ar/` | el gateway real |
| `whatsapp_api_key` | presente y **descifra** | la `encryption.key` de producción es la suya ✔ |
| `smtp_enabled` / `smtp_pass` | 1 / **descifra** | correo operativo |
| `smtp_from_name` | `Mantenimiento` | indistinguible de un correo de staging |

Y dos datos que explican el incidente:

- Producción tenía `whatsapp_enabled=1` con piloto apagado **desde el 23/09**
  (`configuracion_canales_globales.updated_at = 2026-09-23 15:42:38`). O sea: **producción siempre
  estuvo configurada para hablar con clientes**; lo anómalo fue **staging**, que copió esa
  configuración, además del relay y las credenciales.
- Producción **ya había mandando el digest diario a Carlos** el 23/10 a las 08:05 (fila 6236). El
  mensaje con la URL de staging fue un **envío adicional** de las 17:33, hecho desde staging.

**La migración de la feature nueva NO está aplicada en producción**
(`telefono_gerencia`, `informe_lecturas_*` no existen en `empresas`). Por eso el aviso "27 equipos
sin lectura a gerencia" —el que escuchó Carlos— **solo puede salir de staging** hasta que se
migre producción.

### 9.2 Staging (`fasa_189`) — fuga cerrada

`configuracion_canales_globales` (id 1) actualizado el 24/10 10:06:48 UTC:

| clave | antes | después |
|---|---|---|
| `whatsapp_enabled` | 1 | **0** |
| `whatsapp_pilot_enabled` | 0 | **1** |
| `smtp_enabled` | 1 | **0** |

El ajuste es efectivo porque `CodeIgniterGlobalNotificationSettingsStore::get()` lee **la fila de la
base** (la fuente `database`) y no las variables de entorno; con `whatsapp_enabled=0` el gateway
queda `available() === false` y `scheduleUserDailyDigests()` devuelve 0; con `smtp_enabled=0` el
`CodeIgniterEmailNotificationGateway` corta antes de construir el envío.

**Al cerrarlo se descubrió que staging había vuelto a enviar.** El ciclo de las **07:00 (hora de
Argentina) del 24/10** salió antes del bloqueo (07:06:48) y mandó, otra vez, datos viejos:

| canal | destinatario | qué salió | estado |
|---|---|---|---|
| WhatsApp `informe.lecturas.gerencia` (fila 5962) | 5493755632536 | "27 equipos sin lectura hace más de 7 días" con `IWE2I14` hace 18 días | `gateway_message_id` generado → **entregado** |
| Correo (filas 511-517) | Mariela, Carlos y Camila | avisos de "equipo sin telemetría" y "telemetría anómala" (-348.201,39 L) | `ENVIADA` 07:00:14-07:00:21 |
| Correo (filas 518-520) | Mariela, Carlos y Camila | "equipo sin telemetría" | quedaron `PENDIENTE`, **no salieron** |

Las tres últimas quedaron en `PENDIENTE` porque el corte de SMTP llegó antes de que las tomara el
despacho. Esa es la prueba de que el bloqueo frena de verdad.

El digest diario de las 08:00 **no salió**: el sábado no se manda (`format('N') >= 6` en
`UserWhatsAppDigestSchedule`). Pero el informe diario **no mira el día de la semana**, así que el
fin de de semana también sale. Ojo con eso.

**Verificación del corte (24/10, después del bloqueo):**

```
whatsapp_creadas_despues_del_bloqueo   0
notificaciones_creadas_desde_bloqueo   0
configuracion_canales_globales: whatsapp_enabled=0, whatsapp_pilot_enabled=1, smtp_enabled=0
```

Las tres filas de correo que quedaron `PENDIENTE` (518, 519, 520) nunca se enviaron.

**Relojes en la misma base:** la app escribe las fechas en hora de Argentina
(`app.appTimezone`) y el `NOW()` de MariaDB es UTC. Por eso `configuracion_canales_globales.updated_at`
queda 3 horas adelantado respecto de los `created_at` que escribe la app. No es un error de negocio,
pero engaña al leer.

**Consecuencias a tener en cuenta al prenderlo de nuevo en staging:**

- `smtp_enabled=0` también apaga el botón "Probar email" y las pruebas de SMTP de staging. Para
  volver a probarlas hay que prenderlo desde Superadmin, sabiendo que el relay configurado es
  **el de producción** (`a0110632.ferozo.com`) con las credenciales de producción.
- El `smtp_from_name` de la fila de la base dice **"Mantenimiento"**, igual que producción, así que
  un correo de staging es indistinguible de uno de producción. La variable de entorno del contenedor
  dice "Mantenimiento Staging", pero la base manda. Conviene distinguirlo antes de prender el canal.
- `whatsapp_enabled=0` deja staging sin WhatsApp. Al volver a prenderlo, el blindaje de §9.4
  obliga a que salga solo al **teléfono piloto** (3743667526).

### 9.3 Prueba de regresión agregada

`tests/unit/Infrastructure/Notifications/NotificationPortImplementationContractTest.php`

Recorre por reflexión cada puerto de `app/Application/Notifications/Port/` y sus implementaciones
en `app/Infrastructure/`, y falla si a un adaptador le falta un método. Con el estado actual de
`main` pasa (12/12); con el estado que tenía producción rompía.

Suite del área de notificaciones: **96 tests, 682 assertions, OK**.

### 9.4 Blindaje: el modo piloto no se puede apagar fuera de producción

`app/Infrastructure/Notifications/CodeIgniterGlobalNotificationSettingsStore.php`

El modo piloto era **una palanca que se podía apagar por error**: en staging estaba en 0 con la
instancia real de WhatsApp cargada, y por eso el aviso fue al celular del cliente. Ahora, en un
ambiente de prueba (`CI_ENVIRONMENT` = `development` o `testing`), `whatsapp_pilot_enabled` es
**siempre verdadero**, aunque la fila de la base diga 0:

- Lo que sigue funcionando: superadmin puede apuntar el **teléfono piloto** al celular que quiera
  para una prueba concreta. La decisión queda a la vista.
- Lo que deja de pasar: un ambiente de prueba **no puede** escribirle a un teléfono que no sea el
  piloto.
- Si `CI_ENVIRONMENT` no está definido **no se toca nada**, para que un despliegue de producción
  mal configurado no quede mudo.

Prueba: `tests/unit/Infrastructure/Notifications/NonProductionPilotModeGuardTest.php` (5 casos
contra SQLite: staging, testing, producción, ambiente no declarado y piloto ya prendido).

La pantalla de Superadmin lee los mismos valores efectivos, así que muestra el estado real y no el
de la fila: si en staging el piloto queda forzado, la pantalla lo muestra prendido.

Suite del área de notificaciones con los tres cambios: **101 tests, 687 assertions, OK**.

Suite completa:

```
Tests: 1230, Assertions: 4394, Failures: 7, Skipped: 22
```

Los **7 fallos son los mismos 7 preexistentes** que ya estaban documentados esta mañana en
`BITACORA_2026-10-23_INFORME_LECTURAS_GERENCIA.md` (§5): `ManageTelemetryEquipmentLinksTest`,
`HomeRedirectTest`, `LoginRedirectTest`, `QrTechnicalFailureReporterTest`,
`ExpirationDuplicateValidationContractTest`, `ExpirationRenewalFlowContractTest` y
`PreventivePlansPayloadTest`. Ninguno es de este trabajo (se comprobó por nombre contra la lista
documentada). Los 7 tests nuevos de este trabajo pasan.

---

## 10. Pendientes que requieren decisión del usuario

1. **Staging y los datos de clientes**: decidir entre sincronizar producción → staging, o dejar
   staging solo con la empresa demo. Mientras siga la copia congelada al 08/10, cualquier aviso a
   TSA desde staging es falso.
2. **Avisar a Carlos / Mariela**: el 23/10 y el 24/10 a las 07:00 salió de staging un aviso con
   datos viejos ("IWE2I14 hace 18 días"). Hay que explicarles que fue un error de ambiente, no del
   sistema, y que la lectura real del camión estaba confirmada.
3. **Deploy de la feature nueva a producción**: el aviso de lecturas a gerencia (commit
   `05d6c63e`) todavía no está en producción, y requiere la migración
   `2026-10-23-140000_AddManagementReadingReportSettings` (panel Superadmin: Ferozo no tiene CLI).
   La interfaz del puerto de producción es la de `main`; al subir la feature hay que subir
   **interfaz + adaptador + use case + read model + `Services.php` + migración**, todo del mismo
   commit, o se repite el mismo fatal.

   Estado verificado el 24/10 (main ya avanzó a `eb2bf267`):

   | archivo | producción | `main` (eb2bf267) |
   |---|---|---|
   | `Port/WhatsAppNotificationDeliveryQueue.php` | 1340 (la vieja) | 1870 (con `enqueueManagementReadingReport`) |
   | `CodeIgniterWhatsAppNotificationDeliveryQueue.php` | 67222 (el parcheado de §9.1) | 67321 |
   | `app/Config/Services.php` | **84332** | **86334** |
   | `RunNotificationCycle.php` | 5634 | 6121 |
   | `ScheduleManagementReports.php` | 26564 | 27826 |
   | `CodeIgniterOperationalNotificationEventSource.php` | 17443 | 17443 (idéntico) |

   - `main` es **coherente entre sí**: los 13 métodos del puerto están implementados en su
     adaptador, incluido `enqueueManagementReadingReport`.
   - El `Services.php` de producción **no es** el de `main` (ni al revés): producción corre una
     build de plataforma que `main` todavía no tiene. Por eso el arreglo de §9.1 fue surgical y
     no un deploy de `main`.
   - Para llevar la feature a producción hay que **fusionar** el `Services.php` de `main` con el de
     producción (o desplegar la build de plataforma que-production corre) y subir la migración.
     Reemplazar a ciegas el `Services.php` de producción por el de `main` rompería lo que hoy
     funciona.
4. **Credenciales de producción en staging**: el contenedor de staging tiene
   `email.SMTPUser`/`email.SMTPPass` del relay de producción. La fila de la base ya lo corta, pero
   lo correcto es vaciar esas variables en Coolify.
5. **SMTP que marca fallo cuando el correo sí salió** (sección 7): el `QUIT` falla y CI4 lo
   reporta como error, dejando el envío en `REINTENTO` con `alerts.smtpMaxRetries=3`. Puede
   reenviar el mismo correo al cliente.
6. **Digest que reproduce un mes de avisos**: hoy el digest de WhatsApp reproduce avisos del
   29/09 al 17/10. Debería refrescar la bandeja antes de resumir.
7. **Sentido de "sin reportar hace X días"**: en el resumen conviven "Última señal: 07/10/2026" y
   "sin reportar hace 2 días"; ese plazo no se calcula desde la fecha de la última señal.
8. **Los 4 sensores con -348.201,39 L idénticos**: con separador de miles son 348.201,39 litros
   negativos en cuatro equipos distintos. Huele a valor centinela del gateway Wialon.


---

## 10. Cómo se verificó (trazabilidad)

| Evidencia | Cómo |
|---|---|
| Env de staging | `ssh fasa_189 "docker exec <app> env \| grep -Ei 'baseURL\|smtp\|email'"` |
| Base de staging | `docker exec -e MYSQL_PWD=… <mariadb> mariadb -umantenimiento -D mantenimiento_staging` |
| Logs de producción | FTPS `RETR /writable/logs/log-2026-10-23.log` (archivo único, sin listar el docroot) |
| Archivos de producción | FTPS `RETR` de archivos puntuales (`app/…`) |
| Coherencia de ramas | `git diff --stat c5ae69b1 0acc757 -- app/Application/Notifications app/Infrastructure/Notifications` |
| Audio | transcrito con `mcode-tools connector call connector__matrix__listen_audio` |
