<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { DataboxCredential, SyncState } from '@/api/databox'
import { formatDate } from '@/composables/useFormat'
import { daysUntil, formatDateTime } from '@/utils/databox'
import PermissionIndicator from './PermissionIndicator.vue'

const props = defineProps<{
  purpose: 'sync' | 'action'
  cred: DataboxCredential
  syncState?: SyncState
}>()
const { t } = useI18n()

const expiryDays = computed(() => daysUntil(props.cred.password_expires_at))
const expiryWarn = computed(() => expiryDays.value !== null && expiryDays.value < 14)

const chips = computed(() => [
  { key: 'can_send', value: props.cred.can_send },
  { key: 'can_search', value: props.cred.can_search },
  { key: 'can_read', value: props.cred.can_read },
])
const chipClass = (v: boolean | null) =>
  v === true ? 'bg-success-50 text-success-600 border-success-500/40'
    : v === false ? 'bg-neutral-100 text-neutral-500 border-neutral-200 line-through'
      : 'bg-neutral-50 text-neutral-400 border-neutral-200 border-dashed'
</script>

<template>
  <div class="space-y-1.5">
    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
      <span class="font-medium">{{ t(`databox.purpose.${purpose}`) }}:</span>
      <code class="text-xs bg-neutral-100 rounded px-1.5 py-0.5">{{ cred.username }}</code>
      <span v-if="cred.last_check_status && cred.last_check_status !== 'ok'" class="text-xs text-danger-500">
        {{ cred.last_check_message || t(`databox.check_status.${cred.last_check_status}`) }}
      </span>
      <span v-else-if="cred.last_check_at" class="text-xs text-neutral-500">{{ t('databox.checked_at', { date: formatDateTime(cred.last_check_at) }) }}</span>
    </div>
    <div v-if="cred.privilege_labels.length" class="flex flex-wrap gap-1">
      <span v-for="l in cred.privilege_labels" :key="l" class="text-xs px-1.5 py-0.5 rounded bg-neutral-100 text-neutral-700">{{ l }}</span>
    </div>
    <div v-if="purpose === 'action'" class="flex flex-wrap gap-1.5">
      <span v-for="c in chips" :key="c.key" class="text-xs px-2 py-0.5 rounded-full border" :class="chipClass(c.value)"
        :title="c.value === null ? t('databox.unknown') : ''">
        {{ t(`databox.chip.${c.key}`) }}<template v-if="c.value === null"> ?</template>
      </span>
    </div>
    <PermissionIndicator v-if="purpose === 'sync' && syncState" :state="syncState" />
    <p v-if="cred.password_expires_at" class="text-xs" :class="expiryWarn ? 'text-warning-600 font-medium' : 'text-neutral-500'">
      {{ t('databox.password_expires', { date: formatDate(cred.password_expires_at) }) }}
      <template v-if="expiryWarn">{{ expiryDays !== null && expiryDays < 0 ? t('databox.password_expired') : t('databox.password_expiring', { days: expiryDays ?? 0 }) }}</template>
    </p>
  </div>
</template>
