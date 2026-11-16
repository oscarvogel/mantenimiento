# Bitácora 2026-11-16 - Informe de mantenimiento TSA

## Contexto

Se preparó un informe de mantenimiento para gerencia de **TSA** con datos al
**16/11/2026**, a partir de un extracto de solo lectura de producción. No se
descargó la base completa ni se copiaron datos productivos fuera del extracto
necesario.

Entregables locales, fuera del árbol Git:

- `output/informe-tsa/INFORME_MANTENIMIENTO_TSA_2026-11-16.pdf` (15 páginas, con carátula)
- `output/informe-tsa/INFORME_MANTENIMIENTO_TSA_2026-11-16.md`
- `output/informe-tsa/informe-json.json` (extracto usado por el generador)
- `output/informe-tsa/informe-json-2026-11-16-pre-limpieza.json` (evidencia previa a la limpieza)
- `output/informe-tsa/operacion-avisos-*.json` (resultado de la limpieza)

## Correcciones de moneda aplicadas al informe

- `OT-2026-000074` y `OT-2026-000075`: importes en **BRL**, convertidos a **$ 300 por real**.
- `OT-2026-000002`: importe en **ARS**.
- `OT-2026-000020`: registro atípico de **201.377 BRL**; se excluye de los totales y queda marcado para corrección contra comprobante.

Con esas correcciones, el costo correctivo normalizado a pesos, sin la OT atípica,
queda en **$ 18.814.125,16**.

## Limpieza de avisos preventivos

Al corte había 10 avisos pendientes. Se resolvieron 9 con motivo auditable y se
dejó visible el único vencido real.

| Aviso | Equipo | Motivo |
|---:|---|---|
| 77 | AD738EA | Revalidado: el plan ya no está vencido (876.849 km actuales; objetivo 891.669 km). |
| 76 | AF117ZK | Ciclo superado: existe la OT preventiva OT-2026-000076 abierta. |
| 73 | BEN5D63 | Ciclo superado: plan recalculado el 23/10/2026. |
| 67 | ITV9J84 | Revalidado: el plan ya no está vencido (1.165.872 km actuales; objetivo 1.182.170 km). |
| 61 | RHB2H00 | Ciclo superado: service cerrado en la OT preventiva OT-2026-000058. |
| 58 | FKU3A05 | Ciclo superado: service cerrado en la OT preventiva OT-2026-000060. |
| 55 | IUR1E71 | Ciclo superado: plan actualizado el 23/09/2026. Revisar base de kilometraje (1.104.584 km) contra lectura actual (1.079.714 km). |
| 40 | IYT2F23 | Ciclo superado: existe la OT preventiva OT-2026-000030 abierta. |
| 37 | HWW968 | Ciclo superado: service cerrado en la OT preventiva OT-2026-000064. |

Queda pendiente el aviso **80 / AF081MJ**, con el plan vencido por kilometraje
(−116 km). La generación de la OT queda a cargo de administración.

El helper temporal se subió por FTPS, se ejecutó con token y se eliminó del
servidor; se verificó que su URL respondiera 404.

## Criterio de estado de los planes

El informe no usa la fecha objetivo para determinar el estado: 14 de 26 planes
comparten la misma fecha (2027-01-28) porque se crearon en bloque. El estado se
calcula comparando el kilometraje actual con el próximo kilometraje de service y
su margen de anticipación. El detalle de los 26 planes quedó en el Anexo D.

Resultado al corte: **23 al día, 2 próximos y 1 vencido**.

## Scripts incorporados

- `scripts/caratula-informe.py`: genera una carátula A4 con el logo de Vogel y el
  sitio `vogelconsultoria.com.ar`, y la antepone al PDF del informe.
- `scripts/operacion-2026-11-16-avisos-tsa-remote.php` y
  `scripts/operacion-2026-11-16-avisos-tsa.py`: operación puntual y reproducible de
  limpieza de avisos. Conservan los IDs y motivos aplicados como evidencia.

## Causa raíz de los avisos obsoletos

Los avisos quedaron pendientes porque los cierres de OT del 20/10/2026 no
quedaron vinculados al plan (`plan_id` nulo en el extracto) y el plan se
recalculó sin ejecutar la resolución de avisos del ciclo anterior. La limpieza
corrigió el dato; falta corregir el flujo para que no vuelva a repetirse.

## Pendiente

- Generar la OT preventiva de **AF081MJ** (administración).
- Implementar los planes nuevos en el sistema: semirremolques, frenos, neumáticos
  y lubricación; luego eléctrico, aire, diagnóstico electrónico, tren rodante y
  suspensión.
- Cargar el kilometraje de **GZD587** y crear su plan.
- Completar costos, repuestos y detalle de cierre en las OTs.
