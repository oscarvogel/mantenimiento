# Invariantes operativas de Ferozo

> **Separación canónica:** `staging = fasa_189 / Docker / Coolify`; `producción = Ferozo / FTPS / sin CLI`.
>
> Ferozo no tiene SSH, no tiene `php spark` y no tiene CLI. No usar `fasa_195`
> para este proyecto salvo instrucción explícita; el staging canónico es
> `fasa_189`.

Estas reglas son decisiones operativas ya confirmadas del proyecto `mantenimiento`. No requieren una nueva autorizacion del usuario en cada deploy. Solo se debe detener el proceso si aparece una incompatibilidad tecnica nueva, evidencia de que la regla dejo de ser valida, o un riesgo real para datos/produccion.

## 1. Destino de produccion

La produccion publica es:

`https://vogelconsultoria.com.ar/mantenimiento/`

El hosting es Ferozo y el despliegue productivo se realiza por FTPS. Ferozo no dispone de SSH operativo para este proyecto.

El staging canónico es `fasa_189`, administrado con Docker/Coolify. Nunca debe
confundirse con producción Ferozo.

## 2. Rutas del chatbot en Ferozo

Por la estructura productiva actual de Ferozo, las rutas publicas del chatbot deben resolver bajo:

`/mantenimiento/mantenimiento/chatbot/...`

Si el codigo base o una rama trae rutas bajo:

`/mantenimiento/chatbot/...`

el proceso de release debe incorporar automaticamente la adaptacion productiva equivalente ya conocida. Esto NO es un motivo para pedir autorizacion nuevamente.

Existe como antecedente el fix preservado en el commit local `a1004f5`. Ese SHA es una referencia historica del arreglo, no una obligacion de cherry-pick ciego: antes de aplicar el cambio se debe verificar que siga siendo compatible con el `main` actual y reproducir el cambio equivalente si el codigo evoluciono.

Solo detenerse por este punto si el arreglo ya no puede aplicarse de forma segura o si la estructura de rutas de produccion cambio realmente.

## 3. Backup de base productiva

No asumir acceso a panel, phpMyAdmin ni SSH. En este hosting compartido el backup productivo debe poder realizarse mediante un script PHP temporal/controlado u otro mecanismo HTTP/FTPS verificable ya soportado por el proyecto.

La falta de SSH o de acceso a phpMyAdmin NO es, por si sola, un bloqueo ni debe generar una nueva consulta al usuario.

El procedimiento debe:

1. generar el dump mediante el mecanismo disponible;
2. descargarlo/verificarlo fuera del webroot;
3. validar que no este vacio y registrar tamano/hash cuando corresponda;
4. retirar inmediatamente cualquier helper publico temporal (`backup.php`, `migrate.php`, etc.);
5. comprobar que esos helpers vuelvan a responder 404 y que `.env` siga protegido.

Solo detenerse si no existe ningun mecanismo seguro/verificable para producir el backup.

## 4. Migraciones en produccion

No indicar `php spark migrate` en Ferozo: no hay shell productivo disponible.

Las migraciones se ejecutan mediante el helper HTTP temporal documentado (`scripts/migrate-remote.php`, publicado momentaneamente como `migrate.php`) y protegido por `MIGRATE_TOKEN`, o mediante el mecanismo productivo equivalente vigente.

Al finalizar, `migrate.php` debe eliminarse y verificarse que responda 404.

## 5. Secretos y configuracion

Nunca reemplazar ni versionar el `.env` productivo con uno local o de staging. Preservar las credenciales y claves ya instaladas, incluida la API key de MiniMax.

No mostrar secretos en logs ni reportes.

## 6. Regla para agentes/Codex

Ante un deploy productivo, estas invariantes se consideran autorizaciones permanentes del procedimiento ya acordado. No volver a pedir confirmacion por:

- usar FTPS en Ferozo;
- no disponer de SSH;
- generar backup por script PHP controlado;
- ejecutar migraciones mediante helper HTTP temporal;
- adaptar las rutas del chatbot a `/mantenimiento/mantenimiento/chatbot/...` cuando el release lo requiera.

Se debe informar lo realizado y sus verificaciones, pero no frenar esperando una autorizacion repetida sobre estas decisiones ya tomadas.
