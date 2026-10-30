# Diseño: importar localidades oficiales para Viajes

## Intención

Poblar el buscador de origen y destino de los tramos y permitir actualizar el catálogo sin editar la base manualmente. Conservar códigos y coordenadas oficiales para distinguir nombres repetidos y habilitar, más adelante, estimaciones de distancia vial.

## Estado actual

La migración `2026-10-16-120000_CreateGeographicCatalogs` crea países, provincias, localidades y equivalencias, pero solo inserta Argentina, Brasil, Chile y Uruguay. Las localidades actuales solo guardan nombre y provincia; la búsqueda remota ya existe. Los archivos oficiales descargados están documentados en `data/catalogos-localidades/README.md`.

## Enfoque elegido

El usuario eligió el importador administrable con vista previa. Se incorporará una pantalla protegida para cargar un archivo geográfico normalizado por país, revisar altas/actualizaciones/duplicados y confirmar una importación repetible. La importación será idempotente por código oficial de origen; no borrará localidades históricas ni usadas en viajes. Cada localidad conservará sus coordenadas disponibles y su contexto jurisdiccional para identificar coincidencias.

El catálogo es global y compartido por todas las empresas: cada localidad se guarda una sola vez para todo el sistema y su identidad no incluye `empresa_id`. La pantalla de importación debe quedar limitada a un rol de administración global; las cuentas de empresa mantienen acceso de lectura mediante el formulario de viajes. El importador no cambiará el cierre del viaje, los kilómetros reales ni los tramos existentes.

## Alternativas consideradas

1. **Migración con todo el catálogo embebido:** no elegida; hace grande y difícil de actualizar el esquema de base.
2. **Importador web con vista previa y archivos normalizados:** elegido; permite usarlo también en producción Ferozo, donde no hay CLI, y revisar cambios antes de aplicarlos.
3. **Carga manual mediante SQL/CSV:** no elegida; es frágil, no deja una vista previa clara y facilita duplicados.

## Fuentes iniciales y límites

- Argentina: GeoRef, localidades BAHRA, con códigos y centroides.
- Brasil: IBGE, GeoPackage de Localidades 2022; trae 96.163 registros de categoría que corresponden a 87.362 códigos únicos. Una localidad puede aparecer con más de una categoría; se importa una fila por código y se conservan las categorías combinadas.
- Chile: capa INE de Ciudades, Pueblos y Aldeas, Censo 2017; trae 3.894 puntos.
- Uruguay: cartografía INE del Censo 2023; trae 652 localidades censales y puntos.

Las definiciones y años varían por fuente. Se guardará la fuente/versión de importación y el importador no inferirá distancias. El cálculo futuro con OSM queda fuera de este cambio.

## Cambios previstos

- Migración correctiva para identidad oficial global, contexto administrativo y coordenadas sin editar migraciones aplicadas.
- Historial append-only de importaciones con país, fuente, versión, hash del archivo, resultado y Superadministrador responsable.
- Caso de uso de aplicación que valida y confirma el lote mediante un puerto de importación; adaptador CI4 para persistencia transaccional.
- Adaptador de lectura para vista previa/resumen de lote y controlador/vista con autorización global.
- Documentación de formato normalizado, fuentes, operación y resultado de la carga inicial en staging.

## Criterios de aceptación

- Importar cada lote dos veces no crea duplicados.
- El preview muestra conteos por alta, actualización, repetición inválida y fila con error antes de guardar.
- Los códigos oficiales no colisionan entre países o fuentes.
- Se conservan coordenadas y contexto administrativo sin reemplazar localidad por ruta.
- Un usuario de empresa no puede importar ni alterar el catálogo global.
- El buscador del tramo encuentra, como mínimo, Ampere y Buenos Aires, con provincia/país en la etiqueta.
- No se implementa cálculo OSM ni distancia recorrida real en esta tarea.
