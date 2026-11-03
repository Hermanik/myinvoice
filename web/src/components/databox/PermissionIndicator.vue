<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { SyncState } from '@/api/databox'

const props = defineProps<{ state: SyncState }>()
const { t } = useI18n()

// Colour scale: green = safe, orange = delivers / sync off, red = broken, neutral = unknown.
const cls = computed(() => ({
  ok: 'border-success-500/40 bg-success-50 text-success-600',
  opt_in: 'border-warning-500/50 bg-warning-50 text-warning-600',
  blocked_delivers: 'border-danger-500/40 bg-danger-50 text-danger-500',
  blocked_no_list: 'border-danger-500/40 bg-danger-50 text-danger-500',
  login_failed: 'border-danger-500/40 bg-danger-50 text-danger-500',
  unverified: 'border-neutral-200 bg-neutral-50 text-neutral-600',
  no_credential: 'border-neutral-200 bg-neutral-50 text-neutral-600',
}[props.state]))
</script>

<template>
  <div class="text-xs rounded-md border px-2.5 py-1.5" :class="cls" data-test="sync-indicator">
    <span class="font-semibold">{{ t(`databox.indicator.${state}_title`) }}</span>
    <span class="block">{{ t(`databox.indicator.${state}`) }}</span>
  </div>
</template>
