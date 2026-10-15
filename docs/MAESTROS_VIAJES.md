# Maestros del módulo de Viajes

La primera entrega de Viajes habilita los datos reutilizables para viajes de carga y facturación. No crea viajes operativos ni utiliza los segmentos calculados por telemetría.

## Navegación y permisos

- El módulo aparece en `/inicio` cuando el usuario tiene `viajes.maestros.ver`.
- La navegación propia de Viajes lleva a `Maestros` y mantiene un retorno visible a `/inicio`.
- `viajes.maestros.editar` permite crear, editar e inactivar registros.
- La migración asigna ambos permisos a los roles `Administrador` y `Responsable de mantenimiento` cuando existen.

## Catálogos

Los catálogos por empresa son clientes, domicilios de clientes, tipos de carga, unidades de carga, categorías de gastos, medios de pago, tipos de documento y porcentajes de liquidación por empleado con vigencias. Los clientes y empleados se relacionan mediante identificadores existentes; no se duplican esas entidades.

En la pantalla, los domicilios se consultan y administran dentro de la ficha del cliente. No se presentan como un catálogo independiente en la navegación principal de Maestros.

Monedas y países son catálogos compartidos de consulta. La migración carga monedas regionales y países de Sudamérica como conjunto inicial. Se pueden ampliar cuando se confirmen los corredores usados por la empresa. La lista no determina requisitos documentales por país ni corredor.

Los registros de empresa incluyen `empresa_id`, claves e índices para las consultas por empresa, auditoría básica de alta/modificación y estado activo. La inactivación conserva los datos. Los domicilios tienen una clave foránea compuesta con `empresa_id` para impedir asociar el domicilio a un cliente de otra empresa.

Los períodos de liquidación se validan para impedir superposiciones activas del mismo empleado. Esta tabla configura porcentajes; no calcula ni contabiliza liquidaciones. El viaje o liquidación final deberá guardar una copia del porcentaje aplicado.

## Decisiones todavía abiertas

La rentabilidad, los documentos obligatorios según corredor y el perfil de ruteo quedan fuera de este corte. Los tipos documentales son catálogos editables; no implican reglas de obligatoriedad. Los requisitos se completarán cuando la empresa responda.
