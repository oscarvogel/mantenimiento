# Bitácora: cobertura HTTP de las rutas (24/10/2026)

## Qué se pidió

Cerrar el hueco de cobertura HTTP que dejó volver la regresión del chatbot
varias veces: las rutas agrupadas bajo `mantenimiento/` no tenían ningún test
de feature, y el checkout local no reproduce el reescrito de Apache.

## Por qué ese hueco era peligroso

Las 39 rutas GET agrupadas son el grueso de la aplicación y ninguna estaba
cubierta: los tests de feature del repo solo pedían rutas de primer nivel
(`viajes`, `superadmin/...`). El 404 del chatbot de agosto de 2026
(`docs/ERRORES_CONOCIDOS.md`) entró por una URL mal construida y ningún test
lo señaló: la ruta existía, pero nadie preguntaba si la URL que construía la app
respondía.

La causa de fondo es que **el fallo visible en el navegador no está donde el
código parece estar**: la URL se arma en el backend, el prefijo lo agrega
Apache y el consumo puede estar en el frontend. Un test que solo mira "la ruta
existe" no alcanza.
## Qué se agregó

| Archivo | Cambio |
|---|---|
| `tests/feature/RouteResolutionTest.php` | Nuevo. 7 tests de resolución de rutas. |
| `tests/_support/Http/ApplicationUrl.php` | Nuevo método `routePath()`: emula el recorte del directorio físico que hace Apache y devuelve el path que ve el router. |

`ApplicationUrl::routePath()` es la pieza que hace que la suite no dependa de
la máquina: con `app.baseURL` con subdirectorio y sin él, el resultado es el
mismo. Verificado en las dos condiciones.

## Los siete tests

| # | Test | Qué garantiza | Magnitud revisada |
|---|---|---|---|
| 1 | `testTodaRutaAgrupadaDeclaradaResuelve` | Toda ruta GET agrupada pedida por HTTP no tira `PageNotFoundException`. | 39 rutas |
| 2 | `testLaUrlGeneradaParaCadaRutaVuelveALaMismaRuta` | `base_url($ruta)` vuelve a la ruta después del recorte de Apache. | 214 patrones (87 GET + 151 POST) |
| 3 | `testTodaUrlLiteralQueConstruyeLaAppCorrespondeAUnaRuta` | Ninguna URL literal que la app construye apunta a la nada. | 109 literales |
| 4 | `testTodaEntradaDelCatalogoDeModulosExiste` | Toda entrada que el portal puede publicar resuelve como ruta. | 11 entradas |
| 5 | `testTodoEnlaceDeLaAppApuntaAUnaRutaGet` | Todo `<a href>` apunta a una ruta **GET** declarada. | 11 enlaces |
| 6 | `testTodoFormularioDeLaAppApuntaAUnaRutaDeSuMetodo` | Todo `<form action>` apunta a una ruta del **método que declara**. | 42 formularios |
| 7 | `testTodoPathQueConstruyeElFrontendPorSuCuentaExiste` | Los paths que el frontend arma sin que el backend se los pase existen como ruta. | 1 path (`mantenimiento/chatbot`, en 2 archivos) |

Los tests 5 y 6 son los que detectan un **rename** de ruta: el enlace sigue
apuntando al nombre viejo, que ya no responde, y antes eso rompía en
producción sin que nadie lo viera.

El test 7 cubre el hueco que quedaba del lado del frontend: casi todas las
URLs del frontend llegan en un payload del backend (y por eso las cubre el
test 3), pero el widget de chat construye la suya en el componente.

## La regla de coincidencia

Una URL literal puede ser tres cosas distintas, y confundirlas era la razón
por la que la primera versión del test no detectaba nada:

1. **Ruta exacta**: `mantenimiento/equipos` tiene que matchear un patrón
   declarado.
2. **Familia**: la URL termina en barra y la app la completa con los segmentos
   que faltan (`mantenimiento/equipos/` + id). Tiene que existir un patrón
   que empiece por ese prefijo.
3. **Familia completada por el llamador**: la app publica una base y quien
   llama le agrega el identificador. Hay **una sola** en el repo:
   `notificaciones/leer`, que el frontend completa con el id de la
   notificación. Está declarada en `FAMILIAS_COMPLETADAS` con un id de
   ejemplo, y el test verifica que esa familia exista: si se renombra la ruta
   `notificaciones/leer/(:num)`, el test falla.

La regla exacta ("sin barra tiene que responder a una ruta exacta") es la que
permite detectar renombres: con una regla de prefijo la ruta renombrada se
camuflaba detrás de su hermana parametrizada.

## Evidencia: los tests detectan las regresiones

Se rompieron cuatro cosas a propósito, una por una, y se restauró todo
después. Las cuatro fueron detectadas:

| Caso | Qué se rompió | Qué falló |
|---|---|---|
| A | Rename de la ruta GET `mantenimiento/equipos` | Test 5: `La app enlaza a "mantenimiento/equipos" pero ninguna ruta GET declarada lo responde.` |
| B | Typo en una URL literal del backend (`mantenimiento/chatbot/auditoria` → `chatbotTypo`) | Test 3: `La app construye la URL "mantenimiento/chatbotTypo/auditoria" pero ninguna ruta declarada la responde.` |
| C | Rename de la familia `notificaciones/leer/(:num)` | Test 3: `La app construye la URL "notificaciones/leer" y la completa con un id, pero la familia no existe.` |
| D | Typo en el path que construye el frontend (`mantenimiento/chatbot`) | Test 7: `El frontend construye el path "mantenimiento/chatbotTypo" pero ninguna ruta declarada lo responde.` |

El caso A es el que la primera versión **no** detectaba: con una regla de
prefijo la ruta renombrada se escondía detrás de `mantenimiento/equipos/([0-9]+)`.
Por eso el test se reescribió para separar exacto, familia y familia
completada.

## Alcance y lo que NO cubre

Conviene dejarlo escrito, porque un test que se cree más fuerte de lo que es
es peor que no tenerlo:

- **Solo se barren las rutas GET por HTTP.** Los POST se cubren de forma
  estructural (existen y los formularios apuntan a ellas), no con una
  petición real: `FeatureTestTrait` de CodeIgniter 4.7 no tiene
  `withoutCsrf()`.
- **No se cubren los permisos.** Un enlace puede apuntar a una ruta correcta y
  el usuario no tener permiso: eso es un 403, no un 404, y es otro problema.
- **Las rutas sin enlace en las vistas no están cubiertas contra un rename.**
  Si se renombra una ruta que ninguna vista referencia con un `href` y que
  ningún formulario apunta, ningún test lo nota. Pasa con las rutas que solo
  se alcanzan por navegación interna.
- **No se cubren los templates del frontend** que arman URLs a partir de un
  payload. Esos datos los produce el backend, y el backend es lo que se
  valida en el test 3.
- **Los archivos estáticos quedan fuera**: `assets/...` y `manifest.webmanifest`
  los sirve el front controller, no son rutas.
- Quedan fuera del barrido `internal/`, `cron/` (disparan tareas) y
  `mantenimiento/publico/` (responden por contenido, no por contrato de ruta).

## Estado de la suite

```text
Suite PHPUnit completa (WSL, PHP 8.5.4)   1497 pruebas, 5702 aserciones, 0 fallos, 44 omitidas
Solo RouteResolutionTest                    7 pruebas, 391 aserciones, 0 fallos
```

Las 44 omitidas son las que exigen MariaDB `clientes_test_` en `fasa_189`.

Verificado en las dos condiciones de `app.baseURL`:

| Condición | Resultado |
|---|---|
| Con `.env` local (baseURL con subdirectorio) | 7/7, 391 aserciones |
| Sin `.env` (condición de CI) | 7/7, 391 aserciones |

## Publicación

- **PR:** [#492](https://github.com/oscarvogel/mantenimiento/pull/492) — mergeado a
  `main` el 24/10/2026 con el commit de merge `f8d91add`.
- **CI:** `test` en verde en el PR y en `main` (lint de `app/` y `tests/`,
  PHPUnit, tests de frontend y `verify:build-sync`).
- **Staging:** **no se desplegó**, por dos razones concretas:
  1. El cambio es de pruebas y documentación: no toca el runtime. La imagen de
     staging se construye con `composer install --no-dev`, así que PHPUnit no
     corre dentro del contenedor y un rebuild no agregaría verificación
     funcional — la validación real es la CI.
  2. El acceso por SSH a `fasa_189` no estaba disponible desde la PC donde se
     hizo el merge (la red local había cambiado y no había ruta a
     `192.168.0.189`). El deploy de staging exige SSH porque el PAT de la API
     de Coolify se crea dentro del contenedor `coolify` y la API escucha en
     `127.0.0.1:8080` del host.

  Staging sigue sirviendo el deploy anterior (`93f1e38e`), que ya contiene
  prácticamente toda la rama: de los 19 commits de este PR, los dos últimos
  son precisamente los de esta cobertura.

- **Producción:** no se tocó. Este merge no la despliega: en este repositorio
  no hay workflow de deploy y la publicación a Ferozo es manual, por FTPS.

## Pendientes

- La **unificación de la convención de URLs** sigue abierta y es una decisión
  de alcance, no de empresa. La opción 1 de `docs/ERRORES_CONOCIDOS.md`
  (quitar el prefijo `mantenimiento` del código y de las rutas) tiene ahora
  una red de seguridad: con los siete tests, un cambio de esa magnitud se ve
  en la suite antes de llegar a `fasa_189`.
- Sigue faltando cobertura de **permisos por ruta** (403) y de **respuesta
  real de los POST** con CSRF válido.
- El barrido por HTTP se puede extender a `fasa_189` cuando se decida
  levantar un entorno de prueba con base real: el entorno canónico de
  pruebas es el Docker remoto de `fasa_189`, no el PHP local.
