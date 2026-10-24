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

**Estado:** cerrado como diagnóstico el 24/10/2026. **No es un bug de producción.**
Lo que queda abierto es una decisión de alcance, con el mecanismo medido abajo.

### Evidencia

El código construye las URLs anteponiendo el segmento `mantenimiento` a la ruta
y después llamando a `base_url()`:

```php
// app/Presentation/PreventivePlansPayload.php:15
$base = base_url('mantenimiento/planes');
```

Eso duplica el segmento **únicamente si `app.baseURL` ya incluye `/mantenimiento`**.
Y lo incluye: en Ferozo es obligatorio, porque la autodeteccion desde
`SCRIPT_NAME` devuelve la raiz del dominio en vez del subdirectorio
(`docs/DEPLOY_FEROZO.md` §15).

Con esa configuracion, `base_url('mantenimiento/planes')` produce
`https://vogelconsultoria.com.ar/mantenimiento/mantenimiento/planes`.

Magnitud medida sobre el checkout actual:

- **211** llamadas a `base_url('mantenimiento/...')` en `app/`, en 47 archivos,
  concentradas en los payload de presentacion (`OperationsPayload.php` con 42,
  `WorkOrderDocumentImports.php` con 15, `Employees.php` con 10).
- **32** redirects literales a `/mantenimiento/...` en 10 controladores.

### Por que la URL duplicada es la que funciona

La app se publica en `public_html/mantenimiento/` (estructura plana). Apache le
entrega a CodeIgniter el path **relativo** a ese directorio, asi que la ruta que
el router ve vuelve a llevar el prefijo:

```text
URL pedida   https://vogelconsultoria.com.ar/mantenimiento/mantenimiento/equipos
PATH_INFO    /mantenimiento/equipos
ruta         grupo 'mantenimiento' -> equipos    ✓
```

La prueba independiente esta en la regresion del chatbot: la URL que responde es
`/mantenimiento/mantenimiento/chatbot/conversaciones` y la simple da 404, porque
la ruta vive en el grupo `mantenimiento/chatbot`.

Verificado ademas en el checkout, con `app.baseURL` con subdirectorio:

| Peticion | routePath | Resultado |
|---|---|---|
| `/mantenimiento/equipos` | `mantenimiento/equipos` | resuelve |
| `/equipos` | `equipos` | 404 |
| `/mantenimiento/mantenimiento/equipos` | `mantenimiento/mantenimiento/equipos` | 404 |

La tercera fila no se rompe en produccion: el cliente de pruebas no reproduce
el reescrito de Apache, que si recorta el directorio fisico.

### Los redirects literales no son una segunda poblacion rota

Los 32 `redirect()->to('/mantenimiento/...')` producen **la misma URL** que los
`base_url('mantenimiento/...')`: CodeIgniter resuelve tambien contra el
`baseURL` una ruta con barra inicial.

```text
redirect()->to('/mantenimiento/equipos')  -> https://vogelconsultoria.com.ar/mantenimiento/mantenimiento/equipos
redirect()->to('mantenimiento/equipos')    -> https://vogelconsultoria.com.ar/mantenimiento/mantenimiento/equipos
base_url('mantenimiento/equipos')          -> https://vogelconsultoria.com.ar/mantenimiento/mantenimiento/equipos
```

No hay dos poblaciones que arreglar: hay una sola, y funciona.

### Como se detecto

Al correr la suite PHPUnit en local fallan cuatro tests:

```text
HomeRedirectTest              esperado '/inicio'
                              real     '/mantenimiento/inicio'
LoginRedirectTest             esperado '/inicio'
                              real     '/mantenimiento/inicio'
QrTechnicalFailureReporterTest  esperado '/mantenimiento/equipos'
                              real     '/mantenimiento/mantenimiento/equipos'
PreventivePlansPayloadTest     esperado '/mantenimiento/planes/12/editar'
                              real     '/mantenimiento/mantenimiento/planes/12/editar'
```

La causa **no es un defecto del codigo**: el `.env` local sobreescribe el
`app.baseURL` que `phpunit.xml` define como `http://example.com/`. Los cuatro
tests escribian el path absoluto que corresponde a la condicion de CI, asi que
el resultado de la suite dependia de la maquina.

**Resuelto el 24/10/2026.** Los cuatro asserts ahora comparan contra
`Tests\Support\Http\ApplicationUrl::path($ruta)`, que compone el path con el
`baseURL` vigente. Verificado en las dos condiciones: con el `.env` local
(baseURL con subdirectorio) y sin el (baseURL de CI). Los cuatro pasan en las
dos, y la suite queda verde en cualquier maquina.

### Por que importa igual

`docs/ERRORES_CONOCIDOS.md` ya registra el 404 del chatbot como **regresion
recurrente de deploy/base URL**, y la correccion aplicada fue hardcodear el
prefijo doble en `ChatWidget.vue`. Eso tapo el sintoma en ese archivo y dejo sin
resolver la causa: hoy la app tiene dos convenciones conviviendo, y cual es la
correcta depende de un `.env` que difiere entre local, CI y produccion.

Ademas, **las rutas agrupadas no tienen cobertura HTTP**: los tests de feature
del repo solo piden rutas de primer nivel (`viajes`, `superadmin/...`). Nada
verificaba que `/mantenimiento/equipos` resolviera, y el cliente de pruebas no
reproduce el reescrito de Apache. Por eso la regresion pudo volver varias veces.

### Decision pendiente

Definir una sola convencion y aplicarla. Las dos candidatas, con lo que se sabe
hoy:

1. **`base_url()` sin prefijo** (`base_url('planes')`) y quitar el grupo
   `mantenimiento` de las rutas, de forma que el prefijo lo ponga solo el
   `baseURL`. Es la unica que deja **un** prefijo en la URL publica sin tocar el
   despliegue. Impacto: 211 call sites, 32 redirects literales, el grupo de
   rutas, las URLs del frontend y una regresion completa en staging. No es un
   cambio trivial y ya rompio el circuito mas de una vez.
2. **Mantener el prefijo en el codigo** y fijar `app.baseURL` sin
   `/mantenimiento` en todos los entornos. **Con la topologia de despliegue
   actual esto rompe las rutas agrupadas**: Apache recortaria el directorio
   fisico y el router veria `equipos` en vez de `mantenimiento/equipos`. Solo
   seria viable si la app pasa a servirse desde la raiz del webroot, lo que
   ademas choca con el directorio fisico `mantenimiento` que ya causo el 403
   documentado en el `.htaccess`.

La opcion 1 es la recommendada, pero necesita una regresion completa en
`fasa_189` antes de tocar produccion. Mientras no se decida, la opcion minima
—que ya se aplico— es que ningun test dependa del entorno para pasar.


