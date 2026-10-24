# Decisiones pendientes — Fase 0 de la ampliación

**Fecha:** 24/10/2026
**Estado:** D3 cerrada. D1, D2, D4, D5, D6, D7 y D8 esperando respuesta del cliente.
**Origen:** `docs/PLAN_AMPLIACION_NEUMATICOS_VIAJES_FACTURACION.md` §8.

---

## 1. Para qué sirve este documento

La Fase 0 del plan de ampliación **cierra cuando la especificación está actualizada y D1 a D8
tienen respuesta**. La especificación ya está actualizada (ver el cierre más abajo). Las ocho
decisiones son del cliente: no se pueden deducir de los datos sin inventar.

Este documento deja cada decisión con su evidencia, su impacto y una pregunta que se pueda
mandar tal cual. Ninguna se cierra por suposición.

---

## 2. Estado de cada decisión

| # | Decisión | Estado | Bloquea |
|---|---|---|---|
| **D1** | Situación del certificado de ARCA | **Abierta — cliente** | Todo el contexto `Facturación`. Sin esto no hay facturación. |
| **D2** | Alcance de cobranzas: ¿entran o es un contexto aparte? | **Abierta — cliente** | Si son 3 o 4 contextos nuevos. Afecta `AGENTS.md`. |
| **D3** | Cuenta corriente por viaje (`t11`, `t12`, `t13`) | **CERRADA** | Nada. |
| **D4** | Dónde están los clientes reales | **Abierta — cliente** | Facturación. |
| **D5** | ¿Una empresa o cuatro? | **Abierta — cliente** | El `scope` de `empresa_id` en todas las importaciones. |
| **D6** | Sucursales reales de la empresa | **Abierta — cliente** | Contexto `Organization`. |
| **D7** | Padrón definitivo de flota | **Abierta — cliente** | Contexto `Assets`. |
| **D8** | ¿Dónde se anota hoy el mantenimiento? | **Abierta — cliente** | Si hay histórico que migrar o se arranca de cero. |

---

## 3. Detalle y evidencia

### D1 — Certificado de ARCA · abierta

**Pregunta:** ¿El certificado de ARCA sigue vigente? ¿Se renueva? ¿Hay que dar de alta un
punto de venta nuevo?

**Por qué bloquea:** el contexto `Facturación` depende de la autorización. Sin certificado
vigente no hay CAE, y sin CAE no hay comprobante. El puerto `ArcaGateway` ya está escrito
(`docs/CONTRATOS_ENTRE_CONTEXTOS.md` §4.7), pero el adaptador necesita un certificado real
para probarlo contra el servicio.

**Qué sí se hizo:** el contrato, los DTO de autorización y anulación, y el criterio de que un
rechazo sea un resultado y no una excepción.

### D2 — Alcance de cobranzas · abierta

**Pregunta:** ¿Entran recibos, cheques, bancos, cuentas corrientes y caja (`a47`, `a50`, `a51`,
`a13`, `a57`), o solo facturación?

**Por qué bloquea:** define si son **tres** contextos nuevos (`Viajes`, `Neumáticos`,
`Facturación`) o **cuatro**, agregando `Tesorería` con su propio ciclo. `AGENTS.md` ya
documenta los tres; si entra cobranzas, hay que agregar un cuarto con su agente propietario.

**Qué sí se hizo:** el plan y `AGENTS.md` registran los tres contextos. `Tesorería` queda
anotada como condicional hasta la respuesta.

### D3 — Cuenta corriente por viaje · CERRADA

**Decisión del usuario, 17/10/2026:** *se migra preservando*. Las tablas `t11`, `t12` y `t13`
del legacy se importan **tal cual**, con sus columnas, monedas y cotizaciones originales, y
**sin calcular saldos ni totales derivados**.

**Por qué se cierra:** contradice el análisis previo, que las descartaba por monedas
inconsistentes. La evidencia que sostenía ese descarte no se sostiene: el importe de la
empresa es una decisión de negocio, no un problema de datos.

**Consecuencia técnica:** la cuenta corriente **operativa** nueva (`viaje_operativo_caja_movimientos`
y `viaje_operativo_otros_haberes`) es independiente de la historia legacy. La legacy queda
como solo lectura. Es exactamente lo que dice la decisión 10 del §5 de la especificación de
viajes.

**Estado:** cerrada y **implementada**. El importador cubre las seis tablas hijas del
agregado: `t05` viajes, `t06` tramos, `t07` combustible, `t08` gastos, `t11` cuenta
corriente, `t12` cuenta corriente por tramo y `t13` otros haberes. Las tres últimas se
importan preservando las columnas, las monedas y las cotizaciones del origen, sin
calcular saldos ni totales derivados, tal como pide la decisión. La lectura del detalle
del viaje y el análisis de rentabilidad ya incluyen la cuenta corriente y los otros
haberes.

### D4 — Clientes reales · abierta

**Pregunta:** ¿Dónde están los clientes reales? El backup tiene **una** fila ("Clientes
Varios") y `a13` tiene 990 movimientos.

**Por qué bloquea:** sin clientes no hay comprobante. Es bloqueante para `Facturación`.

**Estado real del código:** existe el padrón canónico (`app/Application/Customers/`) con
importador, vista previa, confirmación transaccional e idempotencia, validado en staging
`fasa_189` con **datos sintéticos**: 25 pruebas PHP y 392 pruebas frontend en verde
(`docs/EVIDENCIA_IMPORTACION_CLIENTES_2026-10-16.md`).

**Lo que NO se hizo:** *no se importaron clientes reales*. El código quedó en una rama
integrada, pero no se subió nada a Ferozo. Los 240 códigos de cliente que usa el backup
siguen sin cargar en ninguna base.

**Contraste con el análisis previo:** la bitácora del 17/10 decía que el padrón "no está en
`origin/main` y no hay evidencia de que esté cargado". La primera parte ya no es cierta —el
código está integrado—; la segunda **sigue siendo cierta**.

### D5 — ¿Una empresa o cuatro? · abierta

**Pregunta:** ¿La operación es una sola empresa o cuatro?

**Evidencia:** las 28 unidades y los 1.740 viajes del backup son de **un solo CUIT**
(PAWLUK DUBLIN MARIELA ELISA). Crear las cuatro deja tres vacías.

**Recomendación del análisis:** una sola empresa, con las tres restantes como configuración
pendiente si aparecen.

**Por qué bloquea:** `empresa_id` es la invariante de aislamiento de todo el sistema. Si se
crean cuatro empresas vacías, cada cliente importado queda sin empresa asignable y la
vista previa no puede confirmar viajes.

### D6 — Sucursales reales · abierta

**Pregunta:** ¿Cuáles son las sucursales de la empresa?

**Evidencia:** `a46_sucursales` es la matriz banco × sucursal del legacy, no un catálogo de
sucursales de la empresa.

**Lo que ya se investigó:** la patente no alcanza para deducir el país. Hay una propuesta
documentada en `docs/BITACORA_IMPORTACION_VIAJES_HISTORICOS.md` §4: los 13 dominios
Mercosur-BR salen de base brasilera; los 7 de formato ambiguo salen de base argentina y se
asignan a **TSA Argentina**, con la evidencia registrada y validación obligatoria en la vista
previa. `NEB021` queda marcado como el caso de mayor duda (24 viajes, una sola mención de
Brasil).

**Por qué bloquea:** es la única forma de que un viaje importado tenga sucursal sin que
alguien la invente.

### D7 — Padrón definitivo de flota · abierta

**Pregunta:** ¿Cuál es la lista real y activa de las 28 unidades?

**Evidencia:** las 28 unidades están en el backup, pero sin año, chasis ni motor, y hay
basura (`AA PRUEBA`).

**Por qué bloquea:** `Assets` es el dueño de la ficha del equipo, y de ahí cuelgan las
lecturas, los planes, los neumáticos y los viajes. El contrato `EquipmentByPlateLookup`
—ya escrito— no se puede implementar sin saber contra qué padrón resuelve.

### D8 — ¿Dónde se anota hoy el mantenimiento? · abierta

**Pregunta:** Con 28 camiones operando desde 2022, ¿en qué sistema se anota el mantenimiento
hoy?

**Evidencia:** no hay **ningún** registro de servicio en ninguno de los dos sistemas
analizados.

**Por qué bloquea:** define si el módulo arranca de cero o si hay que migrar un histórico.
No bloquea el diseño: bloquea el alcance de la migración.

---

## 4. Preguntas listas para mandar al cliente

Están en el orden en que conviene resolverlas: las dos primeras cambian el tamaño del
proyecto.

1. **Facturación.** ¿El certificado de ARCA sigue vigente? ¿Se renueva? ¿Hay que dar de
   alta un punto de venta nuevo?
2. **Cobranzas.** ¿El sistema va a registrar cobros (recibos, cheques, bancos, cuentas
   corrientes y caja) o solo a emitir comprobantes?
3. **Clientes.** ¿Dónde están los clientes reales? El backup de transporte tiene una sola
   fila y 990 movimientos sin cabecera utilizable.
4. **Empresas.** ¿La operación es de una sola empresa o de cuatro? Los 28 camiones y los
   1.740 viajes son de un mismo CUIT.
5. **Sucursales.** ¿Cuáles son las sucursales de la empresa y a qué país corresponde cada
   unidad?
6. **Flota.** ¿Cuál es la lista real y activa de las 28 unidades, y cuál es la que no
   opera?
7. **Mantenimiento.** ¿Dónde se anota hoy el mantenimiento de los camiones?

Aparte, el circuito de viajes tiene su propio cuestionario, todavía sin respuesta, en
`docs/PROPUESTA_VIAJES_CIRCUITO_LIQUIDACION.md` §8: si el viaje se abre y sale en el mismo
momento, si siempre hay un solo chofer responsable, si los fletes pueden ir en otra moneda,
si los otros haberes son por viaje o por tramo, si el cierre se bloquea sin combustible,
si la liquidación se paga por viaje o por período, si hay que imprimir la liquidación y si
se usa el tilde "Contabilizado".

---

## 5. Qué se cerró sin necesidad de respuesta

La Fase 0 no era solo D1 a D8. Estos puntos se resolvieron con la evidencia disponible y
quedaron documentados:

| Punto | Estado |
|---|---|
| Mapa de contextos y contratos | Cerrado en `docs/PLAN_AMPLIACION_NEUMATICOS_VIAJES_FACTURACION.md` §2, con los tres contextos nuevos y sus contratos. |
| Contratos escritos | Cerrado en `docs/CONTRATOS_ENTRE_CONTEXTOS.md`: 5 interfaces nuevas, 3 reutilizadas, todas con lint en verde. |
| Especificación de sistema actualizada | Cerrado: §2.2, §3.3, §5.12-5.14, §6.11-6.13, §11, §13 (42 casos) y §15 (etapas 6 a 8) reflejan los tres contextos. |
| `AGENTS.md` con los contextos nuevos | Cerrado: los tres contextos quedan con agente propietario y contratos cruzados. |
| D3 (cuenta corriente por viaje) | Cerrada por decisión del usuario el 17/10/2026. |
