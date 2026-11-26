# Auditoría inicial — Cuenta corriente multimoneda (#588)

**Fecha:** 26/11/2026 · **Issue:** [#588](https://github.com/oscarvogel/mantenimiento/issues/588)
**Rama de trabajo:** `feat/588-cuenta-corriente-multimoneda` (base: `main` @ `18d3f859`)
**Contexto afectado:** `CustomerAccounts` (Domain / Application / Infrastructure / Presentation)

Este documento es la auditoría obligatoria que pide el issue **antes** de tocar código.
Registra el estado real del checkout, no lo que dice la documentación.

---

## 1. Estado del repositorio y ramas

| Ítem | Estado verificado |
|---|---|
| Rama de trabajo | `main` → `feat/588-cuenta-corriente-multimoneda` (creada desde `18d3f859`) |
| Cambios locales del usuario | `scripts/comparar-produccion-drift.py` (modificado) y `docs/BITACORA_2026-10-26_AUDITORIA_SEMIS_PRODUCCION.md` (sin trackear). **Preservados, no tocados.** |
| PR #587 | `origin/feat/536-recibos-cobranzas` — **NO integrado**. Un commit: `d5dee521 feat(cobranzas): implementa recibos, imputaciones y anulación (#536)`. Merge-base con `main` = `18d3f859` (el mismo HEAD actual). |
| Other branches | `feat/clientes-cc-ars-etapa1` (etapa 1 ya fusionada), `staging/cc-unificado`, `staging/cc-y-combustible` — sin conflicto con este trabajo. |

### Diferencia `main` ↔ rama de recibos (PR #587)

74 archivos, +7.928 / −149. Lo relevante para #588:

- **Dominio nuevo:** `CustomerReceipt`, `PaymentInstrument`, `ReceiptImputation`, `ReceiptAttachmentRules`, **`Money`**.
- **Aplicación nueva:** `CreateCustomerReceipt`, `ConfirmCustomerReceipt`, `AccreditReceiptInstrument`, `ImputeCustomerPayment`, `AnnulCustomerReceipt`, `DiscardCustomerReceiptDraft`, `GenerateCustomerReceiptPdf`, `UploadReceiptAttachment`, `DownloadReceiptAttachment`, `ListCustomerReceipts`, `GetCustomerReceipt`, `GetCustomerReceiptForm`.
- **Infraestructura nueva:** `CodeIgniterCustomerReceiptReadModel` (768 líneas), `CodeIgniterCustomerReceiptRepository` (752 líneas), `FpdfCustomerReceiptPdfGenerator`, `LocalPrivateReceiptAttachmentStorage`, `FileinfoReceiptFileInspector`.
- **Migración nueva:** `2026-11-25-160000_CreateCustomerReceipts` (318 líneas) — crea `cliente_recibos`, `cliente_recibo_medios`, `cliente_cheques`, `cliente_recibo_imputaciones`, `cliente_recibo_adjuntos`, `cliente_recibos_numeracion` y **agrega `recibo_id` / `recibo_medio_id` a `cliente_cc_movimientos`**.
- **Frontend:** 3 páginas nuevas de recibos + 137 líneas agregadas a `CustomerAccountDetailPage.vue`.
- **Tests:** `tests/database/CustomerReceiptsIntegrationTest.php` (733), `tests/feature/CustomerReceiptsHttpTest.php` (70), `tests/unit/Domain/CustomerAccounts/CustomerReceiptTest.php`, `PaymentInstrumentTest.php`, `ReceiptAttachmentRulesTest.php`, `MoneyTest.php`, `FpdfCustomerReceiptPdfGeneratorTest.php`, `frontend/tests/operations/CustomerReceipts.test.js`.

---

## 2. Hallazgo central: el hueco que #588 llena

La implementación de #530 **ya es multi-moneda en el origen y mono-moneda en el libro**:

- `cliente_cc_cargos` guarda `importe_original` (DECIMAL 18,4), `moneda` (CHAR 3), `cotizacion` (DECIMAL 18,6), `cotizacion_fecha`, `cotizacion_fuente` e `importe_ars` (DECIMAL 18,4). Un cargo formalizado **ya puede ser en USD o BRL**.
- `cliente_cc_movimientos` —el libro— guarda **solo** `debe_ars` / `haber_ars`. **No tiene columna de moneda.**

Consecuencia: hoy el sistema **no puede contestar** "¿cuánto debe el cliente en USD?". Solo puede dar el saldo consolidado ARS. Peor: el saldo ARS de un cargo en USD es un número congelado, y cualquier movimiento de ese cargo se contabiliza al equivalente ARS de la fecha del cargo. **No hay ningún mecanismo de diferencias de cambio**: un cobro en USD a una cotización distinta de la del cargo produce un saldo ARS que no reconcilia contra el valor histórico del remanente.

### Lo que hace el PR #587 y por qué no alcanza

`cliente_recibo_imputaciones` del PR #587 guarda **solo `importe_ars`** (DECIMAL 18,4). El `ReceiptImputation` del dominio es `chargeId` + `amountMinor` **en ARS**. Los medios (`cliente_recibo_medios`) sí guardan `moneda`, `importe`, `cotizacion` e `importe_ars`.

Es decir: #587 sabe **en qué moneda** se cobró, pero **no sabe en qué moneda estaba la deuda** a la que se imputa, ni calcula diferencia de cambio. Imputar un recibo en BRL contra una factura en USD es imposible de conciliar. **Ese es exactamente el alcance de #588.**

---

## 3. Cambio de decisión funcional que #588 introduce

El issue #530 (etapa 1) dice textualmente:

> NO llevar sub-saldos contractuales separados por ARS/USD/BRL. El **único saldo contable de cuenta corriente es ARS**. La moneda original y sus importes se conservan para trazabilidad.

El issue #588 (decisión aprobada 26/11/2026) **reemplaza esa restricción** y pasa a exigir:

- saldo pendiente **en moneda original**, discriminado por moneda;
- equivalente **histórico** en ARS, reconciliable con el libro;
- **valorización actual** en ARS, como vista gerencial sin efectos contables;
- **diferencias de cambio** explícitas, trazables, reversibles e idempotentes.

**Regla de precedencia aplicada:** #588 es posterior y aprobado explícitamente; donde contradice a #530, manda #588. Donde #530 no se contradice (un solo ledger, sin cuentas por moneda separadas, correcciones por contraasiento, idempotencia por clave, no borrar histórico), #530 sigue vigente.

El issue #589 confirma el orden de integración: *"depende de #588 y debe integrarse antes de aprobar #536 / PR #587"*.

---

## 4. Auditoría del código actual

### 4.1 Dominio — `app/Domain/CustomerAccounts/`

| Archivo | Hallazgo |
|---|---|
| `CustomerCharge.php` | Guarda `originalAmount` como **`float`** y calcula `amountArs` con `round($amount * $rate, 2)` (línea 64). Invariante: el Debe ARS es definitivo y no se recalcula. `activeKey()` = `TRAMO:{legId}` o `SALDO_INICIAL:{customerId}`. |
| `FrozenExchangeRate.php` | Cotización congelada con fecha y fuente (`CATALOGO_EMPRESA` / `MANUAL` / `PESOS`). Fuerza `rate = 1.0` y `source = PESOS` para ARS. **`toArs()` usa `float` (línea 78).** |
| `BillableLeg.php` | Contrato de lectura hacia Viajes: `estimatedAmount` / `estimatedCurrency` / `estimatedRate` / `estimatedArs` (la estimación **no** fija la deuda). |
| `ChargeDocument.php` | Modalidad + fecha + número + tipo de factura. |
| `NonCollectibleClosure.php` | Cierre comercial sin deuda, con motivo y reapertura. |

**Ausencias:** no existe representación de moneda en el libro, ni de cobro, ni de imputación, ni de diferencia de cambio.

### 4.2 Aplicación — `app/Application/CustomerAccounts/`

| Caso de uso | Estado | Nota |
|---|---|---|
| `FormalizeLegCharge` | ✅ | Nace el cargo. Usa `RateResolver` (catálogo de empresa o tasa informada) y transacción con `FOR UPDATE` sobre el tramo. |
| `RegisterOpeningBalance` | ✅ | Saldo inicial a cobrar o a favor. |
| `AnnulCustomerCharge` | ✅ | Anulación por contraasiento; libera `clave_vigente`. |
| `MarkLegNonCollectible` / `ReopenNonCollectibleLeg` | ✅ | No cobrable con motivo. |
| `GetCommercialClosingQueue` | ✅ | Cola de cierre comercial. |
| `ListCustomerAccounts` / `GetCustomerStatement` | ⚠️ | **Solo ARS.** `customerBalances` no pagina; `customerStatement` no pagina y no trae desglose por moneda. |
| `RateResolver` | ✅ | Resuelve la cotización congelada. |
| `CustomerAccountAccess` | ✅ | `VIEW = clientes.cc.ver`, `FORMALIZE = clientes.cc.formalizar`. El superadministrador global **no** opera acá. La CC **no** se filtra por sucursal (agregado de empresa). |

### 4.3 Infraestructura

- `CodeIgniterCustomerAccountRepository` — solo cargos/no cobrables. Inserta el libro con `debe_ars`/`haber_ars` y `clave = 'CARGO:{id}'` / `'ANULACION:{id}'` (idempotencia por `UNIQUE(empresa_id, clave)`).
- `CodeIgniterCustomerAccountReadModel` — `customerBalances` hace `SUM(debe_ars) - SUM(haber_ars)` **sobre todo** el libro, sin `LIMIT`; `customerStatement` acumula en PHP con `round()`.
- `CodeIgniterExchangeRateCatalog` — lee `viajes_monedas` (activos) y `viajes_cotizaciones` (`UNIQUE(empresa_id, moneda)`). **`currentRate()` devuelve `?float` y no expone la fecha de la cotización** (`viajes_cotizaciones` solo tiene `updated_at`).
- `CodeIgniterCustomerAccountsUnitOfWork` — transacción de CI4.

### 4.4 Persistencia — `app/Database/Migrations/2026-11-18-090000_CreateCustomerAccounts.php`

- `cliente_cc_cargos`: `importe_original DECIMAL(18,4)`, `moneda CHAR(3)`, `cotizacion DECIMAL(18,6)`, `importe_ars DECIMAL(18,4)`, `sentido VARCHAR(5)` (`DEBE`/`HABER`), `clave_vigente` con `UNIQUE(empresa_id, clave_vigente)`.
- `cliente_cc_movimientos`: `debe_ars DECIMAL(18,4)`, `haber_ars DECIMAL(18,4)`, `tipo VARCHAR(30)`, `cargo_id`, `clave VARCHAR(100)` con `UNIQUE(empresa_id, clave)`.
- `cliente_cc_tramos_no_cobrables`.
- `down()` **se niega** si hay movimientos. Permisos: `clientes.cc.ver` (Administrador + roles con `viajes.economico.ver`) y `clientes.cc.formalizar` (Administrador).

### 4.5 Presentación

- Rutas (`app/Config/Routes.php:82-90`): 8 rutas, todas con `['auth', 'permission:clientes.cc.*']`.
- `app/Controllers/CustomerAccounts.php`: valida whitelist del `volver`, `importe > 0`, `moneda ^[A-Z]{3}$`, `cotizacion > 0`. **Devuelve HTML** (shell Vue), no JSON.
- Frontend: `frontend/src/pages/operations/CustomerAccountsPage.vue` (listado) y `CustomerAccountDetailPage.vue` (detalle). Renderizado **servidor** vía `renderApp()` + payload JSON. Tailwind con tokens propios (`text-ink`, `bg-surface-raised`, `border-border`, `shadow-card`), **no Bootstrap**.
- El formateo de dinero es un helper local **duplicado en 5 archivos** (`CustomerAccountsPage.vue:19`, `CustomerAccountDetailPage.vue:23`, `TripCommercialClosingPage.vue:41`, `TripDriverSettlementPage.vue:26`, `TripOperationsDetailPage.vue:611`, `TripFuelPendingPage.vue:60`). `helpers.js` no tiene formateador de importes.
- El módulo **no está en el catálogo de `/inicio`** (`GetPlatformModuleCatalog::FUTURE_MODULES`): es un ítem de navegación interna de Viajes, en `app/Presentation/AppShellPayload.php:52-54`.
- Paginación: el patrón del módulo Viajes (#532) usa query `pagina` / `por_pagina` y payload `pagination: {page, perPage, perPageOptions, total, pages, from, to}`. Existe además un componente `PaginationBar.vue` con otro contrato (`totalPages`).
- **No hay paginación hoy en la CC**: `customerBalances` y `customerStatement` devuelven todo.

### 4.6 Precisión monetaria

- Todo el dominio #530 usa **`float`**. `Money::toMinor()` del PR #587 es la única aritmética monetaria determinista del repo (centavos enteros + cotizaciones en millonésimas enteras, redondeo half-up) y **todavía no está en `main`**.
- La regla de AGENTS.md es explícita: *"Importes usan `DECIMAL`, nunca `FLOAT`/`DOUBLE`"* y el issue #588 prohíbe `float` para cálculos monetarios.

---

## 5. Auditoría de la aritmética de diferencias de cambio

Regla derivada y verificada con el ejemplo obligatorio del issue:

```
diferencia_de_cambio = importe_imputado_original × (cotización_del_pago − cotización_del_cargo)
```

- Positiva → se asienta en **Debe** (el crédito en ARS superó el valor histórico de lo cancelado).
- Negativa → se asienta en **Haber**.
- Con esa regla, cuando el saldo original de una moneda llega a cero, el ledger ARS de esa moneda **también** llega a cero.

Comprobación con el ejemplo del issue (USD 2.800 @ 1.500):

| Momento | Movimiento | Debe ARS | Haber ARS | Saldo ARS |
|---|---|---:|---:|---:|
| Factura USD 2.800 @ 1.500 | `CARGO` | 4.200.000 | — | 4.200.000 |
| Pago USD 1.000 @ 1.600 | `COBRANZA` | — | 1.600.000 | 2.600.000 |
| Dif. de cambio 1.000 × (1.600−1.500) | `DIFERENCIA_CAMBIO` | 100.000 | — | **2.700.000** |

Saldo pendiente **USD 1.800**; valor histórico 1.800 × 1.500 = **2.700.000** ✓; valor actual 1.800 × 1.600 = **2.880.000** ✓; diferencia realizada **ARS 100.000** ✓.

Pago restante USD 1.800 @ 1.600 → Haber 2.880.000 + diferencia 1.800 × (1.600−1.500) = 180.000 Debe → saldo ARS **0** y saldo USD **0**. Sin saldos residuales ficticios.

---

## 6. Estrategia de integración con el PR #587

**Decisión:** #588 es dueño de la **capa económica** (moneda original, saldos, imputaciones, diferencias de cambio). #587 es dueño de la **capa documental e instrumental** (recibo numerado, medios, cheques, adjuntos, PDF,.Portfolio). No se fusiona #587 a `main` y no se reescribe su trabajo.

El costo real está en el **cruce**: #587 modela el cobro como un `recibo` con N `medios`, y un medio es "moneda + importe + cotización + equivalente ARS". El pago unimoneda de #588 tiene la misma forma. Por eso #588 no crea un segundo modelo de cobro, sino **una proyección del modelo de #587 sobre el libro**:

- `cliente_cc_pagos` — 1 fila por **medio acreditado** (o por anticipo directo). Es el hecho económico: moneda, importe, cotización congelada, equivalente ARS, fecha, estado. Sin numeración, sin adjuntos, sin datos de cheque, sin PDF.
- `cliente_cc_imputaciones` — 1 fila por (pago, cargo): **importe en la moneda de la deuda**, valor histórico a la cotización del cargo, valor al pago, y la diferencia de cambio.
- `cliente_cc_movimientos` — el libro, ampliado con `moneda`, `debe_original`, `haber_original`, `cotizacion`, `cotizacion_fecha`, `cotizacion_fuente`, `pago_id`, `imputacion_id`.

**Costura de integración (a ejecutar cuando #589 esté cerrado y #587 se apruebe):**

| Origen #587 | Destino #588 | Regla |
|---|---|---|
| `cliente_recibo_medio` acreditado | `cliente_cc_pagos` (1:1) | El medio acreditado **es** el pago. Se copia moneda, importe, cotización, equivalente ARS. |
| `cliente_recibo_imputacion` | `cliente_cc_imputaciones` (1:1) | `importe_ars` pasa a ser **derivado**; se agrega `importe_original` (moneda de la deuda). |
| Recibo en BORRADOR / medio no acreditado | `cliente_cc_pagos` con `estado = 'PENDIENTE'` | El haber **no** nace hasta la acreditación. Conserva la política de #587 (`acredita_al_confirmar`). |
| `cliente_recibo_imputacion` en ARS contra deuda en USD | **prohibido** | Es la compensación cruzada que #588 veta. |
| Anulación de recibo | `cliente_cc_pagos.estado = 'ANULADO'` + contraasiento + reversión de diferencias | El contraasiento de #587 debe delegar en el caso de uso de #588. |

Para que la costura sea barata, `cliente_cc_pagos` y `cliente_cc_imputaciones` ya se crean **con las columnas `recibo_id` y `recibo_medio_id`** (nullable, sin FK, porque `cliente_recibos` no existe todavía en `main`). Es el mismo patrón que el propio PR #587 usa al agregar `recibo_id` / `recibo_medio_id` a `cliente_cc_movimientos`.

**Colisión de archivos a resolver en esa integración (no ahora):**
`Money` —#587 lo agrega en `app/Domain/CustomerAccounts/Money.php`; #588 lo agrega **byte-idéntico** en la misma ruta. Git resuelve la adición doble con contenido idéntico sin conflicto; si #587 lo modifica antes de integrarse, hay que elegir una versión. Lo mismo con `GetCustomerStatement.php`, `CodeIgniterCustomerAccountReadModel.php`, `CodeIgniterCustomerAccountRepository.php`, `app/Config/Services.php`, `app/Config/Routes.php`, `app/Controllers/CustomerAccounts.php` y `CustomerAccountDetailPage.vue`: **#588 los modifica y #587 también**. Por eso la integración debe ser por *rebase de #587 sobre #588*, no al revés.

**Sobre `Money`:** en este trabajo se agrega **byte-idéntico** al del PR #587, sin modificarlo. Toda la aritmética nueva (#588) lo usa; la clase #587 ya existía con ese nombre y esa semántica. Si #587 lo cambia, se reconcilia en la integración, no antes.

---

## 7. Datos históricos: qué se puede migrar y qué no

Inventario de lo que existe hoy:

| Dato | Situación | Acción en la migración |
|---|---|---|
| `cliente_cc_cargos.moneda` | Ya poblado (el cargo se formaliza con la moneda elegida; ARS/USD/BRL del maestro `viajes_monedas`). | Se conserva. No se inventa ni se corrige. |
| `cliente_cc_cargos.importe_original` / `cotizacion` / `importe_ars` | Ya poblados. | Se conservan. |
| `cliente_cc_movimientos` sin `moneda` | **Backfill determinístico**: `moneda` = la del cargo referenciado (`cargo_id`); los movimientos sin cargo (no hay hoy, pero por diseño puede haberlos) → `'ARS'`. | Backfill en la migración, con su bitácora. |
| `cliente_cc_movimientos` sin `debe_original` / `haber_original` | **Backfill determinístico**: para `CARGO`/`SALDO_INICIAL`, el importe original del cargo según `sentido`; para `ANULACION`, el inverso. | Backfill. |
| `cliente_cc_movimientos` sin `cotizacion` | **Backfill determinístico**: la del cargo. `cotizacion_fuente` = `CATALOGO_EMPRESA` si la del cargo lo era, si no `MANUAL`; la fuente real ya está en el cargo y se copia literal. | Backfill. |
| Movimientos con `cargo_id` nulo | Hoy **no existen**: los tres tipos de movimiento (`CARGO`, `SALDO_INICIAL`, `ANULACION`) siempre referencian un cargo. | Se documenta la regla y se deja `NULL` + `moneda = 'ARS'`. |
| Cargos con `moneda` no presente en `viajes_monedas` | Posible si la moneda se desactivó después. **No se corrige**: la moneda original de la deuda se conserva aunque la moneda deje de estar activa. | Se preserva. |
| Saldos ambiguos | La etapa #530 no tiene saldos a favor por moneda (solo un `HABER` de saldo inicial). El `sentido` del cargo define el signo. No hay ambigüedad de signo. | No hace falta reconstruir nada. |
| Clientes con deuda en moneda sin cotización vigente | **No afecta** al histórico: la cotización histórica está congelada en el cargo. Solo afecta la **valorización actual**, que se informa como *no disponible*. | Sin acción. |

**Regla dura aplicada:** la migración **solo agrega columnas, crea tablas y copia valores deterministas** de columnas que ya existen. No recalcula importes, no inventa monedas, no genera deuda retroactiva desde viajes, no borra ni modifica filas históricas. El `down()` **se niega** si hay pagos o movimientos nuevos, igual que la migración de #530.

---

## 8. Cotizaciones: qué hay y qué falta

- Maestro de monedas: `viajes_monedas` (`codigo` único, `activo`).
- Cotización vigente por empresa: `viajes_cotizaciones` (`UNIQUE(empresa_id, moneda)`, `cotizacion DECIMAL(18,6)`, `updated_at`). **Una sola cotización vigente por moneda y empresa**; no hay serie histórica de cotizaciones.
- Consecuencia: la **cotización histórica de cada operación** es la que se congela en el cargo, en el pago o en la imputación (con su `cotizacion_fecha` y `cotizacion_fuente`). La **valorización actual** usa la vigente del catálogo, y su fecha es `updated_at`.
- **No existe historial de cotizaciones por día.** #588 no lo necesita: no recalcula nada histórico. La fuente de la valorización actual se expone como `CATALOGO_EMPRESA` + `updated_at`, y si no hay fila para la moneda, la valorización se informa **no disponible** para esa moneda, sin usar cero ni inventar una tasa.

---

## 9. Permisos

Se agrega `clientes.cc.cobrar` (Administrador) para registrar, imputar y anular cobranzas, siguiendo la convención de `clientes.cc.formalizar` (que cubre tanto formalizar como anular cargos). `clientes.cc.ver` se mantiene para lectura y se extiende a la lectura multimoneda.

La cuenta corriente **no** se filtra por sucursal: es un agregado de empresa. El filtro por sucursal solo aplica a la cola de cierre comercial.

---

## 10. Preparación para #589

#589 necesita distinguir **deuda prevista** de **deuda formalizada** con estado de cobranza independiente. Para no cerrarle la puerta, #588:

- **no** agrega el disparador de creación de viajes (fuera de alcance explícito);
- **no** toca la liquidación del chofer;
- mantiene en `cliente_cc_cargos` la separación entre `modalidad` (evento que formaliza) y `sentido`, de modo que un cargo previsto pueda luego formalizarse sin duplicar;
- deja el `importe_estimado` / `moneda_estimada` / `cotizacion_estimada` / `importe_estimado_ars` que ya existen en `cliente_cc_cargos` intactos: son la semilla natural del importe previsto;
- el estado de cobranza por documento queda derivable (`pendiente` / `parcial` / `cancelado`) a partir de las imputaciones, sin campos mutables nuevos.

Cuando #589 entre, la deuda prevista será un `cliente_cc_cargos` con `modalidad = 'PREVISTA'` y `estado` propio, formalizable sobre el mismo cargo.

---

## 11. Riesgos registrados

| # | Riesgo | Mitigación |
|---|---|---|
| R1 | Colisión de archivos con #587 en 8 rutas comunes. | Integrar #587 **por rebase sobre #588**, no al revés. Documentado en `docs/INTEGRACION_588_589_536.md`. |
| R2 | `Money` byte-idéntico depende de que #587 no lo cambie. | Si #587 lo modifica, la reconciliación se hace en la integración, no en esta rama. |
| R3 | `viajes_cotizaciones` no tiene fecha de vigencia propia. | La fecha de la valorización actual es `updated_at` y se expone explícitamente. |
| R4 | El dominio #530 usa `float`; el nuevo usa enteros. | La frontera queda en la conversión: `Money::toMinor()` al leer DECIMAL y `Money::format()` al escribir. La aritmética nueva nunca toca `float`. |
| R5 | No existe arnés de concurrencia real en el repo. | Se construye en este trabajo (dos conexiones sobre el mismo MariaDB) siguiendo el gate `CUSTOMER_ACCOUNTS_MYSQL`. |
| R6 | No existe helper de sesión autenticada para feature tests. | Los tests HTTP se limitan a redirect de anónimo + contrato de rutas por permiso, igual que el resto del proyecto. El aislamiento multiempresa se prueba en la capa de casos de uso y de datos. |
| R7 | La CC no tiene paginación y el listado carga toda la tabla de movimientos. | #588 agrega paginación real al listado (`pagina` / `por_pagina`), con el patrón de Viajes (#532). |
| R8 | Monedas sin cotización vigente. | La valorización se informa *no disponible* por moneda; nunca un total parcial presentado como completo. |

---

## 12. Conclusión

El sistema tiene la **mitad correcta** de #588: el cargo ya guarda moneda original y cotización congelada. Le falta la otra mitad: **el libro no es multi-moneda, no existe el cobro, no existe la imputación en moneda de deuda y no existe el mecanismo de diferencias de cambio**.

El hueco es de **modelo económico**, no de infraestructura: no hay que reescribir #530, hay que **extender el libro a multi-moneda y agregar la capa de cobranza e imputación**, conservando el ledger único, la idempotencia por clave y el mecanismo de contraasiento ya establecidos.

El trabajo de #588 no solapa con el PR #587: #587 documenta el cobro, #588 lo contabiliza.
