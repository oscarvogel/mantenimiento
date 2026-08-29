# Backup productivo en Ferozo compartido

Esta nota es parte de la operacion obligatoria de produccion para `oscarvogel/mantenimiento`.

## Restriccion conocida

El hosting productivo Ferozo es compartido y no dispone de SSH operativo para este proyecto. No se debe bloquear un deploy esperando poder ejecutar `mysqldump`, `php spark`, comandos shell ni acceder a phpMyAdmin.

Cuando haga falta un backup SQL verificable antes de desplegar, la estrategia soportada es un **script PHP efimero subido por FTPS**, siguiendo el mismo criterio operativo que `scripts/migrate-remote.php`.

## Regla obligatoria antes de un deploy con migraciones

1. No exigir SSH, terminal remota, phpMyAdmin ni `mysqldump`.
2. Usar un script PHP temporal, por ejemplo `scripts/backup-remote.php`, subido como `backup.php` solo durante la operacion.
3. El script debe leer la configuracion real de base de datos de la aplicacion productiva, sin hardcodear ni mostrar credenciales.
4. Debe estar protegido por un token independiente, por ejemplo `BACKUP_TOKEN`, enviado por header HTTP.
5. El dump debe descargarse directamente por HTTP como `application/sql` con `Content-Disposition: attachment`; no dejar un `.sql` publico permanente en el hosting.
6. El dump debe incluir estructura y datos suficientes para una restauracion completa: tablas, indices, claves, `AUTO_INCREMENT`, charset/collation, `NULL`, strings escapados y manejo de foreign keys.
7. Preferir streaming para evitar cargar toda la base en memoria.
8. Guardar el dump fuera del arbol Git y no versionarlo.
9. Verificar antes de desplegar: archivo no vacio, tamaño razonable, presencia de multiples `CREATE TABLE`, presencia de datos cuando corresponda y que no sea una respuesta HTML de error.
10. Calcular y registrar solo metadatos seguros: fecha, tamaño, SHA-256 y cantidad aproximada de tablas.
11. Eliminar inmediatamente `backup.php` por FTPS y comprobar que su URL responda 404.
12. Solo despues del backup validado se permite continuar con upload, migraciones y smoke tests productivos.

## Migraciones productivas

Las migraciones siguen el mecanismo ya documentado:

- subir temporalmente `scripts/migrate-remote.php` como `migrate.php`;
- consultar primero `?status=1`;
- ejecutar migraciones;
- eliminar `migrate.php` inmediatamente;
- comprobar que vuelva a responder 404.

No intentar reemplazar este flujo por `php spark migrate` en produccion porque Ferozo no ofrece SSH para este proyecto.

## Proteccion del checkout local

Un deploy productivo no autoriza a destruir trabajo local divergente. Si el checkout local contiene commits propios o cambios no integrados:

- no usar `git reset --hard`;
- no descartar commits locales;
- no forzar el checkout actual a `origin/main`;
- preparar el release desde un worktree temporal, detached checkout u otro arbol limpio basado en el SHA remoto que realmente se desea desplegar.

## Criterio de bloqueo

La ausencia de un dump entregado manualmente por el usuario **no es un bloqueo por si sola**. Si existe acceso FTPS y la aplicacion puede abrir su propia base productiva, se debe intentar primero el mecanismo PHP efimero documentado aqui.

Solo informar bloqueo si ese mecanismo no puede ejecutarse de forma segura o verificable.

## Seguridad

Nunca versionar ni imprimir:

- `.env`;
- credenciales FTPS;
- usuario/password de base de datos;
- tokens `BACKUP_TOKEN` o `MIGRATE_TOKEN`;
- dumps SQL productivos;
- contenido sensible de la base.

Al finalizar un deploy productivo, `backup.php` y `migrate.php` deben quedar eliminados del servidor.