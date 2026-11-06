# Deploy staging: diario de bordo y estadías automáticas

**Fecha:** 06/11/2026
**Entorno:** staging canónico `fasa_189` / Docker / Coolify
**Aplicación:** `mantenimiento-staging` (`bdqictyu4q5xkfuh6aoh7sr8`)
**Proyecto / environment:** `mantenimiento` / `staging`
**Rama / commit:** `main` / `e4777c9a`
**Imagen / contenedor:** `bdqictyu4q5xkfuh6aoh7sr8:e4777c9a` / `bdqictyu4q5xkfuh6aoh7sr8-20261106T142251`
**Healthcheck:** `healthy`

## 1. Alcance

Se desplegó el diario de bordo del chofer:

- Etapas `CARGA`, `FRONTERA` y `CLIENTE`.
- Cálculo de días por diferencia de fechas y franquicia por etapa.
- Corrección manual de días con motivo.
- Generación, actualización y baja lógica automática del haber de estadía vinculado.

La rama de la aplicación en Coolify se alineó de `feat/reportes-automatizaciones`
a `main`, con el commit fijado en `e4777c9a`. Auto Deploy permanece desactivado.

## 2. Migraciones

| Versión | Clase | Estado |
|---|---|---|
| `2026-11-06-100000` | `AddPrincipalFlagToLocalities` | Aplicada |
| `2026-11-06-130000` | `CreateOperationalTripLogbook` | Aplicada |

La migración del diario se creó originalmente con versión `2026-11-06-120000`,
que colisionaba con `CreateAuditoriaTable`. Se renombró a `2026-11-06-130000`
antes de considerarla definitiva. En staging se corrigió el registro de
`migrations` para que coincida con el nombre final; el deploy posterior ya usó
el archivo renombrado.

Comandos ejecutados dentro del contenedor App:

```bash
php spark migrate
php spark migrate:status
php spark db:table viaje_operativo_diario_bordo
```

Resultado: sin migraciones pendientes y tabla del diario presente.

## 3. Verificación HTTP

| Chequeo | Resultado |
|---|---|
| `GET /login` | 200 |
| Login con `demo@mantenimiento.local` | 200 |
| `GET /viajes/operativos/1` autenticado | 200 |
| Payload de detalle con `logbook` y link `diario-bordo` | Presente |
| `GET /.env` | 403 |

## 4. Pendientes

- Prueba funcional completa con un viaje en curso: cargar una etapa, verificar
  el haber generado y corregir los días.
- Validación visual escritorio/móvil del modal y de la tabla del diario.
- Confirmar con Camila si la franquicia por etapa debe quedar fija en 48 h o
  configurarse por empresa/cliente.
