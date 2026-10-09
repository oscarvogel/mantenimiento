# Bitácora 2026-10-09 — Investigación e inicio de integración Wialon

Estado: investigación cerrada, primer vertical implementado y verificado.
Rama de trabajo: `fix/473-alertas-celulares-regularizados` (sin commitear al momento de escribir esto).

---

## 1. Qué se hizo

Investigación de punta a punta sobre la integración entre la telemetría Wialon
y la plataforma de mantenimiento, en cuatro fases: auditoría del código, mapa
de dominios, estudio de la documentación oficial de Wialon y consulta de la
cuenta real con el token del usuario.

Después se implementó el primer vertical: la alerta de equipos con telemetría
estancada, integrada al ciclo de notificaciones existente.

## 2. Hallazgos que cambian decisiones

### 2.1 La API devuelve HTTP 200 con el error dentro del cuerpo

Wialon responde `200 OK` y mete `{"error":4,"reason":"..."}` en el body.
Un cliente que sólo mire el status code lee una respuesta válida.

**Consecuencia concreta que se pidió:** el primer intento de inventario devolvió
"0 unidades en los 15 tipos de objeto" y por un momento se reportó una flota
vacía. Era un error de validación de parámetros, no una cuenta sin datos.

El adaptador `WialonRemoteApiGateway` propaga todo error del body como
excepción. Hay un test de humo que lo comprueba con un token inválido.

### 2.2 Los métodos con nombre clásico ya no existen

La documentación actual **no incluye** `object/values`, `object/search_props`,
`core/get_item`, `history/*`, `remote_command` ni `core/get_wialon_stat`.
Eso explica el `{"error":2}` que se venía viendo: no era falta de permisos del
token, era que esos servicios ya no están.

Equivalencias vigentes:

| Método clásico (inexistente) | Vigente |
|---|---|
| `object/values` | `unit/calc_last`, `unit/calc_sensors` |
| `history/exec`, `history/get` | `messages/load_interval`, `messages/get_messages` |
| `core/get_item` | `core/search_item` |
| `core/get_wialon_stat` | `core/get_statistics` |
| `remote_command` | `unit/exec_cmd` |
| `notifier/create` | `resource/update_notification` |
| `address/geofencing` | `resource/get_zone_data` |

### 2.3 `core/search_items` exige `spec` anidado y `avl_unit`

```json
{"spec":{"itemsType":"avl_unit","propName":"sys_name","propValueMask":"*","sortType":"sys_name"},
 "force":1,"flags":65535,"from":0,"to":0}
```

Los parámetros van dentro de `spec`, no al tope. Y el tipo es `avl_unit`, no
`unit`. Sin `propMask` el servidor responde con el esquema esperado, lo que
sirve de pista para deducir la forma correcta.

Con `flags=65535` una sola llamada devuelve toda la flota con posición, sensors,
kilometraje y último mensaje. **14 unidades en 3,4 segundos.**

### 2.4 No hay sensor de odómetro, pero sí odómetro

Ninguna unidad tiene sensor `mileage` ni `engine hours`, y no hay nada de ECU
ni CAN. Se concluyó inicialmente que no había odómetro y **era incorrecto**:
Wialon calcula ambos valores y los expone en `unit/calc_last`.

Las 14 unidades tienen kilometraje y horómetro.

### 2.5 El odómetro calculado coincide con nuestro dato propio

Contraste sobre las 13 unidades presentes en ambos sistemas:

| Placa | Nuestro | Wialon | Dif. | % |
|---|---|---|---|---|
| HLD283 | 1.865.007 | 1.866.321 | −1.314 | −0,1% |
| JLH877 | 1.718.119 | 1.719.617 | −1.498 | −0,1% |
| HWW968 | 1.708.407 | 1.708.941 | −534 | −0,0% |
| OJG526 | 1.216.168 | 1.218.435 | −2.267 | −0,2% |
| OIU270 | 1.193.638 | 1.193.568 | +70 | +0,0% |
| AC532DD | 1.030.000 | 1.027.660 | +2.340 | +0,2% |
| NEB021 | 1.038.201 | 1.038.197 | +4 | +0,0% |
| AB499OK | 1.001.749 | 1.001.119 | +630 | +0,1% |
| AD738EA | 861.523 | 864.407 | −2.884 | −0,3% |
| AE223WN | 729.850 | 730.569 | −719 | −0,1% |
| AF117ZK | 489.758 | 490.757 | −999 | −0,2% |
| AF081MJ | 487.949 | 487.500 | +449 | +0,1% |
| AG007VR | 368.097 | 367.876 | +221 | +0,1% |

**Diferencia máxima: 2.884 km sobre camiones de hasta 1,87 millones de km (0,3%).**
Los deltas son consistentes con el tiempo transcurrido desde nuestra última
lectura, no con deriva del GPS.

**Advertencia registrada:** son valores calculados por distancia GPS y
detección de motor, no el odómetro físico del vehículo. Sirven para planning,
no como respaldo legal ni contable.

Una unidad es la excepción: `RENAULT 440 GZD587` reporta 167.548 km con 8.763 h
de motor, combinación imposible (19 km/h de media de vida). Hay que revisar
esas horas antes de confiar en ellas. Es también el único camión de TSA sin
kilometraje propio, sin plan preventivo y sin sensor de combustible.

### 2.6 El combustible existe y es en vivo, pero es 100% de Wialon

En nuestra base hay **cero** datos de combustible: ninguna tabla de las 59, ni
columnas, ni código de dominio.

En Wialon hay dos tanques por camión, alimentados por dos entradas analógicas
(`io_2_779` hasta 550 L e `io_2_780` hasta 230 L) con curvas de calibración ya
cargadas, y la detección de sutilización ya configurada
(`minTheftVolume: 18`, `minTheftTimeout: 60`, `minFillingVolume: 20`) más los
parámetros de consumo por modo.

Cobertura: **12 de 14 unidades**. Faltan MRCDS 2035 y RENAULT GZD587.

Detalle de parseo: los parámetros del último mensaje viven en `lmsg.p`, no en
`lmsg.ps`. Buscar `lmsg.ps` devuelve vacío y hace creer que no hay telemetría.

### 2.7 La cuenta está sin configurar y no hace falta configurarla

| Objeto | Cantidad |
|---|---|
| Unidades | 14 |
| Geocercas | 0 |
| Rutas | 0 |
| Notificaciones | 0 |
| Conductores | 0 |
| Remolques | 0 |
| Recursos | 1 |

**Decisión:** no configurar nada en Wialon. Geocercas, conductores, rutas y
alertas son datos nuestros y los tenemos mejor (filtrados por empresa,
sucursal y permiso). Wialon entra como **gateway de telemetría**, no como
sistema de registro: no hay doble fuente de verdad ni conflicto de maestros.

## 3. Bugs encontrados en la plataforma

### 3.1 El cierre de OT no valida saltos grandes (producción, 08/10)

**Este es el hallazgo más importante y no tiene relación con Wialon.**

`ITV9J84` quedó con `km_actual = 11.515.914` cuando su última lectura real es
**1.159.336 km**. Diferencia: 10.356.578 km.

Cronología, según `created_at` (no `fecha_lectura`):

| Cuándo pasó | Qué |
|---|---|
| 24/09 – 07/10 | Lecturas QR correctas: 1.154.487 → 1.157.083 → 1.159.336. `km_actual` acompaña |
| **08/10 18:39** | Se cierra una OT con una lectura retrodatada al 23/09 de 11.515.914 km |

**Causa.** `Equipment::recordUsage()` (`app/Domain/Assets/Equipment.php:155-168`)
sólo rechaza retrocesos: compara `lectura < actual`. Un valor exagerado pero
*mayor* pasa limpio. El control de salto grande (`MAX_KM_JUMP = 5000`) existe
únicamente en `PublicEquipmentReadings.php:23,302-307`, o sea sólo en el QR.

**Asimetría de validación entre dos caminos de carga:**

| Camino | Retroceso | Salto grande |
|---|---|---|
| QR público | Bloquea | Bloquea con confirmación |
| Cierre de OT | Bloquea | **No revisa** |

**El mismo error ocurrió dos veces el 08/10.** En `AB499OK` el chofer tecleó
10.017.497 y el QR lo frenó con la confirmación de salto; se resolvió como
"Error de carga en el chofer". `ITV9J84` es el mismo tipo de errata (dígito de
más) pero entró por la orden de trabajo y pasó sin filtro.

**Impacto silencioso.** El plan preventivo tiene intervalo de 35.000 km, así que
el próximo vencimiento se calcula en 11.550.914 km. Como el camión va por
1.159.336, **nunca va a vencer**. El sistema lo reporta como perfectamente
mantenido, sin dar ningún error.

**Pendiente de arreglo:** replicar el control de salto en el cierre de OT.

### 3.2 La guarda anti-retroceso no tiene salida de emergencia

Un solo valor malo la convierte en un tope permanente. No existe operación de
recalibración ni detección de que `km_actual` se alejó de las lecturas recientes.

En 5 casos se salvó porque alguien anuló la lectura a mano y cargó la
correctiva con motivo (`HWW968`, `AD738EA`, `AB499OK`, `IWE2I14`, `IYT2F23`).
El mecanismo existe y funciona. Lo que no existe es detectar solo el caso
que nadie revisó.

### 3.3 Datos: el historial tiene basura, el estado actual está limpio

Las lecturas corruptas vienen de `CARGA_RAPIDA` (17/08 – 17/09). Ordenadas por
fecha, la serie converge: de las 6 unidades con saltos, **5 se recuperaron** y
sólo `ITV9J84` quedó mal, por el bug 3.1.

De los 27 camiones de TSA, 26 tienen `km_actual` idéntico a su última lectura.

### 3.4 La patente NEB021 existe en dos empresas

`equipos.id=1` pertenece a "Empresa Demo S.A." (sin km, creada 2026-08-10
14:38) e `equipos.id=43` a TSA (con km, creada 2026-08-10 22:19). Ninguna está
dada de baja: `deleted_at` en `NULL` y `estado = ACTIVO` en las dos.

El listado está bien filtrado por empresa. **Por eso el vínculo con Wialon no se
resuelve por patente**: se resuelve por identificador externo, con la empresa
como parte del alcance.

## 4. Qué se implementó

Alerta de **equipos sin telemetría y fuentes caídas**, integrada al ciclo de
notificaciones existente.

### 4.1 El modelo es multi-fuente desde el arranque

El primer intento usaba una columna `telemetria_unidad_id` en `equipos` y un
token global en `.env`. Se descartó antes de commitear: la realidad es que
**una empresa puede tener varias cuentas de proveedor y un mismo equipo puede
reportar a más de un sistema a la vez**. La columna única no podía expresarlo,
y con dos proveedores el caso de uso emitía **dos alertas de flota para el
mismo camión**, porque cada fuente tenía su propio ciclo en la clave lógica.

Modelo definitivo, dos tablas:

```
integraciones_telemetria          una fila por credencial
  empresa_id + proveedor + nombre   único
  endpoint · token_cifrado · activo
  ultimo_ok_en · consecutivos_fallidos · ultimo_error

equipo_telemetria                 puente, N fuentes por equipo
  integracion_id · equipo_id · unidad_externa · rol
  único (integracion_id, unidad_externa)
  único (equipo_id, integracion_id)<-- no impide dos proveedores distintos
```

El token se cifra con el mismo `encrypter` que SMTP, WebPush y WhatsApp, y
**nunca sale de Infrastructure**: los puertos pasan identificadores enteros, no
credenciales.

### 4.2 La regla que cambió

```
Domain/Telematic/EstadoSenal.php       señal de una fuente (regla pura)
Domain/Telematic/FuenteSenal.php       una fuente + su estado
Domain/Telematic/CoberturaEquipo.php   la regla de agregación
Application/Telematic/                IntegracionTelematrica + DiagnoseSilentUnits
Application/Telematic/Port/            5 puertos
Infrastructure/Telematic/             gateway, almacén de credenciales, catálogos, registro
Infrastructure/Notifications/         fuente a prueba de caídas + composite
Migrations/2026-10-09-143000_...       integraciones_telemetria + equipo_telemetria
```

La pregunta dejó de ser "¿este proveedor reporta?" y pasó a ser
"¿alguna fuente de este equipo trajo señal fresca?". De ahí salen **dos hechos
que antes iban mezclados en uno**:

| Evento | Severidad | Significa |
|---|---|---|
| `equipo.sin_telemetria` | WARNING | Tiene fuentes y **ninguna** reporta. Hay un hueco real de monitoreo. |
| `fuente.telemetria_caida` | INFO | Una fuente dejó de responder pero otra sigue viva. Es un problema de integración, no de flota. |

Sin esa separación, un camión con Wialon mudo y Gestya reportando genera dos
notificaciones al operador diciendo lo mismo.

### 4.3 Otras decisiones

- La clave lógica de la alerta de flota usa la **señal más reciente entre todas
  las fuentes**, así que repetir la corrida no duplica nada, y un equipo que
  vuelve y se corta de nuevo sí genera alerta nueva.
- **Un proveedor caído no genera alerta de flota.** Si el adaptador falla, la
  señal se trata como ausente, y ausente no es lo mismo que vieja: es un
  problema de integración. Evita inventar "../camiones sin señal" cuando lo que
  pasó es que Wialon no respondió.
- Cada integración lleva `ultimo_ok_en` y `consecutivos_fallidos`. Sin eso, una
  integración caída y una flota tranquila son indistinguibles, y el operador ve
  silencio y asume que todo anda bien.
- Registro de gateways: sumar Gestya es agregar una línea. Ni el dominio ni los
  casos de uso cambian.

Configuración en `.env.example` (el token ya **no** va ahí):

```ini
WIALON_ENABLED=false          # kill switch
WIALON_SILENCE_HOURS=24       # 0 desactiva el criterio
WIALON_TIMEOUT_SECONDS=30
```

## 5. La instantánea: la base para mapa y ficha

### 5.1 Por qué persiste y no se consulta en vivo

La pantalla no puede llamar a Wialon en cada visita: serían 3 segundos por
equipo, los límites de Wialon son por IP, y la ficha se rompería cada vez que el
proveedor caiga. Por eso el cron toma la instantánea y la guarda; la vista lee
nuestra base.

Por eso la fila guarda **`observada_en`**, que es la del proveedor, separada de
`registrada_en`, que es cuándo la guardamos nosotros. Sin esa distinción, un
punto en el mapa no dice si el camión está ahí o estuvo ahí hace una semana.

### 5.2 Una sola llamada trae la ficha completa

`unit/calc_last` con `flags=15` devuelve posición, odómetro, horómetro y los
valores **ya calibrados** de todos los sensores. Del IVECO 440 AF081MJ:

```json
"mileage": 487.499,71 km    "engine_hours": 4.062,55 h
"pos": -33,0944 / -68,8800  25 satélites
"sensors": {"1": 24,51 V, "2": "Apagado", "5": 660,48 l, "6": 166,45 l, "7": 494,03 l}
```

El combustible llega **en litros y ya convertido**: 660 de ~780 de capacidad.

Corrección de una estimación propia: yo había calculado a mano unos 70 litros
leyendo la tabla de calibración del sensor y estaba mal. Wialon devuelve el
valor convertido; por eso el dominio nunca debe recalcular calibraciones.

### 5.3 Hace falta más de una llamada

`unit/calc_last` trae los valores **sin nombre ni tipo**:
`{"5":{"value":660.47,"format":{"value":"660.48 l"}}}`. Las definiciones con
nombre y tipo (`n`, `t`, `m`) vienen en `core/search_items`. El adaptador une
ambas por identificador de sensor.

Por eso la ingesta son dos llamadas por integración y no una.

### 5.4 La traducción es por tipo, no por nombre

| Tipo de sensor en Wialon | Nuestro concepto |
|---|---|
| `voltage` | voltaje |
| `engine operation` | motor encendido/apagado |
| `fuel level` | combustible en litros |
| `digital` (ralentí) | se reconoce por etiqueta |
| cualquier otro | `sensoresAdicionales`, con su nombre original |

El nombre lo edita el cliente desde el panel de Wialon y cambia cuando quiere;
el tipo es parte del contrato de la plataforma. Un sensor que no sabemos
interpretar **no se inventa ni se esconde**: se guarda con su etiqueta y su
unidad para que la ficha lo muestre, pero no entra en la lógica ni dispara nada.

### 5.5 Nunca se borra una instantánea

`telematia_ultima_lectura` es estado actual: una fila por integración y unidad
externa, sobrescrita en cada corrida. Las fuentes que no llegan se marcan
`ausente = 1`, **no se borran**. El último lugar conocido sigue siendo un dato,
y la vista necesita poder decir "esto es de hace tres días" en vez de mostrar
una pantalla vacía.

### 5.6 El consumo no toca nada de negocio

`RecordTelemetrySnapshots` es de sólo lectura contra el proveedor y **no escribe
en `lecturas_equipo`, ni en `equipos.km_actual`, ni en los planes**. Alimentar
lecturas desde telemetría es otra decisión, y hoy está frenada por el hueco de
validación del cierre de OT (sección 3.1).

## 6. Evidencia

- `php -l` sin errores en los 22 archivos nuevos y modificados.
- **Suite completa: 873 tests, 3.359 assertions.** Los 2 fallos siguen siendo
  **preexistentes** (`baseURL` duplica `/mantenimiento/mantenimiento/`). Línea
  de base: 825 tests con los mismos 2 fallos.
- **48 tests de telemetría en verde**, incluidos:
  - el mapeador, con un fixture tomado de una respuesta real
  - que dos fuentes muertas generan **una sola** alerta de flota
  - que una fuente muerta con otra viva genera **sólo** la de integración
  - que un proveedor caído **no** genera alerta de flota
  - que una instantánea sin señal no se guarda
  - que `(0,0)` —ausencia de fix— no se traduce a una posición
- Prueba de humo contra la API real: 14 unidades, token inválido →
  `RuntimeException: código 8` **y el fallo queda registrado**.

## 6. Renovación del token

Verificado contra la documentación oficial:

| Hecho | Consecuencia |
|---|---|
| Existe `svc=token/update` con `{"callMode":"create",...}` | La renovación **se puede automatizar** con una sesión viva |
| `dur: 0` = vida infinita | No hay que renovar cada 30 días |
| Cualquier token sin usar **100 días se borra** | Como consultamos a diario, nunca se llega ahí |
| Máximo `dur`: 8.640.000 s (100 días) | Tope por si se quiere una vida finita |
| Máximo 1.000 tokens por usuario | Sin impacto real |

El token actual tiene `fl=-1`: **permisos totales, incluido enviar comandos al
vehículo**. Los bits están documentados y se puede pedir sólo lectura. Debería
hacerse.

Rotación cuando exista la tabla: iniciar sesión con el token vigente →
`token/update` para crear el nuevo → cifrar y guardar → cambiar → revocar el
anterior. Antes de eso no hay dónde guardar el nuevo.

## 7. Pendientes

### Urgente

- [ ] **Control de salto grande en el cierre de OT.** Replicar `MAX_KM_JUMP` con
      su test de regresión. Sin esto, cualquier ingesta nueva hereda el bug 3.1.
- [ ] **Corregir `ITV9J84`** con motivo registrado, o el preventivo queda colgado.

### Integración

- [ ] **Dar de alta la integración.** El token ya no se lee de `.env`: hay que
      crear la fila en `integraciones_telemetria` con el token cifrado. El
      token sigue en `.env` a propósito, para no perderlo hasta que exista esa
      fila y se lo pida a Wialon uno nuevo con sólo lectura.
- [ ] **Vincular las 14 unidades** en `equipo_telemetria`.
- [ ] Activar `WIALON_ENABLED=true`.
- [ ] Probar el ciclo completo por HTTP con `X-Cron-Token`.
- [ ] **Rotar el token**: pedir uno con `fl` de sólo lectura y `dur=0`, y
     Walta del que quedó impreso en una salida de terminal (ver 8.1).

### Datos y negocio

- [ ] Decidir qué hacer con el residuo de "Empresa Demo S.A." (NEB021 duplicado).
- [ ] Revisar las 8.763 h de `GZD587` antes de usar el horómetro.
- [ ] Averiguar por qué 13 de 27 camiones no están en Wialon (cobertura 52%).
- [ ] Confirmar el origen `DEMO` de `lecturas_equipo`: no figura en el enum del
      dominio que se leyó en la auditoría.

### Módulos siguientes

- [ ] **Sección de telemetría en la ficha del equipo** con el último lugar
      conocido en mapa. Es el bloque que desbloquea todo lo visual y ya tiene
      la base de datos lista. **Mostrar siempre la hora de la observación** con
      color por frescura.
- [ ] **Vista de flota** con el mapa completo y el estado de cada equipo.
- [ ] **Combustible por alertas** (nivel bajo, caída brusca): viable hoy con el
      dato actual.
- [ ] **Rendimiento km/l y excesos de velocidad**: quedó localizada la firma
      del histórico, `unit/calc_sensors` con
      `{source, indexFrom, indexTo, unitId, sensorId}`. Falta probarla.
- [ ] **Lecturas de km/horas desde Wialon**: el odómetro ya está validado, pero
      falta resolver el actor de sistema (hoy `EquipmentReading` exige
      `usuario_id`) y agregar `TELEMETRIA` al enum `origen`. **Detenido hasta
      cerrar el bug 3.1.**

### Decisiones abiertas

- [ ] **Quién manda cuando hay dos fuentes.** `equipo_telemetria.rol`
      (`PRINCIPAL` / `SECUNDARIA`) existe pero todavía no se usa para decidir.
      Con dos sistemas reportando odómetro hay que fijar precedencia, o se
      repite el problema del salto grande con otro nombre. Hoy Wialon es dato
      calculado por GPS y el del chofer es el real.
- [ ] **Wialon tiene un producto propio, Fleetrun**, de mantenimiento de flota
      con intervalos, costos y consumos. Solapa con el contexto de preventivo.
      Vale la pena evaluarlo comercialmente antes de construir en profundidad.
- [ ] **Cobertura de Gestya.** Cuando entre, se escribe el adaptador y se
      registra; el dominio no se toca. El `AGENTS.md` ya lo nombra como futuro
      *Anti-Corruption Layer* de importaciones.

**Resuelto durante la implementación:** el nombre de las variables dejó de ser
problema. `WIALON_ENABLED`, `WIALON_SILENCE_HOURS` y `WIALON_TIMEOUT_SECONDS`
siguen con prefijo, pero ya no hay secretos en el entorno: el token y el
endpoint pasaron a `integraciones_telemetria`, así que el prefijo nombra la
integración y no una credencial.

### Apéndice: firma del histórico por sensor

```
svc=unit/calc_sensors&sid=<eid>&params={
  "source":"...", "indexFrom":<unix>, "indexTo":<unix>,
  "unitId":28396292, "sensorId":5
}
```

El esquema lo devuelve el propio servidor en el error
(`VALIDATE_PARAMS_ERROR`), así que se puede deducir sin documentación. **Es la
firma buscada para el histórico**: por sensor, con ventana temporal. Con esto
el rendimiento km/l y los excesos de velocidad dejan de estar bloqueados.

---

## Apéndice

### Cómo reproducir la consulta a Wialon

```bash
# login (guardar el eid)
curl -s "https://hst-api.wialon.com/wialon/ajax.html?svc=token/login&params=%7B%22token%22%3A%22...%22%7D"

# flota completa con posición, sensores y último mensaje
curl -s "https://hst-api.wialon.com/wialon/ajax.html?svc=core/search_items&params=<spec-urlencoded>&sid=<eid>"

# odómetro y horómetro calculados
curl -s "...?svc=unit/calc_last&params=%7B%22itemIds%22%3A%5B28396292%2C...%5D%7D&sid=<eid>"

# cerrar sesión (la sesión expira sola a los 5 minutos)
curl -s "...?svc=core/logout&params=%7B%7D&sid=<eid>"
```

El token **nunca** debe ir en el frontend, en un log, en un commit ni en una
respuesta del asistente. En PHP se resuelve desde `integraciones_telemetria`,
se descifra dentro de Infrastructure y no se propaga: los puertos llevan
identificadores enteros.

### Reglas del proveedor que condicionan el diseño

| Regla | Impacto |
|---|---|
| HTTP 200 con error en el body | Hay que leer el cuerpo, siempre |
| Sesión expira a los 5 min | Login y logout por lectura; no cachear `sid` |
| Límites por **IP**, no por token | 10 intentos fallidos/minuto bloquean la IP completa |
| El token nunca supera los derechos del usuario | Least privilege: un token de lectura, otro acotado |
| Documentación oficial en inglés | URLs canónicas: `help.wialon.com/en/api` |

### 8.1 Incidente de seguridad durante la investigación

Una llamada a PowerShell falló con "no se encuentra ningún parámetro posicional
que acepte el argumento" y el error imprimió el token completo en la terminal.
Causa: definí una función `Wget`, que es un alias de PowerShell para
`Invoke-WebRequest`, y los alias ganan sobre las funciones.

Mitigación aplicada: el token dejó de pasarse como argumento posicional y las
llamadas van por `curl.exe` nativo. Aun así corresponde rotarlo.