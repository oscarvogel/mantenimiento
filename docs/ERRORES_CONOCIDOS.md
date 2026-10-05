# Errores conocidos

Este documento registra fallos reproducibles observados en operación. No
reemplaza un issue ni autoriza por sí mismo cambios de código o despliegues.

## Chatbot: 404 al iniciar una conversación

**Estado:** corregido en código, pendiente de despliegue.

**Evidencia recibida:** 25 de agosto de 2026, desde el dashboard:

```text
chatbot/conversaciones:1 Failed to load resource: the server responded with a status of 404 ()
```

El asistente muestra simultáneamente:

```text
No pude iniciar la conversación. Reintentá más tarde.
```

### Diagnóstico

- `frontend/src/pages/operations/components/ChatWidget.vue` envía un `POST`
  a `/mantenimiento/chatbot/conversaciones`.
- `app/Config/Routes.php` registra el endpoint dentro del grupo interno
  `mantenimiento/chatbot`.
- En el despliegue plano, la aplicación está publicada bajo
  `/mantenimiento/`, pero sus rutas internas ya comienzan con
  `mantenimiento/`. Por lo tanto, la URL correcta del endpoint es
  `/mantenimiento/mantenimiento/chatbot/conversaciones`.
- La petición que actualmente emite el widget omite el segundo
  `mantenimiento`, no coincide con la ruta registrada y responde 404.

Este mismo problema de prefijo `/mantenimiento` versus
`/mantenimiento/mantenimiento` ya tuvo que corregirse varias veces y debe
considerarse una regresión recurrente de deploy/base URL. No se resuelve
reintentando, recargando la página ni cambiando el proveedor de IA.

### Alcance del problema

El fallo afecta el inicio de una conversación desde el widget. Las peticiones
posteriores de historial y mensajes dependen de que exista un `conversationId`,
por lo que no debe interpretarse que el chatbot está operativo solo porque el
panel se abre correctamente.

### Corrección aplicada

`ChatWidget.vue` centraliza el prefijo en
`/mantenimiento/mantenimiento/chatbot` y lo utiliza para iniciar conversaciones,
recuperar historial, enviar mensajes y confirmar acciones. Se agregó una
regresión de Vitest que verifica que el inicio de conversación no vuelva a
emitir la ruta corta que responde 404.

### Ruido de consola no relacionado

También puede aparecer:

```text
Unchecked runtime.lastError: The message port closed before a response was received.
```

Ese mensaje pertenece al canal de mensajería de una extensión del navegador
cuando un puerto se cierra antes de responder. No identifica una excepción de
CodeIgniter ni explica el 404 del chatbot. Debe investigarse por separado
solamente si continúa después de probar sin extensiones o si aparece junto con
un fallo funcional distinto.

### Criterio para cerrar el error

La corrección deberá conservar explícitamente el prefijo doble requerido por
este despliegue, o construir la URL mediante la configuración/base URL de la
aplicación sin hardcodear otro dominio. Se considerará resuelto cuando, con
sesión y CSRF válidos:

1. `POST /mantenimiento/mantenimiento/chatbot/conversaciones` responda `2xx`.
2. Se devuelva un identificador de conversación válido.
3. El widget deje de mostrar el mensaje rojo de error.
4. Se pueda enviar el primer mensaje sin un `404` en la consola.

Este registro conserva el diagnóstico; la corrección está en el checkout actual
y todavía no fue desplegada.

## Estado del checkout local 2026-09-11

La ruta corregida del chatbot permanece implementada y cubierta por Vitest. La
aceptación definitiva requiere un smoke autenticado con CSRF y proveedor de IA
configurados en staging; este checkout no despliega automáticamente.

## Convención de URLs: prefijo `mantenimiento` duplicado

**Estado:** abierto, requiere decisión. No es un bug de una línea: es una
contradicción de convención que afecta a todo el sistema.

### Evidencia

El código construye las URLs anteponiendo el segmento `mantenimiento` a la ruta
y después llamando a `base_url()`:

```php
// app/Presentation/PreventivePlansPayload.php:15
$base = base_url('mantenimiento/planes');
```

Eso es correcto **únicamente si `app.baseURL` no incluye ya `/mantenimiento`**.
Pero la URL canónica del sistema es
`https://vogelconsultoria.com.ar/mantenimiento/` y el `.env` de esta máquina la
fija así:

```dotenv
app.baseURL = 'https://vogelconsultoria.com.ar/mantenimiento/'
```

Con esa configuración, `base_url('mantenimiento/planes')` produce
`https://vogelconsultoria.com.ar/mantenimiento/mantenimiento/planes`.

Magnitud medida sobre el checkout actual:

- **173** llamadas a `base_url('mantenimiento/...')` en `app/`, concentradas en
  los payload de presentación (`OperationsPayload.php` con 42,
  `WorkOrderDocumentImports.php` con 15, `Employees.php` con 10).
- **25** aserciones de path con `/mantenimiento/` en 8 archivos de test.

### Cómo se detectó

Al correr la suite PHPUnit en local fallan dos tests:

```text
QrTechnicalFailureReporterTest  esperado '/mantenimiento/equipos'
                                real     '/mantenimiento/mantenimiento/equipos'
PreventivePlansPayloadTest       esperado '/mantenimiento/planes/12/editar'
                                real     '/mantenimiento/mantenimiento/planes/12/editar'
```

La causa **no es un defecto del código**: el `.env` local sobreescribe el
`app.baseURL` que `phpunit.xml` define como `http://example.com/`. Se comprobó
moviendo el `.env` a un lado y ejecutando los dos tests: ambos pasan. Es un
artefacto del entorno local, y por eso la línea base honesta de la suite en esta
máquina es verde, no "2 fallos preexistentes".

### Por qué importa igual

`docs/ERRORES_CONOCIDOS.md` ya registra el 404 del chatbot como **regresión
recurrente de deploy/base URL**, y la corrección aplicada fue hardcodear el
prefijo doble en `ChatWidget.vue`. Eso tapó el síntoma en ese archivo y dejó sin
resolver la causa: hoy la app tiene dos convenciones conviviendo, y cuál es la
correcta depende de un `.env` que difiere entre local, CI y producción.

### Decisión pendiente

Definir una sola convención y aplicarla. Las dos candidatas:

1. **`base_url()` sin prefijo** (`base_url('planes')`), asumiendo que `app.baseURL`
   ya incluye el segmento. Es la opción que hace que los 25 paths de los tests
   sean correctos y elimina el prefijo doble. Impacto: 173 call sites.
2. **Mantener el prefijo en el código** y fijar `app.baseURL` sin `/mantenimiento`
   en todos los entornos, incluidos producción y CI. Impacto: corregir el `.env`
   de producción y los tests que asertan paths, y unificar el widget del chatbot.

Mientras no se decida, cualquier test que aserte un path y cualquier verificación
HTTP contra `fasa_189` puede dar resultados contradictorios según el entorno.

