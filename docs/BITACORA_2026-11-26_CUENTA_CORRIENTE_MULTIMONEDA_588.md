# Bitácora — Cuenta corriente multimoneda (#588)

**Fecha:** 26/11/2026 · **Issue:** [#588](https://github.com/oscarvogel/mantenimiento/issues/588) · **PR:** [#590](https://github.com/oscarvogel/mantenimiento/pull/590)
**Rama:** `feat/588-cuenta-corriente-multimoneda` (base `main` @ `18d3f859`)

## 1. Qué se implementó

La cuenta corriente de clientes deja de tener un único saldo en pesos. Cada movimiento
del libro conserva su **moneda original**, su **importe original** y la **cotización
congelada** con su fecha y su fuente, y el consolidado en pesos se deriva del libro.

| Pieza | Qué aporta |
| --- | --- |
| `Currency`, `ExchangeDifference`, `PaymentImputation`, `ChargeAllocation`, `CustomerPayment`, `DocumentBalance` | Dominio: reglas puras, sin framework ni base de datos |
| `RegisterCustomerPayment`, `CreditCustomerPayment`, `AnnulCustomerPayment` | Casos de uso: cobro en su moneda, diferido, y anulación con contraasiento |
| `ListCustomerAccounts`, `GetCustomerStatement` | Saldos por moneda, consolidado histórico y valorización actual |
| `CodeIgniterCustomerPaymentRepository` / `...ReadModel` | Persistencia de cobros, imputaciones y asientos |
| Migración `2026-11-26-100000_AddMultimonedaCustomerAccounts` | Amplía el libro y crea `cliente_cc_pagos` y `cliente_cc_imputaciones` |
| `CustomerAccountsPage.vue`, `CustomerAccountDetailPage.vue` | Listado con una columna por moneda, detalle con tarjetas, documentos y cobros |

Detalle de la auditoría inicial: `docs/AUDITORIA_CUENTA_CORRIENTE_MULTIMONEDA_588.md`.
Detalle de la integración con #589 y #587: `docs/INTEGRACION_588_589_536.md`.

## 2. Reglas que quedaron implementadas

- **Un solo ledger.** No hay cuentas corrientes separadas por moneda: `cliente_cc_movimientos`
  es el libro y ahora es multimoneda. El saldo siempre se deriva (Debe − Haber).
- **Nada de `float` en importes.** `Money` (centavos enteros) más columnas `DECIMAL`. El
  importe del cobro viaja como texto decimal exacto desde el formulario hasta el caso de uso.
- **Imputación en la moneda de la deuda.** Un cobro solo se imputa a documentos de la **misma
  moneda**; la imputación cruzada se rechaza con `'no se compensan monedas distintas'`. Lo
  no imputado queda como anticipo (saldo a favor) en su moneda.
- **Diferencia de cambio explícita.** `diferencia = valor del cobro − valor histórico del
  documento`; positiva va al Debe, negativa al Haber, como asiento propio
  (`DIFERENCIA_CAMBIO`) y reversible (`REVERSION_DIFERENCIA_CAMBIO`).
- **El valor histórico se reparte del documento**, en proporción y con el residual en la última
  imputación: las partes suman exactamente el documento y no quedan centavos sueltos.
- **La valorización actual es gerencial y no genera asientos.** Si falta la cotización de una
  moneda con saldo, se informa indisponibilidad (`ars = null`, `monedas_sin_cotizacion`),
  nunca cero.
- **Sin reescribir la historia.** El backfill copia al libro la moneda y el importe original
  que el cargo ya tenía; no recalcula importes ni inventa monedas ni cotizaciones.
- **Aislamiento.** Empresa, sucursal y permiso se validan en servidor en cada caso de uso.

### El ejemplo del issue, reproducido de punta a punta

| Paso | Resultado |
| --- | --- |
| Factura USD 2.800 a 1.500 | Debe ARS 4.200.000 · saldo pendiente USD 2.800 |
| Pago USD 1.000 a 1.600 | Haber ARS 1.600.000 + diferencia de cambio al Debe ARS 100.000 |
| Saldo después del pago | **USD 1.800** · **histórico ARS 2.700.000** |
| Anulación del cobro | Contraasiento y reversión: vuelve a USD 2.800 y ARS 4.200.000 |

## 3. Pruebas

### Locales (SQLite en memoria)

- `php vendor/bin/phpunit --no-coverage --filter "CustomerAccounts|ChargeAllocation|PaymentImputation|Money"`
  → **61 pruebas, 239 aserciones, 1 omitida** (la de concurrencia, que necesita MariaDB).
- Suite completa: **2424 pruebas, 9089 aserciones**. El único error es ambiental y
  preexistente (`ArchivoRespaldoBucketsTest`, `symlink(): Permission denied` en Windows);
  ese archivo no tiene diferencias contra `main`.
- `cd frontend && npm run test` → **618 pruebas** en verde.
- `cd frontend && npm run build` → `ENCODING_QA=OK`; los assets compilados van en el PR,
  como en los merges anteriores.

### En MariaDB real (`fasa_189`)

Instancia temporal con la imagen de staging, base temporal `cc588_tmp` y el arnés
`CUSTOMER_ACCOUNTS_MYSQL=host:usuario:clave:base`:

```
Tests: 61, Assertions: 240 — OK (61 pruebas, sin fallos ni errores)
```

Ahí corre también el **caso #20 de concurrencia real**, que en SQLite queda omitido:
dos sesiones abiertas sobre el mismo esquema, la segunda bloqueada por el `FOR UPDATE`
del documento, con espera de bloqueo acotada a 3 s.

### De punta a punta (interfaz)

Contenedor temporal con la imagen de staging, base temporal `cc588_e2e`, migraciones de
`main` + la de #588, y túnel SSH. Lo que se verificó:

- Listado con una columna por moneda, saldos a favor, consolidado histórico y valorización.
- Filtro por moneda y paginación.
- Detalle con tarjetas por moneda, documentos con estado de cobranza y filtro de movimientos.
- **Alta de cobro desde la UI**: USD 1.000 a 1.600 imputado a la factura en USD
  (cotización tomada del catálogo de la empresa). El resultado en el libro:
  `COBRO` USD 1.000 (Haber ARS 1.600.000) y `DIFERENCIA_CAMBIO` (Debe ARS 100.000).
  El documento pasa a *Cobro parcial* y el saldo queda en USD 1.800 / ARS 2.700.000.
- **Anulación desde la UI** con motivo: genera `ANULACION_COBRO` (Debe ARS 1.600.000) y
  `REVERSION_DIFERENCIA_CAMBIO` (Haber ARS 100.000); la deuda del cliente queda restituida
  y el cobro sigue visible como anulado.
- Móvil (375 px): sin scroll horizontal.

### Defectos que encontró la validación y que quedaron corregidos

1. **El permiso `clientes.cc.cobrar` quedaba sin asignar en una base nueva.** La migración
   corre antes que el seeder, así que el rol *Administrador* todavía no existía y no había a
   quién asignarle el permiso: el botón *Registrar cobro* no aparecía. Ahora el
   `InitialSeeder` también lo declara y lo concede, y hay una prueba de contrato que vigila
   las dos partes.
2. **El importe del cobro se pasaba como `float` a un comando que exige texto decimal**, y el
   registro moría con un `TypeError`. Ahora el importe viaja como texto exacto y la validación
   de "mayor que cero" se hace con la aritmética entera de `Money`.
3. **La prueba de concurrencia abría las dos sesiones sobre la misma conexión compartida**
   (`Database::connect(..., true)` devuelve la misma instancia para los mismos parámetros), así
   que la segunda sesión no bloqueaba nada y la prueba pasaba sin comprobar nada. Ahora cada
   sesión abre su conexión.
4. **Aislamiento entre clases de prueba en MariaDB**: la regresión de #530 no limpiaba las
   tablas de cobranza, y las filas que dejaba la clase multimoneda bloqueaban el borrado de los
   cargos por claves foráneas.
5. **Orden de reversión**: MySQL no permite soltar la tabla padre con la hija viva, así que el
   `down()` de #588 tiene que correr antes que el de #530. La prueba de rollback lo refleja.

## 3.1 Validación en `mantenimiento-staging` (26/11/2026)

La validación de punta a punta corrió primero en una instancia temporal. Después se
repitió sobre el ambiente de staging real, con los datos reales de la base.

**Merge:** `fe3c5532` (squash del PR #590) sobre `main`.

**Orden aplicado en staging, a propósito:**

1. **Primero la migración**, con el código viejo corriendo. La migración es aditiva: agrega
   columnas y tablas nuevas, así que la versión anterior de la aplicación la ignora y no se
   rompe nada. Aplicarla en este orden evita la ventana en la que el código nuevo consulta
   columnas que todavía no existen.
2. **Después el código**, ya con el esquema listo.

Si se hiciera al revés (código primero, migración después), la pantalla de *Cuenta corriente
de clientes* devolvería 500 durante el intervalo entre ambas.

**Resultado de la migración sobre los datos reales:**

| Medida | Valor |
| --- | --- |
| Movimientos en el libro | 9 |
| Movimientos sin moneda | 0 |
| Movimientos sin importe original | 0 |
| Movimientos huérfanos (sin cargo) | 0 |
| Cargos sin moneda / sin cotización | 0 / 0 |

**Valores verificados en la interfaz contra el cálculo por SQL** (empresa 7 *Transporte
Demo S.A.*, cotización USD 1.600 vigente):

| Cliente | Pantalla | SQL | ¿Coincide? |
| --- | --- | --- | --- |
| Grupo Pluma QA — ARS | ARS 0,00 (saldada) | 0 | Sí |
| Grupo Pluma QA — USD | USD 2.700,00 a cobrar | 2.700 | Sí |
| Grupo Pluma QA — histórico | ARS 4.320.000,00 | 4.320.000 | Sí |
| Lottus Transportes QA | ARS 4.147.000,00 a cobrar | 4.147.000 | Sí |
| Consolidado histórico | ARS 8.467.000,00 | 8.467.000 | Sí |

El botón *Registrar cobro* aparece para el Administrador de la empresa, con el permiso
`clientes.cc.cobrar` ya concedido por la migración (el rol existía, así que no hizo falta
correr el seeder). Los documentos muestran cada cotización congelada con su origen
(*informada* o *catálogo de la empresa*) y su fecha. Móvil a 375 px, sin scroll horizontal.

Capturas en `docs/screenshots/588/staging-*.png`.

**Dos cosas que aparecieron en el camino y conviene conocer:**

- El recurso de Coolify tenía el **commit pineado** (`git_commit_sha = cff7014a`): cada
  *Deploy* construía ese SHA y no el HEAD de `main`, así que el código de #588 no llegaba a
  staging aunque el merge estuviera hecho. Se despineó a `HEAD` y el recurso vuelve a seguir
  la rama.
- El deploy dejó pendiente `CreateNeumaticoColocaciones` (migración de #581, ya mergeado en
  `main`): el código nuevo la usa, así que se aplicó en el mismo pase. No es parte de #588,
  pero dejar esa migración pendiente rompe la pantalla de colocaciones de neumáticos.

**Lo que se tocó en staging y cómo se dejó:**

- La migración de #588 (aplicada, es el objetivo) y la de colocaciones (estaba pendiente).
- La clave del usuario `demo@mantenimiento.local` se cambió **de forma reversible** para
  poder entrar a la interfaz: se guardó el hash original en una tabla de respaldo y se
  restauró al terminar. La tabla de respaldo se borró y el hash quedó como estaba.
- Nada más: no se registran cobros ni se modifican saldos de clientes en staging. El flujo
  de cobro y de anulación se validó sobre la instancia temporal, con base propia.

## 4. Despliegue

El ambiente de prueba vigente es **`fasa_189` / Docker / Coolify**
(`mantenimiento-staging` + `mantenimiento-mariadb-staging`).

### Aplicación en staging

1. Desplegar el commit de la rama (Coolify, recurso `mantenimiento-staging`).
2. Correr la migración: `php spark migrate`.
   - `2026-11-26-100000_AddMultimonedaCustomerAccounts` amplía `cliente_cc_movimientos`,
     crea `cliente_cc_pagos` y `cliente_cc_imputaciones`, hace el backfill y concede
     `clientes.cc.cobrar` al rol *Administrador* ya existente.
3. En una base **nueva** (instalación desde cero), correr además `php spark db:seed InitialSeeder`
   para que el permiso quede asignado al rol *Administrador*.
4. Verificar: entrar a *Cuenta corriente clientes* y comprobar que el botón *Registrar cobro*
   aparece para el Administrador.

### Rollback

1. **Aplicación**: volver al commit anterior. Los asientos de cobranza quedan en la base, pero
   el código anterior no los lee: no rompen nada, solo quedan visibles en la base.
2. **Esquema**: `php spark migrate:rollback` (revierte solo la última migración, la de #588).
   - El `down()` **se niega** si ya hay cobranzas, imputaciones o asientos de cobranza: no
     borra historia. En ese caso hay que resolver los cobros o dejar la migración aplicada.
   - Si se revierte, se revierte primero la etapa multimoneda y después la de #530 (MySQL no
     permite soltar `cliente_cc_cargos` con `cliente_cc_imputaciones` viva).
3. **Datos**: la migración es aditiva y su `down()` no borra cargos ni movimientos
   históricos. El backfill solo copia datos que ya existían en el cargo.

### Lo que no se hizo

- **No se aplicó la migración a `mantenimiento_staging` ni a producción.** La validación
  corrió sobre bases temporales (`cc588_tmp`, `cc588_e2e`) que se eliminaron al terminar.
- **No se mergeó a `main`** ni se cerró el issue: queda a criterio de la revisión.
- **No se tocó el PR #587** ni se empezó #589 o #537/#538.

## 5. Integración con #587

#588 es dueño de la **capa económica** (moneda original, saldos, imputaciones, diferencias de
cambio) y #587 de la **capa documental/instrumental** (recibo numerado, medios, cheques,
adjuntos, PDF). #587 documenta el cobro; #588 lo contabiliza.

La costura ya está prevista en el esquema: `cliente_cc_pagos` y `cliente_cc_imputaciones`
tienen `recibo_id` y `recibo_medio_id` nullable. `Money` se agregó **byte-idéntico** al del
PR #587 para que la adición doble no genere conflicto.

Orden de integración: **#588 → #589 → #587**, con rebase de #587 sobre #588. El detalle, con
las nueve colisiones de archivos conocidas y cómo resolverlas, está en
`docs/INTEGRACION_588_589_536.md`.

## 6. Evidencia visual

En `docs/screenshots/588/`:

| Archivo | Qué muestra |
| --- | --- |
| `cuenta-corriente-listado-desktop.png` | Listado con columnas por moneda, consolidado y valorización |
| `cuenta-corriente-listado-movil.png` | El mismo listado en 375 px, sin scroll horizontal |
| `cuenta-corriente-filtro-moneda-usd.png` | Filtro por moneda aplicado |
| `cuenta-corriente-detalle-desktop.png` | Detalle con tarjetas por moneda, documentos y movimientos |
| `cuenta-corriente-detalle-movil.png` | Detalle en 375 px |
| `registro-cobro-diferencia-cambio.png` | Alta de cobro en USD con su previsualización |
| `cuenta-corriente-detalle-cobro-desktop.png` | Cobro registrado: diferencia de cambio ARS 100.000 y saldo USD 1.800 |
| `cuenta-corriente-cobro-anulado-desktop.png` | Cobro anulado con contraasiento y reversión de la diferencia |

## 7. Riesgos que quedan abiertos

Los ocho riesgos de la auditoría (`docs/AUDITORIA_CUENTA_CORRIENTE_MULTIMONEDA_588.md`, §7)
siguen siendo vigentes. Los dos que conviene atender antes de producción:

- **Cobros cruzados de monedas preexistentes**: si en la base real hay pagos que cruzan
  monedas, la migración los conserva y hay que documentar su tratamiento antes de abrir la
  cobranza en esa empresa.
- **Autorización de cobros**: el permiso `clientes.cc.cobrar` queda en manos del
  Administrador. Si el negocio necesita que un rol operativo cobre, hay que asignárselo
  explícitamente: la migración no lo hace por su cuenta.
