// Sidebar sections by daily task (docs/DESIGN_TOKENS.md, "Navegación"). Keys are unique
// across modules, so one ordered list serves Maintenance and Trips. Permissions stay on the
// server: a group shows only the items the backend sent.
export const navigationGroups = [
  {
    title: 'Operación',
    keys: ['dashboard', 'trips-operations-dashboard', 'my-work', 'report-failure', 'quick-readings', 'work-orders', 'work-requests', 'reading-control', 'trips-operations', 'trips-fuel-pending', 'trips-dashboard'],
  },
  { title: 'Flota', keys: ['equipment', 'fleet-telemetry', 'expirations', 'employees'] },
  { title: 'Planificación', keys: ['maintenance', 'plans', 'services', 'preventive-library', 'providers'] },
  { title: 'Comercial', keys: ['trips-commercial-closing', 'customer-accounts', 'trips-profitability'] },
  { title: 'Análisis', keys: ['reports', 'fleet-board', 'theoretical-cost-km'] },
  {
    title: 'Configuración',
    collapsible: true,
    keys: ['trip-masters', 'masters-equipment', 'masters-expirations', 'imports', 'notifications', 'branches', 'users', 'integrations', 'automations', 'chatbot-audit'],
  },
  {
    title: 'Administración global',
    collapsible: true,
    keys: ['superadmin', 'whatsapp-digest-preview', 'telemetry-samples', 'notification-settings', 'audit-changes'],
  },
]

// Shown inside its parent (fleet-telemetry), not as a row of its own.
const nested = new Set(['fleet-consumption'])

export const groupNavigation = (navigation) => {
  const byKey = new Map(navigation.map((item) => [item.key, item]))
  const placed = new Set(nested)
  const groups = navigationGroups
    .map((group) => {
      const items = group.keys.map((key) => byKey.get(key)).filter(Boolean)
      items.forEach((item) => placed.add(item.key))
      return { ...group, items }
    })
    .filter((group) => group.items.length > 0)
  // Nothing the server sends may vanish: unknown keys land in a last section.
  const rest = navigation.filter((item) => !placed.has(item.key))
  if (rest.length) groups.push({ title: 'Más', collapsible: true, keys: [], items: rest })
  return groups
}
