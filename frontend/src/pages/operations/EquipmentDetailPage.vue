<script setup>
import { ArrowDownTrayIcon, QrCodeIcon } from '@heroicons/vue/24/outline'
import { ref } from 'vue'
import CsrfInput from './components/CsrfInput.vue'
import EmptyState from './components/EmptyState.vue'
import FormField from './components/FormField.vue'
import PageHeading from './components/PageHeading.vue'
import PaginationBar from './components/PaginationBar.vue'
import PanelCard from './components/PanelCard.vue'
import StatusBadge from './components/StatusBadge.vue'
import EquipmentThumbnail from './components/EquipmentThumbnail.vue'
import { dangerButton, fieldClass, nowLocal, primaryButton, secondaryButton, today } from './helpers.js'

defineProps({ data: { type: Object, required: true } })

const tabs = [
  { id: 'resumen', label: 'Resumen' },
  { id: 'mantenimiento', label: 'Mantenimiento' },
  { id: 'lecturas', label: 'Lecturas' },
  { id: 'archivos', label: 'Archivos' },
  { id: 'historial', label: 'Historial' },
]
const activeTab = ref('resumen')
</script>

<template>
  <div>
    <PageHeading eyebrow="Ficha del equipo" :title="data.equipment.code" :description="`${data.equipment.typeName} · ${data.equipment.branchCode} · ${data.equipment.branchName}`" :back="{ label: 'Volver al listado', href: data.routes.index }">
      <template #actions><div class="flex flex-wrap gap-2"><a :href="data.routes.qr" target="_blank" rel="noopener" :class="secondaryButton"><QrCodeIcon class="mr-2 size-5" aria-hidden="true" />Ver QR</a><a v-if="data.can.edit" :href="data.routes.addPlansFromTemplate" :class="secondaryButton">Agregar planes desde plantilla</a><a :href="data.routes.maintenance" :class="secondaryButton">Circuito preventivo</a></div></template>
    </PageHeading>

    <nav class="mb-6 overflow-x-auto border-b border-border" aria-label="Secciones de la ficha">
      <div class="flex min-w-max gap-1" role="tablist" aria-label="Información del equipo">
        <button
          v-for="tab in tabs"
          :id="`equipment-tab-${tab.id}`"
          :key="tab.id"
          type="button"
          role="tab"
          :aria-selected="activeTab === tab.id"
          :aria-controls="`equipment-panel-${tab.id}`"
          class="border-b-2 px-4 py-3 text-sm font-semibold transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-primary"
          :class="activeTab === tab.id ? 'border-primary text-primary' : 'border-transparent text-ink-muted hover:border-border-strong hover:text-ink'"
          @click="activeTab = tab.id"
        >
          {{ tab.label }}
        </button>
      </div>
    </nav>

    <div id="equipment-panel-resumen" v-show="activeTab === 'resumen'" role="tabpanel" aria-labelledby="equipment-tab-resumen">

    <section aria-label="Resumen del equipo" class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
      <article v-for="metric in [{label:'Kilometraje actual',value:data.equipment.currentKm === null ? 'Sin datos' : `${data.equipment.currentKm} km`},{label:'Horómetro actual',value:data.equipment.currentHours === null ? 'Sin datos' : `${data.equipment.currentHours} h`},{label:'Patente',value:data.equipment.plate || 'Sin informar'},{label:'Alta',value:data.equipment.startDate}]" :key="metric.label" class="rounded-xl border border-border bg-white p-4 shadow-card"><p class="text-xs text-ink-muted">{{ metric.label }}</p><p class="mt-2 font-bold text-ink">{{ metric.value }}</p></article>
    </section>
    <div class="mb-6 flex items-center gap-3"><StatusBadge :status="data.equipment.status" /><p v-if="data.equipment.endDate" class="text-sm font-medium text-danger-strong">Baja: {{ data.equipment.endDate }}</p></div>

    <PanelCard title="Foto principal" class="mb-6">
      <div class="grid gap-6 md:grid-cols-[minmax(0,22rem)_1fr]">
        <EquipmentThumbnail :url="data.primaryPhoto?.thumbnailUrl" :code="data.equipment.code" :alt="`Foto principal de ${data.equipment.code}`" size="hero" />
        <div>
          <p class="text-sm leading-6 text-ink-muted">JPG, PNG o WEBP, hasta {{ data.maxPrimaryPhotoMb }} MB. La imagen se guarda en almacenamiento privado. Si GD no está disponible, las vistas usan el original de forma segura, sin afirmar que fue normalizado.</p>
          <p v-if="data.primaryPhoto" class="mt-2 text-sm font-semibold text-ink">{{ data.primaryPhoto.originalName }}</p>
          <form v-if="data.can.edit && data.equipment.status === 'ACTIVO'" method="post" enctype="multipart/form-data" :action="data.routes.uploadPrimaryPhoto" class="mt-4 grid gap-3">
            <CsrfInput :csrf="data.csrf" />
            <FormField :label="data.primaryPhoto ? 'Reemplazar foto' : 'Cargar foto'" for-id="detail-primary-photo"><input id="detail-primary-photo" type="file" name="foto" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" required :class="fieldClass" /></FormField>
            <FormField label="Descripción opcional" for-id="detail-primary-photo-description"><input id="detail-primary-photo-description" name="descripcion" maxlength="1000" :class="fieldClass" /></FormField>
            <button type="submit" :class="`${primaryButton} justify-self-start`">{{ data.primaryPhoto ? 'Reemplazar foto' : 'Guardar foto' }}</button>
          </form>
          <details v-if="data.can.edit && data.primaryPhoto" class="ui-details-animated mt-4"><summary :class="dangerButton">Retirar foto</summary><form method="post" :action="data.routes.retirePrimaryPhoto" data-confirm data-confirm-title="¿Retirar la foto principal?" data-confirm-text="La imagen dejará de mostrarse en la ficha del equipo." data-confirm-button="Retirar" data-confirm-danger="true" class="mt-3 max-w-sm rounded-xl border border-border bg-white p-4 shadow-card"><CsrfInput :csrf="data.csrf" /><FormField label="Motivo" for-id="primary-photo-retire-reason"><textarea id="primary-photo-retire-reason" name="motivo" minlength="5" maxlength="255" required :class="fieldClass"></textarea></FormField><button type="submit" :class="`${dangerButton} mt-3`">Confirmar retiro</button></form></details>
        </div>
      </div>
    </PanelCard>

    <section v-if="data.can.edit" class="mb-6 grid gap-6 xl:grid-cols-2">
      <PanelCard title="Datos del equipo">
        <details v-if="data.equipment.status === 'ACTIVO'" class="ui-details-animated group">
          <summary class="flex cursor-pointer list-none items-center justify-between gap-3 rounded-lg border border-border px-4 py-3 font-semibold text-ink hover:bg-surface-subtle">
            Editar ficha técnica
            <span class="text-sm font-normal text-ink-muted group-open:hidden">Mostrar formulario</span>
            <span class="hidden text-sm font-normal text-ink-muted group-open:inline">Ocultar formulario</span>
          </summary>
        <form method="post" :action="data.routes.update" class="mt-5 grid gap-4 sm:grid-cols-2">
          <CsrfInput :csrf="data.csrf" /><FormField label="Código" for-id="detail-equipment-code"><input id="detail-equipment-code" name="codigo" maxlength="50" required :value="data.equipment.code" class="uppercase" :class="fieldClass" /></FormField><FormField label="Patente" for-id="detail-equipment-plate"><input id="detail-equipment-plate" name="patente" maxlength="20" :value="data.equipment.plate" class="uppercase" :class="fieldClass" /></FormField>
          <FormField label="Tipo de equipo" for-id="detail-equipment-type"><select id="detail-equipment-type" name="tipo_equipo_id" required :class="fieldClass"><option v-for="type in data.catalogs.types" :key="type.id" :value="type.id" :selected="String(data.equipment.typeId) === String(type.id)">{{ type.name }}</option></select></FormField><FormField label="Fecha de alta" for-id="detail-equipment-start-date"><input id="detail-equipment-start-date" type="date" name="fecha_alta" required :value="data.equipment.startDate" :max="today()" :class="fieldClass" /></FormField>
          <FormField label="Marca" for-id="detail-equipment-brand"><select id="detail-equipment-brand" name="marca_id" :class="fieldClass"><option value="">Sin informar</option><option v-for="brand in data.catalogs.brands" :key="brand.id" :value="brand.id" :selected="String(data.equipment.brandId) === String(brand.id)">{{ brand.name }}</option></select></FormField><FormField label="Modelo" for-id="detail-equipment-model"><select id="detail-equipment-model" name="modelo_id" :class="fieldClass"><option value="">Sin informar</option><option v-for="model in data.catalogs.models" :key="model.id" :value="model.id" :selected="String(data.equipment.modelId) === String(model.id)">{{ model.brandName }} · {{ model.name }} · {{ model.typeName }}</option></select></FormField>
          <FormField label="Año" for-id="detail-equipment-year"><input id="detail-equipment-year" type="number" min="1900" max="2100" name="anio" :value="data.equipment.year" :class="fieldClass" /></FormField><FormField label="Chasis" for-id="detail-equipment-chassis"><input id="detail-equipment-chassis" name="chasis" maxlength="100" :value="data.equipment.chassis" :class="fieldClass" /></FormField><FormField label="Motor" for-id="detail-equipment-engine"><input id="detail-equipment-engine" name="motor" maxlength="100" :value="data.equipment.engine" :class="fieldClass" /></FormField><FormField label="Observaciones" for-id="detail-equipment-notes" class="sm:col-span-2"><textarea id="detail-equipment-notes" name="observaciones" rows="3" :class="fieldClass" :value="data.equipment.notes"></textarea></FormField><button type="submit" :class="`${primaryButton} sm:justify-self-start`">Guardar cambios</button>
        </form>
        </details>
        <p v-else class="text-sm text-ink-muted">La ficha queda en modo de consulta porque el equipo está dado de baja.</p>
      </PanelCard>

      <PanelCard title="Ubicación y estado">
        <template v-if="data.equipment.status === 'ACTIVO'">
          <form v-if="data.availableBranches.length" method="post" :action="data.routes.transfer" data-confirm data-confirm-title="¿Trasladar este equipo?" data-confirm-text="El equipo cambiará de sucursal. El historial se conservará." data-confirm-button="Trasladar" class="grid gap-4 border-b border-border-subtle pb-6 sm:grid-cols-2">
            <CsrfInput :csrf="data.csrf" /><FormField label="Nueva sucursal" for-id="detail-destination"><select id="detail-destination" name="sucursal_destino_id" required :class="fieldClass"><option v-for="branch in data.availableBranches" :key="branch.id" :value="branch.id">{{ branch.code }} · {{ branch.name }}</option></select></FormField><FormField label="Fecha" for-id="detail-transfer-date"><input id="detail-transfer-date" name="fecha_traslado" type="date" required :value="today()" :class="fieldClass" /></FormField><FormField label="Motivo" for-id="detail-transfer-reason" class="sm:col-span-2"><textarea id="detail-transfer-reason" name="motivo" minlength="5" maxlength="255" rows="2" required :class="fieldClass"></textarea></FormField><button type="submit" :class="`${secondaryButton} sm:justify-self-start`">Registrar traslado</button>
          </form>
          <p v-else class="border-b border-border-subtle pb-5 text-sm text-ink-muted">No hay otra sucursal activa y autorizada disponible para trasladar este equipo.</p>
          <form method="post" :action="data.routes.decommission" data-confirm data-confirm-title="¿Dar de baja este equipo?" data-confirm-text="El equipo dejará de estar disponible para las operaciones normales y quedará en modo de consulta." data-confirm-button="Dar de baja" data-confirm-danger="true" class="mt-6 grid gap-4 sm:grid-cols-[1fr_auto] sm:items-end"><CsrfInput :csrf="data.csrf" /><FormField label="Fecha de baja" for-id="detail-decommission-date"><input id="detail-decommission-date" name="fecha_baja" type="date" required :value="today()" :class="fieldClass" /></FormField><button type="submit" :class="dangerButton">Dar de baja</button><p class="text-xs text-ink-muted sm:col-span-2">Se rechazará si el equipo tiene una orden de trabajo abierta.</p></form>
        </template>
        <p v-else class="text-sm text-ink-muted">El equipo permanece disponible para consulta y auditoría.</p>
      </PanelCard>
    </section>
    </div>

    <div id="equipment-panel-mantenimiento" v-show="activeTab === 'mantenimiento'" role="tabpanel" aria-labelledby="equipment-tab-mantenimiento">
    <div class="mb-6 flex flex-col gap-4 rounded-xl border border-brand-200 bg-brand-50 p-5 sm:flex-row sm:items-center sm:justify-between">
      <div><h2 class="font-bold text-ink">Gestión preventiva</h2><p class="mt-1 text-sm text-ink-muted">Consultá el circuito del equipo o asignale planes desde una plantilla.</p></div>
      <div class="flex flex-wrap gap-2"><a v-if="data.can.edit" :href="data.routes.addPlansFromTemplate" :class="primaryButton">Agregar planes</a><a :href="data.routes.maintenance" :class="secondaryButton">Ver mantenimiento</a></div>
    </div>
    <PanelCard title="Relaciones entre equipos" :count="data.relations.total">
      <form v-if="data.can.edit && data.equipment.status === 'ACTIVO' && data.relatedCandidates.length" method="post" :action="data.routes.createRelation" class="mb-6 grid gap-4 border-b border-border-subtle pb-6 md:grid-cols-4">
        <CsrfInput :csrf="data.csrf" /><FormField label="Equipo relacionado" for-id="detail-related-equipment"><select id="detail-related-equipment" name="equipo_relacionado_id" required :class="fieldClass"><option v-for="candidate in data.relatedCandidates" :key="candidate.id" :value="candidate.id">{{ candidate.code }} · {{ candidate.typeName }}</option></select></FormField><FormField label="Tipo" for-id="detail-relation-type"><select id="detail-relation-type" name="tipo_relacion" :class="fieldClass"><option value="TRACTOR_ACOPLADO">Tractor-acoplado</option><option value="OTRO">Otro</option></select></FormField><FormField label="Desde" for-id="detail-relation-start"><input id="detail-relation-start" type="datetime-local" name="desde" required :value="nowLocal()" :class="fieldClass" /></FormField><button type="submit" :class="`${primaryButton} self-end`">Relacionar</button><FormField label="Observaciones" for-id="detail-relation-notes" class="md:col-span-4"><input id="detail-relation-notes" name="observaciones" maxlength="500" :class="fieldClass" /></FormField>
      </form>
      <EmptyState v-if="data.relations.items.length === 0" title="No hay relaciones registradas" />
      <div v-else class="overflow-x-auto"><table class="ui-table-hover w-full min-w-[52rem] text-left text-sm"><thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted"><tr><th class="px-4 py-3">Equipos</th><th class="px-4 py-3">Tipo</th><th class="px-4 py-3">Vigencia</th><th class="px-4 py-3">Registro</th><th class="px-4 py-3">Acción</th></tr></thead><tbody class="divide-y divide-border-subtle"><tr v-for="relation in data.relations.items" :key="relation.id"><td class="px-4 py-4 font-semibold text-ink">{{ relation.principalCode }} ↔ {{ relation.relatedCode }}</td><td class="px-4 py-4 text-ink-muted">{{ relation.type }}</td><td class="px-4 py-4">{{ relation.from }}<br><span class="text-xs text-ink-muted">{{ relation.to ? `Hasta ${relation.to}` : 'Activa' }}</span></td><td class="px-4 py-4">{{ relation.userName }}<br><span v-if="relation.notes" class="text-xs text-ink-muted">{{ relation.notes }}</span></td><td class="px-4 py-4"><details v-if="data.can.edit && !relation.to" class="ui-details-animated"><summary :class="secondaryButton">Finalizar</summary><form method="post" :action="relation.finishUrl" data-confirm data-confirm-title="¿Finalizar esta relación?" data-confirm-text="La relación dejará de estar activa. El historial se conservará." data-confirm-button="Finalizar relación" class="mt-3 w-72 rounded-xl border border-border bg-white p-4 shadow-card"><CsrfInput :csrf="data.csrf" /><FormField label="Hasta" :for-id="`relation-end-${relation.id}`"><input :id="`relation-end-${relation.id}`" type="datetime-local" name="hasta" required :value="nowLocal()" :class="fieldClass" /></FormField><FormField label="Observaciones de cierre" :for-id="`relation-end-notes-${relation.id}`" class="mt-3"><textarea :id="`relation-end-notes-${relation.id}`" name="observaciones_fin" maxlength="500" :class="fieldClass"></textarea></FormField><button type="submit" :class="`${primaryButton} mt-3`">Finalizar relación</button></form></details></td></tr></tbody></table></div>
      <template #footer><PaginationBar :pagination="data.relations.pagination" /></template>
    </PanelCard>
    </div>

    <div id="equipment-panel-archivos" v-show="activeTab === 'archivos'" role="tabpanel" aria-labelledby="equipment-tab-archivos">
    <PanelCard title="Adjuntos privados" :count="data.attachments.total">
      <form v-if="data.can.edit && data.equipment.status === 'ACTIVO'" method="post" enctype="multipart/form-data" :action="data.routes.uploadAttachment" class="mb-6 grid gap-4 border-b border-border-subtle pb-6 sm:grid-cols-3">
        <CsrfInput :csrf="data.csrf" /><FormField label="Archivo" for-id="detail-attachment-file" :hint="`PDF o imagen, hasta ${data.maxUploadMb} MB.`"><input id="detail-attachment-file" type="file" name="archivo" accept=".pdf,.jpg,.jpeg,.png,.webp" required :class="fieldClass" /></FormField><FormField label="Tipo" for-id="detail-attachment-type"><select id="detail-attachment-type" name="tipo" required :class="fieldClass"><option v-for="type in ['DOCUMENTO','FOTO','MANUAL','COMPROBANTE','OTRO']" :key="type" :value="type">{{ type }}</option></select></FormField><FormField label="Descripción" for-id="detail-attachment-description"><input id="detail-attachment-description" name="descripcion" maxlength="500" :class="fieldClass" /></FormField><button type="submit" :class="`${primaryButton} sm:justify-self-start`">Subir adjunto</button>
      </form>
      <EmptyState v-if="data.attachments.items.length === 0" title="No hay adjuntos para este equipo" />
      <div v-else class="overflow-x-auto"><table class="w-full min-w-[52rem] text-left text-sm"><thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted"><tr><th class="px-4 py-3">Archivo</th><th class="px-4 py-3">Tipo</th><th class="px-4 py-3">Registro</th><th class="px-4 py-3">Estado</th><th class="px-4 py-3">Acción</th></tr></thead><tbody class="divide-y divide-border-subtle"><tr v-for="attachment in data.attachments.items" :key="attachment.id" :class="attachment.retiredAt ? 'bg-surface-subtle text-ink-muted' : ''"><td class="px-4 py-4"><span class="font-semibold">{{ attachment.originalName }}</span><br><span class="text-xs text-ink-muted">{{ attachment.mimeType }} · {{ attachment.sizeKb }} KB</span></td><td class="px-4 py-4">{{ attachment.type }}<br><span v-if="attachment.description" class="text-xs text-ink-muted">{{ attachment.description }}</span></td><td class="px-4 py-4">{{ attachment.createdAt }}<br><span class="text-xs text-ink-muted">{{ attachment.createdByName }}</span></td><td class="px-4 py-4"><StatusBadge :status="attachment.retiredAt ? 'RETIRADO' : 'ACTIVO'" /><p v-if="attachment.retirementReason" class="mt-1 text-xs">{{ attachment.retirementReason }}</p></td><td class="px-4 py-4"><div v-if="!attachment.retiredAt" class="flex gap-2"><a :href="attachment.downloadUrl" :class="secondaryButton"><ArrowDownTrayIcon class="mr-1.5 size-4" aria-hidden="true" />Descargar</a><details v-if="data.can.edit" class="ui-details-animated"><summary :class="dangerButton">Retirar</summary><form method="post" :action="attachment.retireUrl" data-confirm data-confirm-title="¿Retirar este adjunto?" data-confirm-text="El archivo dejará de estar disponible para descarga." data-confirm-button="Retirar" data-confirm-danger="true" class="mt-3 w-72 rounded-xl border border-border bg-white p-4 shadow-card"><CsrfInput :csrf="data.csrf" /><FormField label="Motivo" :for-id="`attachment-reason-${attachment.id}`"><textarea :id="`attachment-reason-${attachment.id}`" name="motivo" minlength="5" maxlength="255" required :class="fieldClass"></textarea></FormField><button type="submit" :class="`${dangerButton} mt-3`">Confirmar retiro</button></form></details></div></td></tr></tbody></table></div>
      <template #footer><PaginationBar :pagination="data.attachments.pagination" /></template>
    </PanelCard>
    </div>

    <div id="equipment-panel-lecturas" v-show="activeTab === 'lecturas'" role="tabpanel" aria-labelledby="equipment-tab-lecturas">
    <PanelCard title="Historial de lecturas" :count="data.readings?.total ?? null">
      <p v-if="data.readings === null" class="text-sm text-ink-muted">No tenés permiso para consultar lecturas.</p>
      <EmptyState v-else-if="data.readings.items.length === 0" title="No hay lecturas registradas" />
      <div v-else class="overflow-x-auto"><table class="w-full min-w-[56rem] text-left text-sm"><thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted"><tr><th class="px-4 py-3">Fecha</th><th class="px-4 py-3">Valores</th><th class="px-4 py-3">Origen y autor</th><th class="px-4 py-3">Estado</th><th class="px-4 py-3">Acción</th></tr></thead><tbody class="divide-y divide-border-subtle"><tr v-for="reading in data.readings.items" :key="reading.id" :class="reading.annulled ? 'bg-surface-subtle text-ink-muted' : ''"><td class="px-4 py-4">{{ reading.recordedAt }}</td><td class="px-4 py-4">{{ reading.kilometers === null ? '—' : `${reading.kilometers} km` }}<br>{{ reading.hours === null ? '—' : `${reading.hours} h` }}</td><td class="px-4 py-4">{{ reading.origin }}<br><span class="text-xs text-ink-muted">{{ reading.userName }} · sucursal #{{ reading.branchId }}</span></td><td class="px-4 py-4"><StatusBadge :status="reading.annulled ? 'ANULADA' : reading.correctedReadingId ? 'CORRECCION' : 'VALIDA'" /><p v-if="reading.annulmentReason || reading.correctionReason" class="mt-1 text-xs">{{ reading.annulmentReason || reading.correctionReason }}</p><p v-if="reading.replacementReadingId" class="text-xs">Reemplazada por #{{ reading.replacementReadingId }}</p></td><td class="px-4 py-4"><details v-if="data.can.correctReadings && !reading.annulled" class="ui-details-animated"><summary :class="secondaryButton">Corregir</summary><form method="post" :action="reading.correctUrl" data-confirm data-confirm-title="¿Guardar la corrección de esta lectura?" data-confirm-text="Se registrará como corrección auditada del historial del equipo." data-confirm-button="Guardar corrección" class="mt-3 w-80 rounded-xl border border-border bg-white p-4 shadow-card"><CsrfInput :csrf="data.csrf" /><FormField label="Kilómetros" :for-id="`correct-km-${reading.id}`"><input :id="`correct-km-${reading.id}`" type="number" min="0" name="kilometraje" :value="reading.kilometers" :disabled="!data.equipment.controlsKm" :class="fieldClass" /></FormField><FormField label="Horómetro" :for-id="`correct-hours-${reading.id}`" class="mt-3"><input :id="`correct-hours-${reading.id}`" type="number" min="0" step="0.1" name="horometro" :value="reading.hours" :disabled="!data.equipment.controlsHours" :class="fieldClass" /></FormField><FormField label="Motivo obligatorio" :for-id="`correct-reason-${reading.id}`" class="mt-3"><textarea :id="`correct-reason-${reading.id}`" name="motivo" minlength="5" maxlength="255" rows="2" required :class="fieldClass"></textarea></FormField><FormField label="Observaciones" :for-id="`correct-notes-${reading.id}`" class="mt-3"><textarea :id="`correct-notes-${reading.id}`" name="observaciones" rows="2" :class="fieldClass"></textarea></FormField><button type="submit" :class="`${primaryButton} mt-3`">Guardar corrección auditada</button></form></details></td></tr></tbody></table></div>
      <template v-if="data.readings" #footer><PaginationBar :pagination="data.readings.pagination" /></template>
    </PanelCard>
    </div>

    <div id="equipment-panel-historial" v-show="activeTab === 'historial'" role="tabpanel" aria-labelledby="equipment-tab-historial">
    <PanelCard title="Historial de traslados" :count="data.transfers.total">
      <EmptyState v-if="data.transfers.items.length === 0" title="Este equipo todavía no fue trasladado" />
      <div v-else class="overflow-x-auto"><table class="w-full min-w-[48rem] text-left text-sm"><thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted"><tr><th class="px-4 py-3">Fecha</th><th class="px-4 py-3">Origen</th><th class="px-4 py-3">Destino</th><th class="px-4 py-3">Motivo</th><th class="px-4 py-3">Registró</th></tr></thead><tbody class="divide-y divide-border-subtle"><tr v-for="movement in data.transfers.items" :key="movement.id"><td class="px-4 py-4">{{ movement.date }}</td><td class="px-4 py-4">{{ movement.originCode }} · {{ movement.originName }}</td><td class="px-4 py-4">{{ movement.destinationCode }} · {{ movement.destinationName }}</td><td class="px-4 py-4 text-ink-muted">{{ movement.reason }}</td><td class="px-4 py-4">{{ movement.userName }}</td></tr></tbody></table></div>
      <template #footer><PaginationBar :pagination="data.transfers.pagination" /></template>
    </PanelCard>
    </div>
  </div>
</template>
