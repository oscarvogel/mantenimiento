# Bitácora 2026-10-08 — Deploy quirúrgico PR #466 (persistencia de lectura con foto/IA)

Registro del hotfix desplegado en **producción Ferozo** el 2026-10-08: qué se
subió, con qué verificación y qué queda pendiente. No sustituye al issue ni al
PR.

## Qué fallaba

La recepción de foto por QR público ya funcionaba y MiniMax ya devolvía el
análisis, pero **persistir** la lectura fallaba dentro de la transacción. Como
los `insert()` de CodeIgniter no lanzan excepción cuando la sentencia no
tiene efecto, el flujo seguía adelante creyendo que había guardado y terminaba
en un error genérico, sin decir qué etapa había fallado.

## Qué se desplegó

Un único archivo, sin migraciones, sin frontend, sin assets y sin tocar `.env`:

`app/Controllers/PublicEquipmentReadings.php`

| | |
|---|---|
| PR | #466 |
| TARGET_SHA | `f8a6f9937bff63078e1deee9c81c2d7914192681` |
| BASE_SHA | `c18b533c7867bd522e9379e74b5525c4c463a83e` |
| CI | run `37818210983` — `Issue 15 check`, completed / success |
| Archivos subidos | 1 |

Cambios del parche, todos dentro del bloque transaccional de `store()`:

1. `observacion_ia` se recorta a 255 caracteres con `mb_substr` antes de
   insertar. Es la causa raíz más probable: una observación larga de la IA
   reventaba el insert de `lecturas_equipo_evidencias` por longitud de columna.
2. Cada `insert`/`update` ahora **captura su valor de retorno** y, si no es
   `true`, lee `$database->error()`, escribe un log específico y lanza
   `DomainException`: lectura, evidencia, actualización de equipo y auditoría QR.
3. Los mensajes de log distinguen cada etapa, que es lo que permite el paso 9.

Los commits del rango son dos (`5d3f21e3` fix + `f8a6f993` test). El rango
tocaba 2 archivos: el controlador y su test. Se desplegó solo el controlador.

## Verificación hecha

### Precondiciones

- `git merge-base --is-ancestor BASE TARGET` → exit 0.
- El remoto **coincidió byte a byte con BASE_SHA** antes de subir
  (`40cb82a0…`, 32466 b, LF, sin BOM). No había cambios desconocidos en
  producción, así que no hizo falta frenar.
- CI verde **y** con `headSha` igual al TARGET_SHA.

### Backup

`.deploy/backup-pre-f8a6f993/PublicEquipmentReadings.php` — copia del estado
previo verificada por hash. Sirve de reversión directa si el hotfix se comportara
peor que lo que arregla.

### Upload y hash

- Bytes extraídos con `git show TARGET_SHA:<path>` capturados en binario. **No**
  se usó el working tree: el árbol local tiene cambios sin commitear en assets y
  docs, y `app/` está limpio, pero el deploy tiene que ser reproducible desde el
  commit.
- Subida por FTPS al path chroot-relative `/app/Controllers/…`, que en el
  hosting es `public_html/mantenimiento/app/Controllers/…`.
- Relectura remota posterior: `HASH_VERIFICATION=OK`. Remoto = 34828 b,
  `6a9ceac0…`, idéntico al blob del TARGET_SHA.

### Smoke (solo GET)

- `/login` → **HTTP 200**.
- Ruta pública con token inválido → **HTTP 200 controlado**, renderizando
  "Acceso no disponible — El acceso QR no es válido". Sin 500 y sin firmas de
  error fatal. Esto prueba que la clase carga, la ruta resuelve y el camino de
  `DomainException` responde.
- No se probó MiniMax artificialmente ni se hice POST de lectura.

Ojo con el prefijo: en producción la ruta pública resuelve en
`/mantenimiento/mantenimiento/publico/equipo/<token>/lectura`. Con el prefijo
simple devuelve 404 y parece que el deploy falló.

### Logs

`writable/logs/log-2026-10-08.log`: 1 línea, **0 errores**, ninguno de los
marcadores de etapa disparado. El deploy no introdujo fallos por sí mismo.

## Bugs y trampas encontrados durante el deploy

1. **`SIZE` en ASCII contra ProFTPD.** `SIZE` devuelve `550 SIZE not allowed in
   ASCII mode`, y `550` es el mismo código que "no such file". Un helper que lo
   mapee a "ausente" hace que *todos* los archivos parezcan no existir. Hay que
   forzar `TYPE I` antes de cada `SIZE`. Ya estaba resuelto en
   `.deploy/ferozo_op.py`.
2. **El `inventory` completo no es reanudable** contra Ferozo: corta el canal a
   mitad de camino. Para una operación puntual conviene un operador con
   reconexión por comando.
3. **Drift de fin de línea.** Hay precedentede archivos con CRLF subidos desde
   Windows. La comparación previa se hizo con tolerancia a EOL además del hash
   exacto, para no frenar por un detalle de plataforma. En este caso no hizo
   falta: remoto y blob eran idénticos en bytes.

## Pendientes

1. **Prueba funcional con teléfono (sin hacer).** Falta la evidencia real del
   caso de uso; el smoke no alcanza. Oscar tiene que abrir el enlace público
   del camión demo, sacar foto, confirmar el kilometraje y registrar.
2. ** mergear el PR #466 a `main`.** Lo desplegado es el commit de la rama
   `fix/issue-453-production-persist-evidence`; `main` sigue en `c18b533c`.
   Mientras no se mergee, producción corre un commit que `main` no contiene y un
   redeploy routine desde `main` **revertiria el hotfix**.
3. **Si la prueba falla**, no tocar producción: correr
   `.deploy/_logcheck_466.py` y reportar `STAGE_FAILED`, `DB_ERROR_CODE` y
   `DB_ERROR_MESSAGE` sanitizado.
4. **No reintegrar el drift de assets.** El working tree tiene assets y docs sin
   commitear que son de otro trabajo; no tiene relación con este deploy.