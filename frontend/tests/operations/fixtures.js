export const csrf = { name: 'csrf_test_name', hash: 'secure-token' }
export const pagination = { page: 1, totalPages: 2, total: 21, previousUrl: null, nextUrl: '?page=2&per_page=10', perPage: 10, perPageKey: 'per_page', pageKey: 'page' }
export const flash = { success: 'Operación completada.', error: '' }

export const maintenanceData = {
  csrf,
  flash,
  currentDateTime: '2026-08-08 15:00:00',
  old: {},
  routes: { equipmentIndex: '/mantenimiento/equipos', createEquipment: '/mantenimiento/equipos', detectDue: '/mantenimiento/vencimientos/detectar' },
  can: { createEquipment: true, registerReading: true, assignPlan: true, detectDue: true, generateOrder: true, editOrder: true, closeOrder: true },
  pagination: {
    equipments: { page: 1, totalPages: 2, total: 11, perPage: 10, perPageOptions: [5, 10, 25], pageKey: 'equipos_page', perPageKey: 'equipos_per_page', previousUrl: null, nextUrl: '/mantenimiento?equipos_page=2&equipos_per_page=10&planes_page=1&planes_per_page=10&avisos_page=1&avisos_per_page=10&ordenes_page=1&ordenes_per_page=10&lecturas_page=1&lecturas_per_page=10' },
    plans: { page: 1, totalPages: 1, total: 1, perPage: 10, perPageOptions: [5, 10, 25], pageKey: 'planes_page', perPageKey: 'planes_per_page', previousUrl: null, nextUrl: null },
    notices: { page: 1, totalPages: 1, total: 1, perPage: 10, perPageOptions: [5, 10, 25], pageKey: 'avisos_page', perPageKey: 'avisos_per_page', previousUrl: null, nextUrl: null },
    orders: { page: 1, totalPages: 1, total: 1, perPage: 10, perPageOptions: [5, 10, 25], pageKey: 'ordenes_page', perPageKey: 'ordenes_per_page', previousUrl: null, nextUrl: null },
    readings: { page: 1, totalPages: 1, total: 1, perPage: 10, perPageOptions: [5, 10, 25], pageKey: 'lecturas_page', perPageKey: 'lecturas_per_page', previousUrl: null, nextUrl: null },
  },
  catalogs: {
    branches: [{ id: 1, code: 'CC', name: 'Casa central' }],
    equipmentTypes: [{ id: 1, name: 'Camión' }],
    brands: [{ id: 1, name: 'Scania' }],
    models: [{ id: 1, name: 'R450', brandName: 'Scania', typeName: 'Camión' }],
    serviceTypes: [{ id: 1, name: 'Service motor' }],
    templateDefaults: [{ id: 15, templateId: 3, templateName: 'Preventivo camiones', equipmentTypeId: 1, serviceTypeId: 1, serviceName: 'Service motor', intervalKm: 10000, intervalHours: '250.0', intervalDays: 180, warningKm: 1000, warningHours: '25.0', warningDays: 15, priority: 'MEDIA', notes: 'Aceite y filtros' }],
    users: [{ id: 7, name: 'Técnico Uno' }],
  },
  equipments: [{ id: 9, code: 'CAM-01', plate: 'AA123BB', typeId: 1, typeName: 'Camión', branchName: 'Casa central', status: 'ACTIVO', controlsKm: true, controlsHours: true, currentKm: 1000, currentHours: 42, routes: { detail: '/mantenimiento/equipos/9', registerReading: '/mantenimiento/equipos/9/lecturas', assignPlan: '/mantenimiento/equipos/9/planes' } }],
  plans: [{ id: 2, equipmentCode: 'CAM-01', serviceName: 'Service motor', computedState: 'PROXIMO', nextKm: 1500, nextHours: null, nextDate: null }],
  notices: [{ id: 3, equipmentCode: 'CAM-01', serviceName: 'Service motor', triggerCriteria: 'kilómetros', generateOrderUrl: '/mantenimiento/avisos/3/orden' }],
  orders: [{ id: 4, number: 'OT-4', equipmentCode: 'CAM-01', serviceName: 'Service motor', ownerName: 'Técnico Uno', status: 'EN_PROCESO', startUrl: '/mantenimiento/ordenes/4/iniciar', closeUrl: '/mantenimiento/ordenes/4/cerrar', tasks: [{ id: 1, description: 'Cambiar aceite', status: 'PENDIENTE' }] }],
  readings: [{ id: 5, equipmentCode: 'CAM-01', recordedAt: '08/08/2026 15:00', kilometers: 1000, hours: 42, origin: 'MANUAL' }],
}

export const preventivePlansData = {
  csrf, flash, canEdit: true, old: {},
  routes: { index: '/mantenimiento/planes', create: '/mantenimiento/planes', equipmentIndex: '/mantenimiento/equipos' },
  filters: { q: '', branchId: '', equipmentId: '', state: '' },
  catalogs: {
    equipment: [{ id: 9, code: 'CAM-01', plate: 'AA123BB', branchId: 1, typeId: 1, branchCode: 'CC', branchName: 'Casa central', typeName: 'Camión', controlsKm: true, controlsHours: false, currentKm: 9900, currentHours: null }],
    serviceTypes: [{ id: 3, code: 'SM', name: 'Service motor' }],
    branches: [{ id: 1, code: 'CC', name: 'Casa central' }],
    templateDefaults: [{ id: 15, templateId: 3, templateName: 'Preventivo camiones', equipmentTypeId: 1, serviceTypeId: 3, serviceName: 'Service motor', intervalKm: 10000, intervalHours: null, intervalDays: 180, warningKm: 1000, warningHours: null, warningDays: 15, priority: 'MEDIA', notes: 'Aceite y filtros' }],
  },
  plans: {
    total: 1,
    pagination: { page: 1, totalPages: 1, total: 1, perPage: 10, perPageOptions: [5, 10, 25], perPageKey: 'por_pagina', pageKey: 'page', previousUrl: null, nextUrl: null },
    items: [{ id: 2, equipment: { id: 9, code: 'CAM-01', plate: 'AA123BB', typeName: 'Camión', detailUrl: '/mantenimiento/equipos/9' }, branch: { id: 1, code: 'CC', name: 'Casa central' }, serviceName: 'Service motor', state: 'PROXIMO', priority: 'MEDIA', criteria: { kilometers: { interval: 1000, warning: 200, base: 9000, next: 10000, current: 9900 }, hours: null, date: null }, notes: null }],
  },
}

export const assetsData = {
  csrf, flash, canEdit: true, old: {},
  routes: { index: '/mantenimiento/equipos', maintenance: '/mantenimiento', createEquipment: '/mantenimiento/equipos', createBrand: '/mantenimiento/catalogos/marcas', createModel: '/mantenimiento/catalogos/modelos' },
  filters: { q: '', typeId: '', brandId: '', branchId: '', status: '', perPage: 10 },
  catalogs: {
    branches: [{ id: 1, code: 'CC', name: 'Casa central' }],
    types: [{ id: 1, name: 'Camión', active: true }, { id: 2, name: 'Acoplado', active: true }],
    brands: [{ id: 2, name: 'Scania', active: true, updateUrl: '/mantenimiento/catalogos/marcas/2', inactivateUrl: '/mantenimiento/catalogos/marcas/2/inactivar' }, { id: 4, name: 'Volvo', active: true, updateUrl: '/mantenimiento/catalogos/marcas/4', inactivateUrl: '/mantenimiento/catalogos/marcas/4/inactivar' }],
    models: [{ id: 3, name: 'R450', brandId: 2, typeId: 1, brandName: 'Scania', typeName: 'Camión', active: true, updateUrl: '/mantenimiento/catalogos/modelos/3', inactivateUrl: '/mantenimiento/catalogos/modelos/3/inactivar' }, { id: 5, name: 'FH', brandId: 4, typeId: 1, brandName: 'Volvo', typeName: 'Camión', active: true, updateUrl: '/mantenimiento/catalogos/modelos/5', inactivateUrl: '/mantenimiento/catalogos/modelos/5/inactivar' }],
  },
  equipment: { total: 1, pagination, items: [{ id: 9, code: 'CAM-01', typeName: 'Camión', plate: 'AA123BB', brandName: 'Scania', modelName: 'R450', year: 2023, branchCode: 'CC', branchName: 'Casa central', currentKm: 1000, currentHours: 42, status: 'ACTIVO', detailUrl: '/mantenimiento/equipos/9', qrUrl: '/mantenimiento/equipos/9/qr.svg' }] },
  management: {
    brands: {
      total: 2,
      pagination: { ...pagination, total: 2, totalPages: 2, perPage: 5, pageKey: 'brand_page', perPageKey: 'brand_per_page', nextUrl: '?brand_page=2&brand_per_page=5&model_page=1&model_per_page=10' },
      items: [{ id: 2, name: 'Scania', active: true, updateUrl: '/mantenimiento/catalogos/marcas/2', inactivateUrl: '/mantenimiento/catalogos/marcas/2/inactivar' }],
    },
    models: {
      total: 2,
      pagination: { ...pagination, total: 2, totalPages: 2, pageKey: 'model_page', perPageKey: 'model_per_page', nextUrl: '?brand_page=1&brand_per_page=5&model_page=2&model_per_page=10' },
      items: [{ id: 3, name: 'R450', brandName: 'Scania', typeName: 'Camión', active: true, updateUrl: '/mantenimiento/catalogos/modelos/3', inactivateUrl: '/mantenimiento/catalogos/modelos/3/inactivar' }],
    },
  },
}

export const equipmentData = {
  csrf, flash, maxUploadMb: 10,
  can: { edit: true, correctReadings: true },
  routes: { index: '/mantenimiento/equipos', maintenance: '/mantenimiento', qr: '/mantenimiento/equipos/9/qr.svg', update: '/mantenimiento/equipos/9/editar', transfer: '/mantenimiento/equipos/9/trasladar', decommission: '/mantenimiento/equipos/9/baja', createRelation: '/mantenimiento/equipos/9/relaciones', uploadAttachment: '/mantenimiento/equipos/9/adjuntos' },
  equipment: { id: 9, code: 'CAM-01', typeId: 1, typeName: 'Camión', branchCode: 'CC', branchName: 'Casa central', branchId: 1, status: 'ACTIVO', currentKm: 1000, currentHours: 42, plate: 'AA123BB', startDate: '2025-01-02', endDate: null, brandId: 2, modelId: 3, year: 2023, chassis: 'CH-1', engine: 'MO-1', notes: 'Unidad piloto', controlsKm: true, controlsHours: true },
  catalogs: { types: [{ id: 1, name: 'Camión', controlsKm: true, controlsHours: true }], brands: [{ id: 2, name: 'Scania' }], models: [{ id: 3, name: 'R450', brandId: 2, typeId: 1, brandName: 'Scania', typeName: 'Camión' }] },
  availableBranches: [{ id: 2, code: 'N', name: 'Norte' }],
  relatedCandidates: [{ id: 10, code: 'ACO-01', typeName: 'Acoplado' }],
  relations: { total: 1, pagination: { ...pagination, total: 1 }, items: [{ id: 1, principalCode: 'CAM-01', relatedCode: 'ACO-01', type: 'TRACTOR_ACOPLADO', from: '2026-08-01 10:00', to: null, userName: 'Admin', notes: '', finishUrl: '/mantenimiento/equipos/9/relaciones/1/finalizar' }] },
  attachments: { total: 1, pagination: { ...pagination, total: 1 }, items: [{ id: 2, originalName: 'manual.pdf', mimeType: 'application/pdf', sizeKb: '12.0', type: 'MANUAL', description: '', createdAt: '2026-08-08', createdByName: 'Admin', retiredAt: null, retirementReason: '', downloadUrl: '/mantenimiento/equipos/9/adjuntos/2/descargar', retireUrl: '/mantenimiento/equipos/9/adjuntos/2/retirar' }] },
  readings: { total: 1, pagination: { ...pagination, total: 1 }, items: [{ id: 4, recordedAt: '08/08/2026 12:00', kilometers: 1000, hours: 42, origin: 'MANUAL', userName: 'Admin', branchId: 1, annulled: false, annulmentReason: null, replacementReadingId: null, correctedReadingId: null, correctionReason: null, correctUrl: '/mantenimiento/equipos/9/lecturas/4/corregir' }] },
  transfers: { total: 1, pagination: { ...pagination, total: 1 }, items: [{ id: 1, date: '2026-08-02', originCode: 'S', originName: 'Sur', destinationCode: 'CC', destinationName: 'Casa central', reason: 'Reasignación', userName: 'Admin' }] },
}

export const importsData = {
  csrf, flash, canUpload: true, maxSizeMb: 10,
  routes: { upload: '/mantenimiento/importaciones', templates: { equipment: '/mantenimiento/importaciones/plantilla/EQUIPOS', readings: '/mantenimiento/importaciones/plantilla/LECTURAS' } },
  imports: { total: 1, pagination, items: [{ id: 8, date: '2026-08-08', userName: 'Admin', originalFile: 'equipos.xlsx', type: 'EQUIPOS', importedRows: 4, errorRows: 1, duplicateRows: 2, summary: 'Vista previa', status: 'BORRADOR_VALIDADO', detailUrl: '/mantenimiento/importaciones/8' }] },
}

export const importShowData = {
  csrf, flash, canMutate: true,
  routes: { back: '/mantenimiento/importaciones', confirm: '/mantenimiento/importaciones/8/confirmar', cancel: '/mantenimiento/importaciones/8/cancelar' },
  header: { id: 8, originalFile: 'equipos.xlsx', type: 'EQUIPOS', status: 'BORRADOR_VALIDADO', summary: 'Validado', totalRows: 7, validRows: 4, errorRows: 1, duplicateRows: 2 },
  rows: { total: 1, pagination, items: [{ rowNumber: 2, status: 'VALIDA', normalizedData: { codigo: 'CAM-01' }, issues: [], result: '' }] },
}
