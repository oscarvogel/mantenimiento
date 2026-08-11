const allowedStatuses = new Set(['AL_DIA', 'PROXIMO', 'VENCIDO', 'SIN_DATOS'])

const asText = (value, fallback = '') =>
  typeof value === 'string' && value.trim() ? value.trim() : fallback

const asUrl = (value, fallback = '#') => {
  const url = asText(value)

  if (!url || /^javascript:/i.test(url)) {
    return fallback
  }

  return url
}

const asCount = (value) => {
  const count = Number(value)
  return Number.isFinite(count) && count >= 0 ? Math.trunc(count) : 0
}

const initialsFor = (name) =>
  name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0])
    .join('')
    .toUpperCase() || 'US'

const statusTones = {
  AL_DIA: 'ok',
  PROXIMO: 'due',
  VENCIDO: 'overdue',
  SIN_DATOS: 'inactive',
}

const normalizeNavigation = (navigation) => {
  if (!Array.isArray(navigation)) return []

  return navigation
    .filter((item) => item && typeof item === 'object')
    .map((item, index) => ({
      key: asText(item.key, `item-${index}`),
      label: asText(item.label, 'Sección'),
      href: asUrl(item.href),
      icon: asText(item.icon, 'dashboard'),
      active: Boolean(item.active),
      disabled: Boolean(item.disabled),
      badge: item.badge === null || item.badge === undefined ? '' : asText(String(item.badge)),
    }))
}

const normalizeUpcoming = (items) => {
  if (!Array.isArray(items)) return []

  return items
    .filter((item) => item && typeof item === 'object')
    .map((item, index) => ({
      id: asText(String(item.planId ?? ''), `maintenance-${index}`),
      planId: asCount(item.planId),
      equipmentId: asCount(item.equipmentId),
      equipment: asText(item.equipmentCode, 'Equipo sin identificar'),
      service: asText(item.serviceName, 'Mantenimiento'),
      branch: asText(item.branchName, 'Sin sucursal'),
      remaining: asText(item.remaining, 'Sin datos'),
      status: allowedStatuses.has(item.status) ? item.status : 'SIN_DATOS',
      tone: statusTones[item.status] ?? 'inactive',
      statusLabel: asText(item.statusLabel, 'Sin datos'),
      priority: asCount(item.priority),
    }))
}

const findNavigationUrl = (navigation, matchers) => {
  const item = navigation.find((entry) => {
    if (entry.disabled) return false
    const search = `${entry.key} ${entry.icon} ${entry.label}`.toLowerCase()
    return matchers.some((matcher) => search.includes(matcher))
  })

  return item ? item.href : '#'
}

export function normalizeAppShellPayload(payload) {
  const source = payload && typeof payload === 'object' ? payload : {}
  const user = source.user && typeof source.user === 'object' ? source.user : {}
  const company = source.company && typeof source.company === 'object' ? source.company : {}
  const logout = source.logout && typeof source.logout === 'object' ? source.logout : null
  const notifications = source.notifications && typeof source.notifications === 'object'
    ? source.notifications
    : null
  const navigation = normalizeNavigation(source.navigation)
  const branches = Array.isArray(company.branches)
    ? company.branches
        .filter((branch) => branch && typeof branch === 'object')
        .map((branch) => ({ id: asCount(branch.id), name: asText(branch.name, 'Sucursal') }))
    : []
  const mode = source.mode === 'global' ? 'global' : 'tenant'
  const userName = asText(user.name, asText(user.email, 'Usuario'))
  const roles = Array.isArray(user.roles) ? user.roles.map((role) => asText(role)).filter(Boolean) : []

  return {
    available: Object.keys(source).length > 0,
    mode,
    user: {
      name: userName,
      email: asText(user.email),
      roles,
      roleLabel: roles.join(', ') || (Boolean(user.isSuperAdmin) ? 'Superadministrador' : 'Acceso autorizado'),
      initials: initialsFor(userName),
      isSuperAdmin: Boolean(user.isSuperAdmin),
    },
    company: {
      name: mode === 'global' ? 'Administración global' : asText(company.name, 'Empresa'),
      branches,
      scopeLabel:
        mode === 'global'
          ? 'Todas las empresas'
          : branches.length === 0
            ? 'Sin sucursales asignadas'
            : branches.length === 1
              ? branches[0].name
              : `${branches.length} sucursales`,
    },
    navigation,
    notifications: notifications
      ? {
          enabled: Boolean(notifications.enabled),
          summaryUrl: asUrl(notifications.summaryUrl),
          centerUrl: asUrl(notifications.centerUrl),
        }
      : { enabled: false, summaryUrl: '#', centerUrl: '#' },
    logout: logout
      ? {
          href: asUrl(logout.url),
          method: 'post',
          csrfName: asText(logout.csrfName),
          csrfValue: asText(logout.csrfHash),
        }
      : null,
  }
}

export function normalizeDashboardPayload(payload) {
  const source = payload && typeof payload === 'object' ? payload : {}
  const metrics = source.metrics && typeof source.metrics === 'object' ? source.metrics : {}
  const shell = normalizeAppShellPayload(source)
  const equipmentUrl = findNavigationUrl(shell.navigation, ['camion', 'equipo', 'truck'])
  const maintenanceUrl = findNavigationUrl(shell.navigation, ['plan', 'preventiv', 'mantenimiento', 'servicio', 'maintenance'])

  return {
    ...shell,
    metrics: {
      equipmentTotal: asCount(metrics.equipmentTotal),
      equipmentActive: asCount(metrics.equipmentActive),
      maintenanceDueSoon: asCount(metrics.maintenanceDueSoon),
      maintenanceOverdue: asCount(metrics.maintenanceOverdue),
      maintenanceScheduled: asCount(metrics.maintenanceScheduled),
      openOrders: asCount(metrics.openOrders),
    },
    upcomingMaintenance: normalizeUpcoming(source.upcomingMaintenance),
    links: {
      equipment: equipmentUrl,
      maintenance: maintenanceUrl,
    },
  }
}
