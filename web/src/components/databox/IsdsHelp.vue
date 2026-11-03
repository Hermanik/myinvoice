<script setup lang="ts">
import { useI18n } from 'vue-i18n'

const { t, tm, rt } = useI18n()
const list = (key: string): string[] => (tm(key) as unknown[]).map(m => rt(m as never))
</script>

<template>
  <details class="rounded-md border border-neutral-200 bg-neutral-50 text-sm" data-test="isds-help">
    <summary class="cursor-pointer px-3 py-2 font-medium">{{ t('databox.help.title') }}</summary>
    <div class="px-4 pb-4 space-y-4">
      <section>
        <h3 class="font-semibold mt-2">{{ t('databox.help.how_title') }}</h3>
        <p class="text-neutral-700 mt-1">{{ t('databox.help.how_intro') }}</p>
        <table class="w-full text-xs mt-2 border border-neutral-200 bg-surface">
          <thead class="bg-neutral-100 text-neutral-600">
            <tr>
              <th class="text-left px-2 py-1.5">{{ t('databox.help.th_login') }}</th>
              <th class="text-left px-2 py-1.5">{{ t('databox.help.th_use') }}</th>
              <th class="text-left px-2 py-1.5">{{ t('databox.help.th_rights') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr>
              <td class="px-2 py-1.5 font-medium">{{ t('databox.help.row_sync_login') }}</td>
              <td class="px-2 py-1.5">{{ t('databox.help.row_sync_use') }}</td>
              <td class="px-2 py-1.5">{{ t('databox.help.row_sync_rights') }}</td>
            </tr>
            <tr>
              <td class="px-2 py-1.5 font-medium">{{ t('databox.help.row_action_login') }}</td>
              <td class="px-2 py-1.5">{{ t('databox.help.row_action_use') }}</td>
              <td class="px-2 py-1.5">{{ t('databox.help.row_action_rights') }}</td>
            </tr>
          </tbody>
        </table>
        <p class="mt-2 text-neutral-700">{{ t('databox.help.levels_intro') }}</p>
        <ol class="list-decimal pl-5 mt-1 space-y-1 text-neutral-700">
          <li v-for="(l, i) in list('databox.help.levels')" :key="i">{{ l }}</li>
        </ol>
        <p class="mt-2 text-neutral-700">{{ t('databox.help.refuse_note') }}</p>
      </section>

      <section>
        <h3 class="font-semibold">{{ t('databox.help.steps_title') }}</h3>
        <p class="text-neutral-700 mt-1">{{ t('databox.help.steps_intro') }}</p>
        <ol class="list-decimal pl-5 mt-1 space-y-1.5 text-neutral-700">
          <li v-for="(s, i) in list('databox.help.steps')" :key="i">{{ s }}</li>
        </ol>
        <p class="mt-3 text-sm rounded-md border border-primary-200 bg-primary-50 text-primary-700 px-3 py-2">{{ t('databox.help.note_portal') }}</p>
        <p class="mt-2 text-sm rounded-md border border-warning-500/50 bg-warning-50 text-warning-600 px-3 py-2">{{ t('databox.help.warning_owner') }}</p>
      </section>

      <a href="/manual?ch=42_Datove_schranky" target="_blank" rel="noopener" class="inline-block text-primary-700 hover:underline">
        {{ t('databox.help.manual_link') }}
      </a>
    </div>
  </details>
</template>
