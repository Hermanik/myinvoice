<script setup lang="ts">
/**
 * The data box card of the accountant dashboard: how many received messages the owner has not dealt with yet and the
 * newest three. It only exists when the owner opened a box to the accountant (the dashboard does not render it
 * otherwise); every row opens that message, read-only, on the data box page.
 */
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import type { AccountantDatabox } from '@/api/dashboard'
import { formatDate } from '@/composables/useFormat'

defineProps<{ databox: AccountantDatabox }>()
const { t } = useI18n()

/** The server sends `YYYY-MM-DD HH:MM:SS`, which not every browser parses without the `T`. */
const day = (value: string | null) => formatDate(value?.replace(' ', 'T'))
</script>

<template>
  <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm flex flex-col" data-testid="accountant-databox">
    <header class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between gap-3">
      <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-600">{{ t('accountant_dashboard.databox.title') }}</h3>
      <RouterLink to="/databox" class="text-xs text-primary-600 hover:underline shrink-0">{{ t('accountant_dashboard.databox.open') }} →</RouterLink>
    </header>

    <p
      class="px-5 pt-3 text-sm" data-testid="accountant-databox-count"
      :class="databox.unhandled > 0 ? 'font-medium text-warning-600' : 'text-neutral-600'"
    >
      {{ databox.unhandled > 0 ? t('accountant_dashboard.databox.unhandled', { count: databox.unhandled }) : t('accountant_dashboard.databox.none') }}
    </p>

    <p v-if="databox.latest.length === 0" class="px-5 py-3 text-sm text-neutral-600" data-testid="accountant-databox-empty">
      {{ t('accountant_dashboard.databox.empty') }}
    </p>

    <template v-else>
      <h4 class="px-5 pt-3 pb-1 text-xs font-medium uppercase tracking-wide text-neutral-500">{{ t('accountant_dashboard.databox.latest') }}</h4>
      <ul class="divide-y divide-neutral-100">
        <li v-for="m in databox.latest" :key="m.id" :data-unhandled="m.unhandled">
          <RouterLink
            :to="{ path: '/databox', query: { box: String(m.databox_id), message: String(m.id) } }"
            class="flex items-center gap-3 px-5 py-2.5 hover:bg-neutral-50"
          >
            <span class="w-2 h-2 rounded-full shrink-0" :class="m.unhandled ? 'bg-primary-600' : 'bg-transparent'" aria-hidden="true"></span>
            <span class="w-24 shrink-0 text-sm font-mono text-neutral-800 whitespace-nowrap">{{ day(m.received_at) }}</span>
            <span class="min-w-0 flex-1">
              <span class="block text-sm text-neutral-800 truncate" :class="{ 'font-semibold': m.unhandled }">{{ m.sender_name || '—' }}</span>
              <span class="block text-xs text-neutral-500 truncate">{{ m.annotation || t('databox.detail.no_subject') }}</span>
            </span>
            <span v-if="m.is_tax_office" class="px-2 py-0.5 rounded text-xs font-medium shrink-0 bg-danger-50 text-danger-500 border border-danger-500/30">
              {{ t('databox.tax_office') }}
            </span>
          </RouterLink>
        </li>
      </ul>
    </template>
  </section>
</template>
