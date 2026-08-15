export const dashboardPayload = {
  mode: 'tenant',
  user: { name: 'Ana Pérez', email: 'ana@example.test', roles: ['Administradora'], isSuperAdmin: false },
  company: { name: 'Transportes Sur', branches: [{ id: 2, name: 'Sucursal Centro' }] },
  navigation: [
    { key: 'dashboard', label: 'Dashboard', href: '/dashboard', icon: 'dashboard', active: true },
    { key: 'equipment', label: 'Camiones', href: '/mantenimiento/equipos', icon: 'truck' },
  ],
  metrics: {
    equipmentTotal: 24,
    equipmentActive: 21,
    maintenanceDueSoon: 5,
    maintenanceOverdue: 2,
    maintenanceScheduled: 8,
    openOrders: 3,
  },
  links: {
    equipment: '/mantenimiento/equipos',
    maintenance: '/mantenimiento/planes',
    maintenanceDueSoon: '/mantenimiento/planes?estado=PROXIMO',
    maintenanceOverdue: '/mantenimiento/planes?estado=VENCIDO',
  },
  upcomingMaintenance: [
    {
      planId: 15,
      equipmentId: 9,
      equipmentCode: 'Scania R450',
      serviceName: 'Cambio de aceite y filtros',
      branchName: 'Sucursal Centro',
      remaining: '1.200 km restantes',
      status: 'PROXIMO',
      statusLabel: 'Próximo',
      priority: 2,
      detailUrl: '/mantenimiento/equipos/9',
      actionUrl: '/mantenimiento/planes?equipo_id=9&estado=PROXIMO',
      actionLabel: 'Ver plan',
    },
  ],
  logout: {
    url: '/logout',
    csrfName: 'csrf_test_name',
    csrfHash: 'secure-token',
  },
}
