# Plan: notificación interna importante al rectificar una OT cerrada

1. Agregar el puerto `NotifiableEventPublisher` y ampliar el contrato del sincronizador para devolver un evento opcional.
2. Hacer que `CodeIgniterReadingCorrectionRepository` construya el evento `orden.rectificada` después de actualizar la OT y su historial.
3. Delegar la publicación desde `CorrectReadingHandler` y conectar el publicador central en `Services`.
4. Incluir `orden.rectificada` en la cola de email empresarial para conservar el comportamiento previo.
5. Agregar regresión y ejecutar pruebas focalizadas y completas.
6. Publicar la rama y abrir un PR vinculado al issue #216.
