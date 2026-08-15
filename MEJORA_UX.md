# V3 — MEJORA DE USABILIDAD OPERATIVA

Quiero realizar una revisión y mejora transversal de UX del sistema de mantenimiento.

IMPORTANTE:
Esto NO es un rediseño visual.
NO quiero cambiar la identidad visual actual.
NO quiero agregar funcionalidades por agregar.
NO quiero modificar arquitectura innecesariamente.

El objetivo es hacer que las funcionalidades existentes sean realmente cómodas para una persona que utiliza el sistema todos los días.

---

# REGLA OBLIGATORIA DE DISEÑO

A partir de esta tarea, toda decisión de UX debe responder a esta pregunta:

"¿Cómo va a hacer esto una persona todos los días?"

NO diseñar pensando solamente:

"¿Qué campos necesita el backend?"

Diseñar pensando:

- qué sabe la persona;
- qué necesita hacer;
- qué información ya conoce el sistema;
- qué errores previsibles puede cometer;
- cuánto tarda en completar la tarea;
- cuántas veces tiene que repetir pasos;
- qué información necesita antes de decidir;
- qué necesita saber después de guardar.

Una funcionalidad no se considera usable solamente porque:

input -> POST -> guardar

Debe ser clara, rápida, preventiva y proporcionar feedback inmediato.

---

# PRINCIPIOS UX OBLIGATORIOS

Aplicar transversalmente estos principios:

1. No mostrar IDs internos al usuario.
2. No mostrar códigos técnicos cuando exista una descripción humana.
3. No hacer que el usuario calcule algo que el sistema puede calcular.
4. No pedir información que el sistema ya conoce.
5. No mostrar campos que no aplican al contexto.
6. Mostrar siempre el valor actual antes de pedir un nuevo valor cuando sea relevante.
7. Mostrar consecuencias importantes antes de guardar.
8. Advertir errores previsibles antes de enviar al servidor.
9. Mantener igualmente las validaciones definitivas en backend.
10. Mostrar claramente qué ocurrió después de guardar.
11. Mantener el contexto después de una acción.
12. Reducir clics y pasos en tareas repetitivas.
13. Priorizar carga rápida mediante teclado.
14. Mantener mobile completamente usable.
15. Usar lenguaje de negocio, no lenguaje de base de datos/programación.
16. Evitar confirmaciones innecesarias.
17. No saturar la pantalla de información secundaria.
18. Priorizar siempre la siguiente acción que el usuario probablemente necesita realizar.

---

# PRIORIDAD 1
## LECTURAS RÁPIDAS

Revisar completamente:

`QuickReadingsPage.vue`

y todo el flujo asociado de lecturas.

Actualmente esta será probablemente una de las operaciones más repetidas del sistema.

Debe poder realizarse de manera rápida y con mínima posibilidad de error.

---

## 1.1 HORÓMETRO

Actualmente "Horómetro" puede resultar ambiguo.

Debe quedar explícito que el usuario ingresa:

LA LECTURA TOTAL ACTUAL DEL HORÓMETRO

y NO:

las horas trabajadas desde la última carga.

Cambiar el lenguaje por algo similar a:

"Lectura actual del horómetro"

Mostrar debajo:

"Última registrada: 1.250,4 h"

y una ayuda breve:

"Ingresá el valor total que muestra actualmente el horómetro."

No escribir textos excesivamente largos.

---

## 1.2 MOSTRAR DIFERENCIA AUTOMÁTICA

Cuando el usuario escriba una nueva lectura:

actual:
1250.4

nuevo:
1258.4

mostrar inmediatamente:

"+8,0 h desde la última lectura"

Para kilómetros igual:

actual:
120.500 km

nuevo:
120.750 km

mostrar:

"+250 km desde la última lectura"

Usar formato `es-AR`.

Esto debe ocurrir en frontend sin esperar al POST.

---

## 1.3 DETECCIÓN VISUAL DE ERRORES

Si el usuario ingresa:

nuevo < actual

mostrar inmediatamente:

"⚠ Esta lectura es 50,4 h menor que la última registrada."

No esperar al backend para descubrirlo.

Si nuevo === actual:

mostrar:

"Sin variación respecto de la última lectura."

No bloquear automáticamente una lectura idéntica si el dominio actualmente la permite, salvo que se determine que funcionalmente debe rechazarse.

Backend sigue siendo autoridad definitiva.

---

## 1.4 SOLO MOSTRAR CAMPOS QUE APLICAN

Si el equipo:

controla horas
pero no kilómetros

NO mostrar Kilómetros deshabilitado.

Mostrar únicamente:

Horómetro.

Si controla únicamente kilómetros:

mostrar únicamente Kilómetros.

Si controla ambos:

mostrar ambos.

---

## 1.5 FECHA Y HORA COMÚN

Actualmente cada equipo puede terminar teniendo su propio datetime.

Para carga diaria masiva quiero un control principal arriba:

"Fecha y hora de las lecturas"

con valor actual.

Ese valor debe aplicarse inicialmente a todas las filas.

Permitir modificar individualmente una fila solo si existe una necesidad real.

IMPORTANTE:

No utilizar permanentemente la hora generada cuando se cargó la página.

Si la página quedó abierta 3 horas, al comenzar una nueva carga debe poder utilizar la hora actual.

No registrar silenciosamente una hora vieja.

---

## 1.6 PROGRESO DE GUARDADO

Al guardar varias lecturas:

NO mostrar solamente:

"Guardando..."

Mostrar:

"Guardando 3 de 8..."

Cada equipo debe tener estado:

Pendiente
↓
Guardando
↓
Guardada

o:

Error

Después:

CAM-01
✓ 1.258,4 h registrada
+8,0 h

Si falla:

CAM-02
✕ No se pudo registrar
"La lectura es menor que la actual."

No mostrar:

"Equipo #9"

Mostrar siempre:

Código
Patente si existe
Tipo si aporta contexto.

---

## 1.7 BOTÓN DE GUARDAR

Si no existe ninguna lectura ingresada:

"Guardar lecturas"

debe estar deshabilitado.

Cuando existen lecturas:

"3 lecturas listas para guardar"

o equivalente.

Evitar que el usuario pulse un botón que no hará nada.

---

## 1.8 TECLADO

Optimizar carga rápida.

Ejemplo:

escribir lectura
↓
Enter / Tab
↓
siguiente equipo

No forzar uso del mouse entre cada unidad.

No romper accesibilidad.

---

# PRIORIDAD 2
## REGISTRAR LECTURA DESDE OTRAS PANTALLAS

Buscar TODOS los lugares donde se pueda registrar:

- kilometraje;
- horómetro.

Por ejemplo:

MaintenanceOverviewPage.vue
EquipmentDetailPage.vue
cierre de OT
otras vistas.

La experiencia debe ser consistente.

Crear si corresponde un componente reutilizable:

`UsageReadingInput.vue`

o equivalente.

NO duplicar la misma lógica de:

- lectura anterior;
- nueva lectura;
- delta;
- validación visual;
- formato;

en múltiples componentes.

---

# PRIORIDAD 3
## CIERRE DE ORDEN DE TRABAJO

Revisar el flujo:

"Cerrar orden"

Actualmente existen datos como:

- trabajo realizado;
- fecha;
- km salida;
- horas salida.

Transformar el flujo en algo entendible para un técnico.

---

## 3.1 LECTURAS AL CERRAR

Si el equipo controla horómetro:

mostrar:

Horómetro actual:
5.240,5 h

Nueva lectura:
[ 5247,8 ]

Resultado:
+7,3 h

Si no controla horas:

NO mostrar Horas salida.

Lo mismo con kilómetros.

---

## 3.2 EXPLICAR CONSECUENCIA

Si cerrar la orden recalcula el mantenimiento:

mostrar:

"Al cerrar esta orden, el sistema recalculará el próximo mantenimiento."

Si es posible calcularlo previamente:

mostrar preview:

Próximo mantenimiento:
8.500 h

o:

Próximo mantenimiento:
12/11/2026

No obligar al usuario a interpretar:

"Cerrar y recalcular"

Cambiar por lenguaje más natural:

"Cerrar orden"

La recalculación es responsabilidad interna del sistema.

---

# PRIORIDAD 4
## PLANES PREVENTIVOS

Revisar:

PreventivePlansPage.vue

Especial atención a términos como:

Base km
Base horas
Base fecha
Cada km
Anticipación

---

## 4.1 TRADUCIR CONCEPTOS

No eliminar necesariamente los conceptos de dominio.

Pero presentarlos de forma humana.

Por ejemplo:

En vez de únicamente:

Base horas:
8000

mostrar:

"Último mantenimiento realizado a las"

[ 8.000 ] h

Frecuencia:

[ 500 ] h

Resultado:

"Próximo mantenimiento: 8.500 h"

---

## 4.2 PREVIEW DE PRÓXIMO MANTENIMIENTO

Mientras el usuario modifica:

base + intervalo

mostrar inmediatamente:

"Próximo: 8.500 h"

Lo mismo para:

km
fecha

No hacer que calcule mentalmente.

---

## 4.3 ANTICIPACIÓN

En vez de presentar solamente:

"Anticipación: 100 h"

mostrar algo equivalente a:

"Avisar 100 h antes"

y, si existe próximo:

"Aviso desde: 8.400 h"

---

## 4.4 CAMPOS SEGÚN EQUIPO

Antes de elegir equipo:

NO mostrar campos km/horas como si aplicaran.

Después de seleccionar:

mostrar únicamente criterios soportados.

---

## 4.5 REVISAR PRECARGA DE PLANTILLA

Verificar cuidadosamente:

`manualTemplateDefault`

La referencia precargada debe corresponder realmente a:

- equipo;
- tipo;
- servicio seleccionado;

cuando corresponda.

NO permitir que el usuario seleccione un servicio y reciba frecuencias pertenecientes silenciosamente a otro servicio.

Esto debe tener tests.

---

# PRIORIDAD 5
## DASHBOARD

El dashboard debe responder:

"¿Qué requiere mi atención ahora?"

y después:

"¿Qué puedo hacer con eso?"

Actualmente no quiero solamente métricas.

Si aparece:

3 vencidos

el usuario debe poder llegar directamente a esos vencidos.

Si aparece:

CAM-15 · Service motor · Vencido

esa fila debe permitir:

"Abrir"
"Atender"

según permisos.

Evitar:

Dashboard
↓
Ver todos
↓
Buscar nuevamente CAM-15
↓
abrir

Mantener contexto y deep links.

---

# PRIORIDAD 6
## CENTRO OPERATIVO / SERVICIOS

Revisar:

MaintenanceOverviewPage.vue

La pantalla tiene muchas responsabilidades.

NO rediseñar completamente.

Pero priorizar lo que una persona viene a hacer.

Preguntar conceptualmente:

- Responsable de mantenimiento → vencimientos y decisiones.
- Técnico → órdenes asignadas.
- Operador → lecturas.
- Administrador → configuración.

No mostrar el mismo nivel de protagonismo a todas las acciones.

Usar permisos/rol/contexto para priorizar contenido cuando sea posible sin duplicar pantallas.

---

## DETECTAR VENCIDOS

Investigar específicamente el botón:

"Detectar vencidos"

El usuario no debería tener que recordar ejecutar un cálculo interno del sistema diariamente.

Si los vencimientos por lectura ya se recalculan automáticamente, revisar cómo automatizar correctamente también los de fecha.

Preferencias:

- reevaluación durante operaciones relevantes;
- cron/tarea programada existente;
- cálculo al consultar;

según arquitectura.

NO eliminar el endpoint hasta entender por qué existe.

Si el botón debe seguir existiendo:

que sea administrativo/diagnóstico, no parte del flujo diario principal.

---

# PRIORIDAD 7
## EQUIPOS

Revisar:

AssetsIndexPage.vue

---

## 7.1 SUCURSAL

Actualmente existe un filtro:

"Sucursal ID"

Esto NO es aceptable para una interfaz de usuario.

Reemplazar por:

Sucursal

<select>

con nombres reales:

Puerto Rico
Posadas
Casa Central
etc.

El ID continúa siendo value interno.

Nunca pedir a una persona recordar IDs.

---

## 7.2 USO ACTUAL

Si el equipo no controla km:

no mostrar:

—

km

Si no controla horas:

no mostrar:

—

h

Mostrar únicamente las métricas aplicables.

---

## 7.3 ACCIONES CONTEXTUALES

Mantener acciones frecuentes visibles:

Ficha
Asignar plan

QR puede mantener menor protagonismo si no es una acción de uso diario.

En mobile evitar demasiados botones equivalentes compitiendo visualmente.

---

# PRIORIDAD 8
## IMPORTACIONES

Revisar:

ImportsShowPage.vue

Actualmente los datos normalizados pueden mostrarse mediante:

JSON.stringify(...)

Eso es útil para desarrollo pero NO como interfaz final.

Eliminar la representación JSON orientada a programadores.

---

## MOSTRAR DATOS HUMANOS

Ejemplo:

Fila 17

Equipo:
CAM-08

Fecha:
15/08/2026 13:45

Horómetro:
8.250,4 h

Estado:
ERROR

Problema:

"El horómetro es menor que la última lectura registrada: 8.300,1 h."

No:

{
   "equipment_id": 9,
   "horometro": "8250.4"
}

---

## ERRORES

Mostrar:

Campo
Valor recibido
Problema
Cómo resolverlo

cuando sea posible.

Ejemplo:

Horómetro

Ingresado:
825.4

Último:
8250.4

"Revisá si falta un cero."

No inventar correcciones automáticas peligrosas.

---

# PRIORIDAD 9
## HISTORIALES

Buscar lugares donde actualmente se muestren cosas como:

CARGA_RAPIDA
ORDEN_TRABAJO
ALTA_INICIAL

o:

Sucursal #3

Traducirlos para UI:

Carga rápida
Cierre de orden
Lectura inicial
Puerto Rico

Mantener códigos internos solamente en backend/auditoría técnica si son necesarios.

Crear formatters/mappings compartidos.

No hacer replace() improvisado en cada componente.

---

# PRIORIDAD 10
## BIBLIOTECA PREVENTIVA

La funcionalidad ya está mejor encaminada.

Hacer revisión de lenguaje.

Ejemplo:

"Observaciones de la relación"

es lenguaje del modelo:

tipo_servicio_tareas

Cambiar por algo humano:

"Indicaciones para esta tarea dentro del servicio"

o equivalente.

Buscar otros textos de dominio técnico que puedan entenderse mejor sin perder precisión.

---

# PRIORIDAD 11
## USUARIOS Y PERMISOS

No rehacer administración.

Mejorar comprensión antes de guardar.

Cuando se seleccionan:

roles
+
sucursales

mostrar si es viable un resumen:

"Este usuario tendrá acceso a:"

- Equipos
- Lecturas
- Planes preventivos
- Órdenes

"Sucursales:"

- Puerto Rico
- Posadas

Especialmente antes de modificar accesos existentes.

No reemplazar permisos del backend.

Es solamente representación humana de su efecto.

---

# PRIORIDAD 12
## FEEDBACK

Después de toda operación importante la persona debe saber:

1. qué ocurrió;
2. sobre qué entidad;
3. qué cambió;
4. qué debería hacer después, si aplica.

Ejemplo malo:

"Operación realizada correctamente."

Ejemplo bueno:

"Lectura de CAM-14 actualizada a 8.520,4 h."

"El próximo service quedó previsto para 9.000 h."

SweetAlert2 ya existe o puede reutilizarse.

No saturar de alerts.

Para cargas repetitivas, preferir feedback inline sobre modal.

---

# LENGUAJE

Auditar textos de UI.

Evitar:

- ID
- payload
- origenPlantilla
- relación
- persistencia
- recalcular
- referencia interna
- CARGA_RAPIDA
- campos con snake_case
- códigos técnicos

cuando exista una alternativa más natural.

No ocultar información necesaria para auditoría.

Separar:

Información para operar
vs
Información técnica/auditoría.

---

# FORMATO NUMÉRICO

Utilizar presentación:

es-AR

Ejemplo:

1250.4

mostrar:

1.250,4

Internamente se puede mantener el formato necesario para backend.

Revisar especialmente:

- horómetro;
- kilometraje;
- costos si aplica;
- intervalos.

---

# COMA DECIMAL

El sistema se utilizará en un contexto donde es habitual ingresar:

1250,5

además de:

1250.5

Revisar la experiencia completa.

Backend/dominio debe normalizar de forma segura ambos formatos para horómetro cuando sea compatible con los inputs existentes.

NO convertir ambiguamente:

1.250

sin saber si significa:

mil doscientos cincuenta

o:

uno con veinticinco.

Definir reglas claras y tests.

---

# NO HACER

No:

- rediseñar la identidad visual;
- cambiar paleta;
- agregar dashboards innecesarios;
- convertir en SPA;
- introducir Vue Router;
- agregar dependencias grandes de UX;
- meter validaciones de negocio exclusivamente en Vue;
- eliminar validaciones backend;
- agregar confirmación SweetAlert para cada acción;
- utilizar modales para todo;
- hacer AJAX donde un POST tradicional sea suficiente;
- duplicar componentes;
- mezclar esta tarea con refactors arquitectónicos no relacionados.

---

# COMPONENTES REUTILIZABLES

Cuando varios flujos necesiten la misma experiencia:

crear componente/helper/composable compartido.

Ejemplos posibles:

UsageReadingInput.vue
UsageDelta.vue
HumanDate.vue
formatUsage.js
formatOrigin.js

Usar nombres acordes a la estructura actual.

NO duplicar:

delta horómetro
delta km
formato es-AR
warning retroceso

en tres páginas diferentes.

---

# FIRST MOBILE

Revisar especialmente:

- carga de lecturas;
- cierre OT;
- ficha equipo;
- dashboard;
- importaciones.

En móvil:

- inputs grandes;
- teclado numérico apropiado;
- acciones principales fáciles de alcanzar;
- evitar tablas horizontales para tareas operativas frecuentes cuando exista una representación card mejor.

No hace falta eliminar tablas desktop.

---

# TESTS UX/FUNCIONALES

Agregar tests al menos para:

1. Horómetro muestra lectura anterior.
2. Horómetro calcula delta.
3. Km calcula delta.
4. Retroceso muestra warning.
5. Lectura idéntica muestra "sin variación".
6. Equipo solo horas no muestra km.
7. Equipo solo km no muestra horas.
8. Botón guardar lecturas deshabilitado sin cambios.
9. Batch muestra progreso.
10. Resultado identifica equipo por código, no solamente ID.
11. Fecha común se aplica correctamente.
12. Plan muestra próximo valor calculado.
13. Cambio de servicio usa template default correspondiente.
14. Cierre OT muestra solamente métricas controladas.
15. Filtro sucursal usa catálogo, no input ID.
16. Importación no renderiza JSON técnico.
17. Códigos de origen se muestran en lenguaje humano.
18. Formato es-AR.
19. comma decimal según reglas definidas.
20. Responsive no pierde acciones principales.

Mantener tests backend para validaciones reales.

---

# AUDITORÍA ANTES DE PROGRAMAR

ANTES DE REALIZAR CAMBIOS:

recorrer todo el repositorio relacionado y entregarme una matriz:

FLUJO | PROBLEMA UX | IMPACTO | PROPUESTA | ARCHIVOS

Clasificar impacto:

CRÍTICO
ALTO
MEDIO
BAJO

No comenzar a cambiar código inmediatamente.

Primero corroborar específicamente los problemas mencionados en este prompt contra la implementación actual.

Si alguno ya fue resuelto:

NO implementarlo de nuevo.

Si encontrás problemas adicionales:

incluirlos.

---

# IMPLEMENTACIÓN

Después de entregar la auditoría:

implementar primero:

1. Lecturas rápidas.
2. Lecturas reutilizadas en otras pantallas.
3. Cierre OT.
4. Planes.
5. Equipos.
6. Dashboard/Servicios.
7. Importaciones.
8. Historiales.
9. Biblioteca.
10. Usuarios.

Si la tarea resulta demasiado grande:

NO hacer cambios parciales silenciosamente.

Dividirla explícitamente en fases coherentes manteniendo una misma arquitectura UX.

---

# CRITERIO GLOBAL DE ACEPTACIÓN

Una mejora queda aprobada solamente si puede responderse:

"Sí"

a:

¿Una persona que no conoce la implementación interna entiende qué hacer?

¿Puede hacerlo rápidamente?

¿El sistema utiliza información que ya conoce?

¿Evita errores previsibles?

¿Muestra el resultado de sus decisiones?

¿Mantiene su contexto?

¿No expone detalles técnicos innecesarios?

¿Es cómodo repetir esta tarea muchas veces durante una jornada?

---

# CASO DE REFERENCIA

Usar este criterio como referencia de calidad:

Un operador mira un horómetro físico:

1. Abre Lecturas rápidas.
2. Encuentra CAM-14.
3. Ve:
   Última: 1.250,4 h
4. Ingresa:
   1.258,4
5. El sistema muestra:
   +8,0 h
6. Presiona guardar.
7. Ve:
   ✓ CAM-14 actualizado a 1.258,4 h
8. Puede continuar inmediatamente con el siguiente equipo.

Objetivo:

menos de 10 segundos por unidad en condiciones normales.

Ese nivel de claridad debe trasladarse proporcionalmente a los demás flujos.

---

# VALIDACIÓN FINAL

Ejecutar:

npm test

npm run build

php vendor/bin/phpunit

y cualquier test específico agregado.

No introducir nuevos fallos.

---

# AL FINALIZAR

Entregar:

- auditoría UX inicial;
- problemas encontrados;
- prioridades;
- cambios realizados;
- componentes reutilizables creados;
- textos técnicos reemplazados;
- cálculos/preview incorporados;
- mejoras mobile;
- tests agregados;
- resultados de tests;
- resultado del build;
- problemas UX encontrados pero dejados para una fase posterior;
- deuda técnica detectada.

IMPORTANTE:

No medir el éxito por cantidad de código modificado.

Medirlo por reducción de:

- dudas;
- clics;
- errores;
- cálculos manuales;
- datos repetidos;
- tiempo necesario para completar una tarea.