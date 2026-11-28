# Integración de #588, #589 y #536 (PR #587)

**Fecha:** 26/11/2026 · **Alcance:** cómo se encadenan la cuenta corriente multimoneda
(#588), la deuda comercial prevista (#589) y los recibos y cobranzas (#536, PR #587).

Este documento es la "estrategia de integración" que pide el issue #588
(§5 y §13): la más segura para que las tres etapas convivan sin duplicar
funcionalidad ni desincronizar el libro.

---

## 1. Orden de integración

El issue #589 lo dice explícitamente: *"depende de #588 y debe integrarse antes de
aprobar #536 / PR #587"*.

```text
main
  └─ #588  (este trabajo)   ← base de todo lo demás
       └─ #589  deuda prevista desde viajes
            └─ #536 / PR #587  recibos, medios y cheques sobre la capa de #588
```

**Regla operativa:** el PR #587 se integra **por rebase sobre #588**, nunca al
revés. Rebasar #587 sobre #588 conserva el trabajo del PR y hace que la
adaptación sea explícita; al revés, #588 tendría que去打 el modelo de receipts
ya instalado.

---

## 2. Quién es dueño de qué

| Capa | Dueño | Tablas | Qué NO hace |
|---|---|---|---|
| Documento de deuda | #530 | `cliente_cc_cargos` | No sabe de cobros |
| Libro (ledger) | #588 | `cliente_cc_movimientos` | No sabe de documentos ni de recibos |
| Cobro (hecho económico) | #588 | `cliente_cc_pagos` | No lleva numeración, adjuntos ni datos de cheque |
| Imputación | #588 | `cliente_cc_imputaciones` | No genera asientos: el haber nace al acreditarse el cobro |
| Deuda prevista | #589 | `cliente_cc_cargos` (modalidad `PREVISTA`) | No se imputa ni se cobra |
| Recibo (documento) | #536 | `cliente_recibos` | No recalcula nada del libro |
| Medio de pago | #536 | `cliente_recibo_medios`, `cliente_cheques` | No decide el valor histórico |

**Regla:** el libro es de #588. #536 lo usa; no lo redefine.

---

## 3. La costura

`cliente_cc_pagos` y `cliente_cc_imputaciones` ya se crean con las columnas
`recibo_id` y `recibo_medio_id` (nullable, sin FK, porque `cliente_recibos` todavía
no existe en `main`). Es el mismo patrón que el propio PR #587 usa al agregar
`recibo_id` y `recibo_medio_id` a `cliente_cc_movimientos`.

### 3.1 Traducción de #587 a #588

| Origen en #587 | Destino en #588 | Regla |
|---|---|---|
| `cliente_recibo_medio` acreditado | `cliente_cc_pagos` (1:1) | El medio acreditado **es** el pago. Se copian moneda, importe, cotización y equivalente ARS. |
| `cliente_recibo_medio` sin acreditar | `cliente_cc_pagos` con `estado = 'PENDIENTE'` | El haber **no** nace hasta la acreditación. Se conserva la política de #587 (`acredita_al_confirmar`). |
| `cliente_recibo_imputacion` | `cliente_cc_imputaciones` (1:1) | `importe_ars` pasa a ser **derivado**; se agrega `importe_original`, que es el importe en la **moneda de la deuda**. |
| Imputación en ARS contra deuda en USD | **prohibida** | Es la compensación cruzada que #588 veta. El caso de uso de #587 tiene que pasarse a `RegisterCustomerPayment`. |
| Anulación de un recibo | `cliente_cc_pagos.estado = 'ANULADO'` + contraasiento | El contraasiento de #587 tiene que delegar en `AnnulCustomerPayment`, que ya revierte las diferencias de cambio. |

### 3.2 Qué cambia en el PR #587 cuando se integre

1. `ConfirmCustomerReceipt` y `AccreditReceiptInstrument` dejan de escribir
   directo en `cliente_cc_movimientos` y llaman a `RegisterCustomerPayment` /
   `CreditCustomerPayment`.
2. `ImputeCustomerPayment` deja de calcular el importe en ARS y calcula el
   importe **en la moneda de la deuda**, dejando el reparto histórico y la
   diferencia de cambio a cargo de #588.
3. `ReceiptImputation` (dominio de #587) pasa a ser un DTO de entrada: la
   entidad con las reglas es `PaymentImputation` de #588.
4. `AnnulCustomerReceipt` llama a `AnnulCustomerPayment`.

### 3.3 Lo que NO hay que hacer

- No crear una segunda cuenta corriente por moneda.
- No mantener el saldo en ARS y el saldo en USD como dos verdades: el saldo en
  ARS es la diferencia entre Debe y Haber del libro.
- No recalcular ningún cargo ni cobro histórico cuando cambia una cotización.
- No compensar automáticamente una deuda en USD con un cobro en ARS.

---

## 4. Colisiones de archivos conocidas

Al rebasar #587 sobre #588, estos archivos cambian en las dos ramas:

| Archivo | Conflicto esperado | Cómo resolverlo |
|---|---|---|
| `app/Domain/CustomerAccounts/Money.php` | #588 lo agrega **byte-idéntico** a #587 | Sin conflicto: adición doble con contenido idéntico. Si #587 lo modificó, se elige la versión de #587 y se corre la suite. |
| `app/Application/CustomerAccounts/GetCustomerStatement.php` | #587 agrega el resumen de cobranzas; #588 agrega saldos por moneda y valoración | Fusionar los dos payloads: `statement + collection + balances + valuation`. |
| `app/Infrastructure/CustomerAccounts/CodeIgniterCustomerAccountReadModel.php` | #587 agrega las columnas del recibo; #588 agrega la moneda y los saldos por moneda | Fusionar el `select` y el `match` de `tipo_label`. |
| `app/Infrastructure/CustomerAccounts/CodeIgniterCustomerAccountRepository.php` | #588 escribe las columnas multi-moneda del libro | fusionar los dos `insert`. |
| `app/Config/Services.php` | ambos agregan servicios | fusionar. |
| `app/Config/Routes.php` | ambos agregan rutas | fusionar. |
| `app/Controllers/CustomerAccounts.php` | #587 agrega el recibo; #588 agrega el cobro | fusionar. |
| `frontend/src/pages/operations/CustomerAccountDetailPage.vue` | #587 agrega las tarjetas de cobranzas; #588 las tarjetas por moneda | fusionar: **las tarjetas por moneda y la tabla de documentos van antes**; las de cobranzas van después. |
| `tests/feature/CustomerAccountsHttpTest.php` | ambos agregan el contrato de rutas | fusionar. |

---

## 5. Qué se necesita para que #589 entre

#588 deja preparado el modelo:

- `CustomerCharge::PREVISTA` ya existe como modalidad, con su etiqueta. #589 le
  da el circuito de formalización.
- La estimación del viaje (`importe_estimado`, `moneda_estimada`,
  `cotizacion_estimada`, `importe_estimado_ars`) sigue intacta en
  `cliente_cc_cargos`: es la semilla natural del importe previsto.
- El estado de cobranza por documento se **deriva** de las imputaciones
  vigentes (`DocumentBalance`), no es un campo mutable: #589 no puede
  desincronizarlo.
- La identidad por tramo (`clave_vigente = 'TRAMO:{id}'`) permite que un cargo
  previsto sea formalizado sin duplicar: la formalización libera la clave del
  previsto y crea el definitivo, con su propio asiento.

**Lo que #588 NO hace, por diseño:** no crea cargos previstos, no dispara nada
al crear o editar un viaje y no toca la liquidación del chofer.

---

## 6. Checklist de integración de #589

1. Agregar la modalidad `PREVISTA` a `ChargeDocument` y a `CustomerCharge::LEG_MODALITIES`
   **en el caso de uso de #589**, no en el de #588.
2. Crear el cargo previsto con `importe_original` en la moneda original del
   flete y `cotizacion` **estimada** (la del viaje), marcada como tal.
3. Al formalizar (memo, factura o cierre interno), **no** crear un cargo nuevo:
   actualizar el cargo previsto a la modalidad definitiva y asentar la
   diferencia entre el importe previsto y el definitivo con un movimiento
   `DIFERENCIA_CAMBIO` o `AJUSTE_PREVISTO` trazable.
4. Si el cargo previsto ya tiene imputaciones, bloquear el cambio de importe o
   exigir reversión explícita.
5. Agregar los permisos que correspondan y las pruebas de aislamiento.

---

## 7. Checklist de integración de #536 (PR #587)

1. Rebasar `feat/536-recibos-cobranzas` sobre la rama de #588.
2. Resolver las colisiones de la §4, en ese orden.
3. Reemplazar la escritura directa en el libro por los casos de uso de #588.
4. Cambiar `cliente_recibo_imputaciones.importe_ars` por un campo derivado y
   agregar `importe_original`.
5. Prohibir la imputación cruzada de monedas en el caso de uso de #587.
6. Correr la suite completa: los tests del PR #587 (`CustomerReceiptsIntegrationTest`,
   `CustomerReceiptsHttpTest`, `CustomerReceiptTest`, `PaymentInstrumentTest`)
   más los de #588.
7. Verificar en `fasa_189` con un recibo en USD y una factura en USD, y con un
   recibo en BRL contra una factura en BRL.

---

## 8. Rollback

Cada etapa se revierte sola:

- **#588**: `down()` de `2026-11-26-100000_AddMultimonedaCustomerAccounts` quita
  `cliente_cc_pagos` y `cliente_cc_imputaciones` y las columnas agregadas al
  libro. **Se niega** si hay cobranzas registradas o si el libro tiene asientos
  de cobranza: con datos, no se revierte.
- **#589**: `down()` de su migración, con el mismo criterio.
- **#536**: `down()` de `2026-11-25-160000_CreateCustomerReceipts`, que también
  se niega si hay recibos.

El orden de reversión es el inverso al de integración: primero #536, después
#589 y por último #588.
