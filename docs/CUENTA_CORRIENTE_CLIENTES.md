# Cuenta corriente de clientes en ARS — etapa 1 (#530)

**Fecha:** 17/11/2026 · **Contexto:** `CustomerAccounts` (Domain / Application / Infrastructure).

## Decisiones confirmadas con Oscar (17/11/2026)

- La deuda se genera **por tramo** (cada tramo tiene su cliente y su flete).
- **Memo autorizado:** lo carga Administración y al cargarlo queda autorizado.
- **Factura emitida:** se registra a mano (tipo, punto de venta y número, fecha) mientras no
  haya emisión electrónica (D1, ARCA).
- **Saldo inicial por cliente:** a cobrar o a favor, con fecha y referencia. Uno vigente por cliente.

## Reglas implementadas

1. Cerrar un viaje **no** genera deuda. El tramo queda en *Cierre comercial → Pendientes*.
2. Formalizar el tramo (memo, factura o cierre comercial aprobado) genera **un único Debe en ARS**
   con la cotización de la fecha del documento, congelada con su fuente (`MANUAL`,
   `CATALOGO_EMPRESA` o `PESOS`). La estimación del viaje queda guardada aparte, sin reescribirse.
3. El cierre comercial aprobado emite una liquidación interna no fiscal `LI-000123`.
4. *No cobrable* cierra el tramo sin deuda, con motivo. Se puede reabrir con motivo.
5. Corregir = **anular con contraasiento** (fecha del día) y volver a formalizar. Nada se edita ni se borra.
6. El saldo se deriva del libro (`cliente_cc_movimientos`, solo inserciones); cada asiento tiene
   una clave única por empresa (idempotencia). `clave_vigente` impide dos cargos vigentes por tramo.
7. Cada alta, anulación, no cobrable y reapertura queda en `auditoria` (`cliente_cc.*`).

## Tablas y permisos

- `cliente_cc_cargos`, `cliente_cc_movimientos`, `cliente_cc_tramos_no_cobrables`
  (migración `2026-11-18-090000_CreateCustomerAccounts`, aditiva; `down()` se niega con datos).
- `clientes.cc.ver`: Administrador y roles con `viajes.economico.ver` (Gerencial).
- `clientes.cc.formalizar`: Administrador.

## Pantallas

- `viajes/cierre-comercial`: Pendientes / Sin cliente / Formalizados / No cobrables.
- `clientes/cuenta-corriente`: saldo único ARS por cliente.
- `clientes/cuenta-corriente/{id}`: estado de cuenta con saldo acumulado, saldo inicial y anulación.

## Fuera de esta etapa

- Cobros, recibos y cheques: implementados en la etapa 2 (#536), ver
  `docs/RECIBOS_COBRANZAS_CLIENTES.md`. La cartera completa de cheques (#537) y la
  tesorería (#538) siguen fuera.
- Un documento que agrupe varios tramos con vínculo N:M (hoy cada tramo lleva su referencia).
- Modalidad tentativa al planificar el viaje; PDF/Excel del estado de cuenta; reporte estimado vs. definitivo.
- País y moneda habitual del cliente, CNPJ/CPF.
