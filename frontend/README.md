# Dashboard Vue/Tailwind

Adaptador visual del panel de mantenimiento. No calcula reglas de dominio ni decide permisos: CodeIgniter entrega un modelo de lectura ya autorizado y la interfaz únicamente lo presenta.

## Desarrollo

```powershell
npm install
npm run dev
npm test
npm run build
```

El fallback de `src/data/developmentDashboard.js` se utiliza exclusivamente en modo desarrollo. Una compilación de producción sin payload muestra un estado vacío seguro y nunca datos ficticios.

## Contrato de entrada

Antes del bundle, la vista de CodeIgniter puede definir `window.__MAINTENANCE_DASHBOARD__` o un bloque JSON con `id="maintenance-dashboard-data"`:

```json
{
  "mode": "tenant",
  "user": {
    "name": "Ana Pérez",
    "email": "ana@example.test",
    "roles": ["Administradora"],
    "isSuperAdmin": false
  },
  "company": {
    "name": "Empresa",
    "branches": [{ "id": 1, "name": "Sucursal Centro" }]
  },
  "metrics": {
    "equipmentTotal": 24,
    "equipmentActive": 21,
    "maintenanceDueSoon": 5,
    "maintenanceOverdue": 2,
    "maintenanceScheduled": 8,
    "openOrders": 3
  },
  "upcomingMaintenance": [
    {
      "planId": 15,
      "equipmentId": 9,
      "equipmentCode": "Scania R450",
      "serviceName": "Cambio de aceite y filtros",
      "branchName": "Sucursal Centro",
      "status": "PROXIMO",
      "statusLabel": "Próximo",
      "remaining": "1.200 km restantes",
      "priority": 2
    }
  ],
  "navigation": [
    { "label": "Dashboard", "href": "/dashboard", "icon": "dashboard", "active": true },
    { "label": "Camiones", "href": "/mantenimiento/equipos", "icon": "truck", "disabled": false }
  ],
  "logout": {
    "url": "/logout",
    "csrfName": "csrf_test_name",
    "csrfHash": "token-generado-por-CI4"
  }
}
```

`mode` acepta `global` o `tenant`. Los estados de mantenimiento son `AL_DIA`, `PROXIMO`, `VENCIDO` y `SIN_DATOS`. La navegación debe venir filtrada por permisos desde el servidor; Vue no reemplaza la autorización de CodeIgniter. Los elementos deshabilitados y sus `badge` opcionales se presentan sin convertirlos en enlaces.

## Integración del build

`npm run build` publica únicamente el bundle en `assets/dashboard/` y genera `assets/dashboard/.vite/manifest.json`. La entrada del manifiesto es `src/main.js`; el punto de montaje es:

```html
<div id="maintenance-dashboard"></div>
```

La vista PHP debe resolver desde el manifiesto los archivos JavaScript y CSS con hash. No debe apuntar a nombres de archivo fijos. `frontend/` contiene las fuentes y `node_modules`; Ferozo sólo necesita recibir `assets/dashboard/` junto al resto de la aplicación.
