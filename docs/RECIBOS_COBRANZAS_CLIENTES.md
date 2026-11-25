# Recibos y cobranzas de clientes — etapa 2 (#536)

**Fecha:** 25/11/2026 · **Contexto:** `CustomerAccounts` (Domain / Application / Infrastructure).
**Depende de:** #530 (`docs/CUENTA_CORRIENTE_CLIENTES.md`).
**Prepara:** #537 (cartera de cheques) y #538 (cajas, bancos y conciliación).

---

## 1. Regla central

`cliente_cc_movimientos` sigue siendo **la única fuente de verdad del saldo** del cliente,
expresado en ARS. Los recibos **no crean un segundo ledger**: agregan asientos de cobro y
sus contraasientos al libro existente, con clave única por empresa para que un reintento no
duplique nada.

Un recibo tiene tres estados:

| Estado | Qué significa |
|---|---|
| `BORRADOR` | Se puede editar/descartar. No tiene número ni efecto contable. |
| `CONFIRMADO` | Consumió su número correlativo. Acreditó en el libro los medios que correspondían. |
| `ANULADO` | Tiene contraasientos por lo efectivamente contabilizado y sus imputaciones quedaron revertidas. Se conserva completo. |

El historial de operaciones queda en la auditoría transversal (`cliente_recibo.*`).

---

## 2. Política de acreditación de medios (decisión funcional)

Recepción, confirmación, acreditación y disponibilidad **no son lo mismo**:

| Medio | Acredita al confirmar | Motivo |
|---|---|---|
| Efectivo | **Sí** | El dinero se recibió físicamente. |
| Transferencia | Solo si el operador la marca **verificada** (o conciliada). | Una transferencia informada no prueba que el dinero esté disponible. |
| Cheque físico / eCheq | **No.** Queda `PENDIENTE` hasta una acreditación explícita. | Un cheque diferido no es dinero disponible; la cartera es del #537. |

Consecuencias:

1. El total del recibo se expresa en ARS, pero solo impacta el Haber lo que se acredita.
2. Un recibo con un cheque pendiente **no reduce el saldo** por ese cheque.
3. La acreditación posterior usa la clave `RECIBO_MEDIO:{id}`: es idempotente y no puede
   generar un segundo Haber.
4. Los medios pendientes se listan en la cuenta corriente y en el detalle del recibo como
   **medios pendientes de acreditación**.
5. Si el cheque ya fue depositado, endosado, acreditado o rechazado, o la transferencia ya
   fue conciliada, **no se permite anular el recibo**: primero se resuelve en tesorería.

### Alternativas evaluadas

| Alternativa | Problema |
|---|---|
| Acreditar todo al confirmar y compensar después un cheque rechazado | Muestra como cobrado dinero que no existe y obliga a contraasientos que el usuario no pidió. |
| No confirmar el recibo hasta que todos los medios estén acreditados | Impide registrar la recepción del cheque y separa la constancia del hecho económico. |
| **Acreditar por medio (implementada)** | Es la única que distingue recepción de disponibilidad y evita el doble Haber. |

**Pendiente de aprobación explícita del cliente:** si se prefiere que las transferencias
informadas acrediten al confirmar por defecto. El sistema ya lo permite marcándolas
`VERIFICADA`; cambiar el valor por defecto es una decisión de negocio de una línea, no de
arquitectura. Hasta que se apruebe, el valor seguro es `PENDIENTE`.

---

## 3. Modelo de datos (aditivo)

Migración `2026-11-25-160000_CreateCustomerReceipts`.

| Tabla | Rol |
|---|---|
| `cliente_recibos` | Cabecera: cliente, sucursal, serie, número, fechas, estado, totales ARS, responsable, confirmación y anulación. |
| `cliente_recibo_medios` | Una línea por medio: moneda, importe original, cotización congelada, `importe_ars`, `acredita_al_confirmar`, `estado_acreditacion`, datos de transferencia y referencia. |
| `cliente_cheques` | Identidad propia del cheque/eCheq (tipo, banco, número, librador, CUIT, fechas, estado de cartera). **Es la superficie que administrará #537** sin migrar datos. |
| `cliente_recibo_imputaciones` | Asignación del crédito a cargos, en ARS. No genera asientos. Se anula, no se borra. |
| `cliente_recibo_adjuntos` | Comprobantes privados (PDF/JPG/PNG/WEBP, 10 MB), ligados al recibo y opcionalmente a un medio. |
| `cliente_recibos_numeracion` | Contador por empresa + sucursal + serie, bloqueado con `FOR UPDATE`. |
| `cliente_cc_movimientos` | Se le agregan `recibo_id` y `recibo_medio_id`. No se modifica ningún asiento histórico. |

Claves de idempotencia del libro:

- `RECIBO_MEDIO:{medioId}` — Haber del cobro.
- `ANULACION_RECIBO_MEDIO:{medioId}` — Debe del contraasiento.

No hay borrado físico de recibos confirmados. `down()` se niega si existe al menos un recibo.

---

## 4. Reglas de imputación

- Se imputa en ARS contra cargos `VIGENTE` y `DEBE` del **mismo cliente y empresa**.
- La suma imputada de un recibo no puede superar lo **acreditado** de ese recibo.
- Un cargo no puede recibir más que su saldo pendiente; los cargos se bloquean dentro de la
  transacción, así que dos recibos concurrentes no pueden sobreimputar.
- Un recibo puede cancelar varios cargos.
- Varios recibos pueden cancelar un mismo cargo (pago parcial).
- Se puede registrar un recibo **sin imputaciones**: el crédito queda como anticipo.
- Imputar un anticipo existente **no crea un nuevo Haber**; solo asigna crédito disponible.
- El excedente queda a favor del cliente (saldo negativo en el libro).
- La anulación de un cargo con imputaciones vigentes se **bloquea** para no dejar
  imputaciones huérfanas. Primero se anulan los recibos que lo cancelan.

Estados de cobranza por documento, independientes del estado comercial:

- `PENDIENTE` — sin imputaciones.
- `PARCIAL` — imputado mayor que cero y menor que el total.
- `COBRADO` — pendiente cero.

---

## 5. Anulación

- Permiso `clientes.recibos.anular` (no lo tiene Gerencial por defecto).
- Motivo obligatorio (5 a 500 caracteres) y confirmación explícita en la UI.
- Solo un recibo `CONFIRMADO`; un borrador se descarta.
- Contraasiento por cada medio **efectivamente acreditado**, con la fecha de anulación.
- Se anulan las imputaciones (estado `ANULADA`) y el cargo recupera su saldo.
- Medios pendientes se anulan sin asiento porque nunca impactaron el saldo.
- Doble anulación rechazada por estado.
- Cheques no disponibles para anular si ya salieron de `EN_CARTERA`; transferencias
  conciliadas (`conciliado_at`) también bloquean.

---

## 6. Precisión monetaria

`App\Domain\CustomerAccounts\Money` trabaja en **centavos enteros** y cotizaciones en
millonésimas enteras. La conversión a ARS usa redondeo half-up entero. Los `float` solo se
usan en el borde de persistencia (`DECIMAL(18,4)` / `DECIMAL(18,6)`). Los tests cubren
`0,10 + 0,20`, redondeos de media unidad y multomoneda.

---

## 7. Permisos

| Permiso | Administrador | Gerencial | Consulta |
|---|---|---|---|
| `clientes.recibos.ver` | Sí | Sí | No |
| `clientes.recibos.registrar` | Sí | No | No |
| `clientes.recibos.confirmar` | Sí | No | No |
| `clientes.recibos.anular` | Sí | No | No |
| `clientes.recibos.imputar` | Sí | No | No |
| `clientes.recibos.imprimir` | Sí | Sí | No |

Se validan en el backend en cada caso de uso y también en las rutas (`permission:`). La UI
oculta acciones, pero no es la autoridad.

---

## 8. Rutas

| Método | Ruta | Permiso |
|---|---|---|
| GET | `clientes/recibos` | `clientes.recibos.ver` |
| GET | `clientes/recibos/nuevo/{cliente}` | `clientes.recibos.registrar` |
| POST | `clientes/recibos` | `clientes.recibos.registrar` |
| GET | `clientes/recibos/{id}` | `clientes.recibos.ver` |
| POST | `clientes/recibos/{id}/confirmar` | `clientes.recibos.confirmar` |
| POST | `clientes/recibos/{id}/anular` | `clientes.recibos.anular` |
| POST | `clientes/recibos/{id}/descartar` | `clientes.recibos.registrar` |
| POST | `clientes/recibos/{id}/medios/{medio}/acreditar` | `clientes.recibos.confirmar` |
| POST | `clientes/recibos/{id}/imputar` | `clientes.recibos.imputar` |
| GET | `clientes/recibos/{id}/imprimir` | `clientes.recibos.imprimir` |
| POST | `clientes/recibos/{id}/adjuntos` | `clientes.recibos.registrar` |
| GET | `clientes/recibos/{id}/adjuntos/{adjunto}` | `clientes.recibos.ver` |

---

## 9. Pantallas

- `clientes/cuenta-corriente/{cliente}`: saldo deudor/a favor, total cobrado, anticipos
  disponibles, medios pendientes, documentos con estado de cobranza, recibos recientes y
  acciones (nuevo recibo, ver recibos, imputar anticipos, imprimir, anular).
- `clientes/recibos/nuevo/{cliente}`: formulario con líneas dinámicas de medios, cotización
  precargada, vista previa en tiempo real e imputación por documento.
- `clientes/recibos`: listado paginado con búsqueda y filtros.
- `clientes/recibos/{id}`: detalle, acreditación de medios, imputación, adjuntos, impresión y
  anulación.

El comprobante PDF es interno: **no declara ser un comprobante fiscal autorizado por ARCA**.
Se imprime con estado `BORRADOR` o `ANULADO` bien visible. El maestro de empresas no tiene
archivo de logo, por lo que se usa una marca tipográfica con las iniciales, el nombre y el
CUIT; incorporar el logo real queda como pendiente de datos del cliente.

---

## 10. Integración con #537 y #538

- **#537 (cartera de cheques):** `cliente_cheques` ya contiene la identidad y el estado
  (`EN_CARTERA`, `DEPOSITADO`, `ENDOSADO`, `ACREDITADO`, `RECHAZADO`, `ANULADO`). El #537
  debe agregar sus movimientos y usar `recibo_medio_id` para no duplicar el cheque. Al
  acreditar un cheque debe reutilizar `AccreditReceiptInstrument` (o su puerto) para el
  Haber idempotente. Al rechazarlo, debe registrar el Debe y revertir imputaciones; el
  recibo no podrá anularse una vez que el cheque salió de `EN_CARTERA`.
- **#538 (cajas, bancos y conciliación):** `cliente_recibo_medios` ya reserva
  `conciliado_at`/`conciliado_por` para transferencias y `cuenta_destino`. La conciliación
  debe marcar esos campos y, si corresponde, acreditar el medio por el mismo caso de uso.
  Una transferencia conciliada bloquea la anulación.

---

## 11. Despliegue y rollback

1. Ejecutar `php spark migrate` en staging `fasa_189` (Docker/Coolify) antes de publicar el
   código. La migración es aditiva.
2. Verificar que los seis permisos nuevos existan y que Administrador/Gerencial los tengan.
3. Publicar PHP + `assets/dashboard` reconstruido.
4. Rollback: `php spark migrate:rollback` solo si no hay recibos. Con recibos, `down()` se
   niega a propósito: el rollback de código debe mantener las tablas y columnas nuevas.

**Producción Ferozo:** no se ejecutó ninguna migración en producción. El procedimiento es el
habitual de `docs/DEPLOY_MIGRACIONES_FEROZO.md`, con backup previo y sin CLI.

---

## 12. Riesgos y pendientes

| Riesgo / pendiente | Estado |
|---|---|
| Aprobación explícita de la política por defecto de transferencias | **Pendiente del cliente.** El valor seguro implementado es `PENDIENTE`. |
| Logo real de la empresa en el PDF | **Pendiente de datos.** No existe archivo de logo en el esquema; se usa marca tipográfica. |
| Adjuntos en el alta inicial | Se adjuntan desde el detalle (con asociación opcional al medio). No se suben en el mismo POST de creación para no mezclar archivos con la transacción contable. |
| Rechazo de cheque y conciliación | Fuera del #536; columnas y bloqueos preparados para #537/#538. |
| Numeración con huecos por anulación | El número se consume al confirmar y no se reutiliza: un hueco es preferible a duplicar un comprobante. |
| Concurrencia real | Probada por diseño con bloqueo de cargos y contador; la suite SQLite no ejecuta dos conexiones simultáneas. Validar en MySQL de staging con dos sesiones. |

---

## 13. Pruebas

- `tests/unit/Domain/CustomerAccounts/MoneyTest.php`
- `tests/unit/Domain/CustomerAccounts/PaymentInstrumentTest.php`
- `tests/unit/Domain/CustomerAccounts/CustomerReceiptTest.php`
- `tests/unit/Infrastructure/CustomerAccounts/FpdfCustomerReceiptPdfGeneratorTest.php`
- `tests/database/CustomerReceiptsIntegrationTest.php` (20 pruebas: medios, monedas,
  cotizaciones congeladas, pagos parciales/totales, varios cargos, varios recibos, anticipos,
  excedentes, anulación, doble anulación, doble contabilización, sobreimputación, numeración,
  aislamiento, permisos, cargos con imputaciones, precisión, medios pendientes, filtros,
  paginación y regresión de #530). Corre en SQLite y, con
  `CUSTOMER_ACCOUNTS_MYSQL`/`CUSTOMER_ACCOUNTS_MYSQL_PORT`, contra MariaDB/MySQL real; se
  validó en MariaDB 11.8 con InnoDB y foreign keys (20/20).
- `tests/feature/CustomerReceiptsHttpTest.php`
- `frontend/tests/operations/CustomerReceipts.test.js`
- Regresión completa: `php vendor/bin/phpunit --no-coverage` (2424 pruebas) y `npm test`
  (621 pruebas).
