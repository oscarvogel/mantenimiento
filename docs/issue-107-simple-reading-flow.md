# Issue #107 — Flujo individual de lectura

La ruta existente `/mantenimiento/lecturas/rapidas` conserva el endpoint fila-a-fila y pasa a mostrar por defecto un flujo individual orientado a operadores.

- búsqueda de equipo por el filtro existente del backend;
- selección explícita del equipo;
- muestra de la última lectura visible;
- campo de km y/o horas según configuración del equipo;
- fecha/hora y observación opcional;
- guardado mediante `QuickReadings::storeRow`;
- estado preventivo recalculado usando la respuesta ya existente;
- acceso a la carga masiva anterior con `?modo=masivo`.

No se duplica la lógica de persistencia ni las reglas de validación del dominio.
