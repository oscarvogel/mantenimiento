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
