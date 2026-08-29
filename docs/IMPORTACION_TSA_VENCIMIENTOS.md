# Importación TSA: unidades y vencimientos

Esta entrega adapta las planillas recibidas de TSA al flujo verificable de
Importaciones. La vista previa muestra cada fila normalizada y no persiste
ningún dato hasta que una persona con `importaciones.cargar` confirma el
borrador.

## Fuentes soportadas

- **Unidades de transporte TSA**: lee las hojas de Argentina y Brasil, toma la
  sucursal del nombre de la hoja (`TSAARG` / `TSABR`), normaliza la patente como
  código interno, usa `Camión` como tipo y completa la fecha de alta con la
  fecha actual cuando la fuente no la informa. El modelo y la marca siguen
  sujetos a los catálogos activos de la empresa.
- **Vencimientos**: lee la hoja `unidades`, expande los bloques Argentina y
  Brasil y convierte VTV, SENASA, Póliza y CRVL en filas independientes. Las
  fechas se guardan como `AAAA-MM-DD` y los guiones de la fuente se tratan como
  dato ausente.

La hoja `Choferes` se conserva en la vista previa como error explícito porque
el contexto de Personas/Empleados todavía no está habilitado. No se descartan
silenciosamente esos vencimientos ni se inventa un equipo para asociarlos.

## Plantillas

En `/mantenimiento/importaciones` están disponibles las plantillas XLSX de
**Unidades de transporte** y **Vencimientos**, además de los formatos
existentes. Las plantillas incluyen una fila de ejemplo y una hoja de
instrucciones.

## Alcance de issues

- [#195](https://github.com/oscarvogel/mantenimiento/issues/195): se creó el
  catálogo y registro tenant-scoped de vencimientos de equipos, con estado
  derivable por fecha y anticipación configurable por tipo.
- [#196](https://github.com/oscarvogel/mantenimiento/issues/196): la carga
  conserva el archivo original en almacenamiento privado y deja preparados
  número/observaciones; la asociación de documentos binarios reutilizará la
  infraestructura privada existente cuando el módulo de adjuntos de
  vencimientos se habilite.
- [#200](https://github.com/oscarvogel/mantenimiento/issues/200): el ciclo
  central de notificaciones incorpora vencimientos próximos y vencidos con
  clave idempotente, alcance por empresa/sucursal y enlace a la ficha del
  equipo.

## Evidencia visual

Con el stack local levantado se registro evidencia visual de las pantallas
disponibles:

- [Importaciones con plantillas TSA](screenshots/issues-195-196-200/importaciones-local.png)
- [Dashboard autenticado del backend](screenshots/issues-195-196-200/dashboard-backend-local.png)
- [Dashboard del frontend Vite](screenshots/issues-195-196-200/dashboard-frontend-local.png)

Las capturas documentan el estado local; la vista previa real de las dos
planillas requiere iniciar una importacion y confirmar el archivo desde la
interfaz autenticada.
