# Bitácora — trabajo que no exige decisiones de la empresa

**Fecha:** 24/10/2026
**Rama:** `feat/telemetria-autotrac-atc`
**Alcance:** todo lo que quedó pendiente de la Fase 0 y que no depende de una respuesta de TSA.
**Estado:** cerrado. Quedan dos deudas registradas y dos piezas que sí dependen del cliente.

---

## 1. Conclusión primero

Se entró con cuatro frentes y se cerraron los cuatro. El más importante no era una tarea de
código: era **un diagnóstico equivocado que llevaba un rato dando vueltas**.

| Frente | Resultado |
|---|---|
| Los 4 tests rojos de URL | **Resuelto.** No eran un bug de producción: eran tests atados al entorno. |
| Documentos de viajes vencidos | **Resuelto.** Spec y bitácora ya describen el estado real. |
| Importación de `t11/t12/t13` | **Ya estaba hecha.** Se corrigió la documentación que decía lo contrario. |
| Acoplamiento de tablas de Viajes | **Resuelto.** Viajes ya no lee `equipos` ni `empleados`. |

---

## 2. El diagnóstico de las URLs

### 2.1 Qué se creía

`docs/ERRORES_CONOCIDOS.md` decía que el prefijo `mantenimiento` duplicado era "una
contradicción de convención que afecta a todo el sistema" y dejaba dos candidatos: quitar el
prefijo del código, o dejar de incluir `/mantenimiento` en el `baseURL` de producción.

### 2.2 Qué se verificó

Con una sonda contra el framework, el `baseURL` local y el `.htaccess`:

1. **CI4 4.7 resuelve la ruta a partir del path que le entrega Apache**, no relativo al
   `baseURL`. Con `baseURL` con subdirectorio, `GET /mantenimiento/equipos` matchea el grupo
   `mantenimiento` y `GET /equipos` da 404.
2. **La app se publica en `public_html/mantenimiento/`**, así que Apache le pasa a CI4 el path
   relativo a ese directorio: `/mantenimiento/equipos`. Por eso el segmento aparece dos
   veces en la URL pública y por eso **la URL con el prefijo doble es la que funciona**.
3. **La regresión del chatbot lo confirma de forma independiente**: el endpoint que responde
   es `/mantenimiento/mantenimiento/chatbot/conversaciones` y el simple da 404, porque la
   ruta vive en el grupo `mantenimiento/chatbot`.
4. **Los 32 redirects literales** (`redirect()->to('/mantenimiento/...')`) producen **la misma
   URL** que los `base_url('mantenimiento/...')`: CodeIgniter resuelve también contra el
   `baseURL` una ruta con barra inicial. No hay dos poblaciones que arreglar.

**Conclusión: no hay bug de producción.** El doble prefijo es la forma que tiene la URL
publica en el despliegue actual.

### 2.3 Lo que sí era un defecto

Los cuatro tests escribían el path absoluto que corresponde a la condición de CI
(`baseURL` en la raíz), pero el `.env` local fija el de producción (con subdirectorio). El
resultado de la suite **dependía de la máquina**.

**Resuelto.** Los cuatro asserts ahora comparan contra
`Tests\Support\Http\ApplicationUrl::path($ruta)`, que compone el path con el `baseURL`
vigente. Verificado en las dos condiciones: con el `.env` local y sin él. La suite queda
verde en cualquier máquina.

### 2.4 Lo que se dejó abierto, con la recomendación

Unificar la convención sigue siendo una decisión de alcance, no de la empresa. La opción 1
del documento (quitar el prefijo del código y de las rutas) es la única que deja **un**
prefijo en la URL pública sin tocar el despliegue. La opción 2, tal como estaba escrita,
**rompería las rutas agrupadas en producción**. Impacto medido: 211 llamadas a
`base_url('mantenimiento/...')` en 47 archivos, 32 redirects literales, el grupo de rutas,
las URLs del frontend y una regresión completa en `fasa_189`.

Además se registró que **las rutas agrupadas no tienen cobertura HTTP**: los tests de
feature solo piden rutas de primer nivel. Nada verificaba que `/mantenimiento/equipos`
resolviera, y el cliente de pruebas no reproduce el reescrito de Apache. Por eso la
regresión pudo volver varias veces.

---

## 3. Documentos de viajes que mentían

| Documento | Qué decía | Qué se hizo |
|---|---|---|
| `ESPECIFICACION_VIAJES_CIRCUITO_LIQUIDACION.md` | "Borrador para evaluación. No autoriza implementación", y §4.2 listaba diez piezas faltantes | Cabecera a "Fase 1 implementada", §4.1 con las 22 piezas reales, §4.2 con lo que queda de verdad, §18 con el registro de revisión y dos checklists |
| `BITACORA_IMPORTACION_VIAJES_HISTORICOS.md` | "El importador operativo todavía NO está implementado y ningún dato fue importado" | Nota de revisión al inicio, con el detalle de lo que se implementó después |

---

## 4. La afirmación que hubo que corregir

Al levantar el pendiente de "importar la cuenta corriente legacy (`t11`, `t12`, `t13`)" se
buscó el código antes de escribir nada, y la importación **ya estaba completa**: el paquete
declara las tres tablas en su contrato, el mapper las traduce, el store las persiste, el
read model de detalle las lee y el análisis de rentabilidad usa los otros haberes. Hay tres
suites que lo cubren.

La decisión D3 —migrar preservando, sin calcular saldos— está implementada tal como fue
tomada.

**Se corrigió en los cuatro lugares donde se había escrito lo contrario**, entre ellos la
bitácora de esta Fase 0 y `DECISIONES_PENDIENTES_FASE_0.md`. D3 queda cerrada y
implementada.

---

## 5. Viajes dejó de leer tablas de otros contextos

`CodeIgniterTripFleetLookup` consultaba `equipos`, `tipos_equipo` y `empleados` desde la
infraestructura de Viajes. Funcionaba, pero ataba el contexto al esquema de otros dos: un
cambio de columna los habría roto sin que ningún test lo señalara.

**Ahora:**

- `app/Application/Assets/Port/EquipmentFleetLookup.php` — responde por el equipo, por id y
  por patente.
- `app/Infrastructure/Assets/CodeIgniterEquipmentFleetLookup.php` — su implementación, con el
  `scope` de empresa y la baja lógica resueltos dentro.
- `app/Application/Employees/Port/EmployeeDirectoryLookup.php` — responde por el chofer.
- `app/Infrastructure/Employees/CodeIgniterEmployeeDirectoryLookup.php` — su implementación.
- `CodeIgniterTripFleetLookup` traduce el resultado al formato del caso de uso y no toca
  ninguna tabla ajena.
- Cableado en `app/Config/Services.php`, en `createTrip` y `addTripOtherEarning`.

Se eliminó el puerto `EquipmentByPlateLookup`, que se había escrito en la Fase 0: quedó
solapado con `EquipmentFleetLookup` y sobraba. Un solo puerto para la misma capacidad.

**12 pruebas nuevas** cubren los tres adaptadores, incluyendo que una unidad de otra empresa,
una dada de baja y una patente ajena devuelven `null`.

---

## 6. La deuda que quedó registrada

**El rol físico de la unidad se deduce del nombre del tipo de equipo**, con una expresión
regular sobre texto libre:

```php
preg_match('/acoplado|semirremolque|remolque|batea/', $tipo)
preg_match('/camion|tractor|chasis|cabina|utilitario|pickup/', $tipo)
```

Renombrar un tipo cambia la clasificación en silencio: el equipo deja de poder asignarse
como acoplado y el viaje no se cierra con esa asignación. Ahora la regla vive en el contexto
que es dueño del catálogo, que es donde corresponde, pero la forma definitiva es un atributo
explícito en `tipos_equipo`.

**No se hizo en este corte** porque implica una migración con carga de datos sobre las
unidades reales, y el padrón definitivo de flota todavía no está confirmado (D7). Queda
registrado en `docs/CONTRATOS_ENTRE_CONTEXTOS.md` §6.2.

---

## 7. Evidencia

```
Suite PHPUnit completa (WSL, PHP 8.5.4)   1478 pruebas, 5273 aserciones, 0 fallos, 44 omitidas
  Dominio + aplicacion de Viajes           88 pruebas, 268 aserciones, OK
  Base de datos + feature                  57 pruebas, 149 aserciones, OK
  Frontend de Viajes (vitest)              9 archivos, 28 pruebas, OK
  Adaptadores nuevos                       12 pruebas, 38 aserciones, OK
php -l de los archivos nuevos              sin errores
```

**Los 44 omitidos** son los que exigen el esquema MariaDB descartable `clientes_test_` en
`fasa_189`. No se ejecutaron porque este trabajo no toca la base: no se corrieron
migraciones, no se tocó `fasa_189` y no se desplegó nada.

---

## 8. Pendientes

| Pendiente | Quién lo cierra |
|---|---|
| Unificar la convención de URLs (opción 1 del punto 2.4) | Decisión técnica, con regresión completa en `fasa_189` antes de tocar producción. |
| Cobertura HTTP de las rutas agrupadas | Falta de código. Es lo que dejó volver la regresión del chatbot. |
| Atributo explícito de tractor/acoplado en `tipos_equipo` | Falta de código, con carga de datos. Depende de D7. |
| Aprobación y pago de liquidaciones (`viajes.liquidar`) | Falta de código, y depende de la respuesta del cliente sobre si la liquidación se paga por viaje o por período. |
| D1, D2, D4, D5, D6, D7 y D8 | Cliente. Las preguntas están en `DECISIONES_PENDIENTES_FASE_0.md` §4. |
