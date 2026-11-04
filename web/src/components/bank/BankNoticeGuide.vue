<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  detectGuideBank,
  detectGuideMailProviderFromHosts,
  type GuideMailProvider,
} from '@/utils/bankNoticeGuide'

// providerRefs: provider refs used by the current account mappings; hosts: IMAP hosts
// configured or being typed. Both only drive which parts of the guide are highlighted.
const props = defineProps<{
  providerRefs?: Array<string | null | undefined>
  hosts?: Array<string | null | undefined>
}>()

const { t } = useI18n()

const open = ref(false)
// Air Bank is the only fully described bank, so its section starts expanded.
const airbankOpen = ref(true)
const bank = computed(() => detectGuideBank(props.providerRefs ?? []))
const mailProvider = computed(() => detectGuideMailProviderFromHosts(props.hosts ?? []))

// Prefill: an Air Bank mapping marks the section as selected, a recognised host highlights its card.

const airBankSteps = ['ab_1', 'ab_2', 'ab_3', 'ab_4', 'ab_5', 'ab_6']
const step3 = ['s3_1', 's3_2', 's3_3', 's3_4', 's3_5', 's3_6', 's3_7']
const verify = ['v_1', 'v_2', 'v_3', 'v_4']
const troubles = ['ts_1', 'ts_2', 'ts_3', 'ts_4', 'ts_5', 'ts_7']

const providers: Array<{ key: GuideMailProvider; title: string; lines: string[] }> = [
  { key: 'seznam', title: 'mp_seznam', lines: ['mp_seznam_values', 'mp_seznam_app', 'mp_seznam_imap', 'mp_seznam_auth'] },
  { key: 'gmail', title: 'mp_gmail', lines: ['mp_gmail_values'] },
  { key: 'microsoft', title: 'mp_ms', lines: ['mp_ms_values'] },
  { key: 'generic', title: 'mp_generic', lines: ['mp_generic_values'] },
]
</script>

<template>
  <section class="bg-surface border border-primary-200 rounded-lg shadow-sm overflow-hidden" data-testid="bank-notice-guide">
    <button type="button" data-testid="guide-toggle" :aria-expanded="open" @click="open = !open"
      class="cursor-pointer w-full px-5 py-3 flex items-center justify-between gap-3 text-left hover:bg-neutral-50">
      <span class="min-w-0">
        <span class="block text-sm font-semibold">{{ t('bank_notice_guide.title') }}</span>
        <span class="block text-xs text-neutral-500 mt-0.5">{{ t('bank_notice_guide.subtitle') }}</span>
      </span>
      <span class="shrink-0 text-sm text-primary-600">{{ open ? t('bank_notice_guide.hide') : t('bank_notice_guide.show') }}</span>
    </button>

    <div v-if="open" class="border-t border-neutral-200 p-5 space-y-6 text-sm text-neutral-700" data-testid="guide-body">
      <p>{{ t('bank_notice_guide.intro') }}</p>

      <div data-testid="guide-step-1" class="space-y-3">
        <h3 class="text-base font-semibold text-neutral-900">{{ t('bank_notice_guide.step1_title') }}</h3>
        <p>{{ t('bank_notice_guide.step1_intro') }}</p>

        <div class="border border-neutral-200 rounded-md">
          <button type="button" data-testid="guide-bank-airbank" :aria-expanded="airbankOpen"
            @click="airbankOpen = !airbankOpen"
            class="cursor-pointer w-full px-4 py-2 flex items-center justify-between text-left font-medium hover:bg-neutral-50">
            <span>
              {{ t('bank_notice_guide.bank_airbank') }}
              <span v-if="bank === 'airbank'" class="ml-2 text-xs font-normal text-success-600">{{ t('bank_notice_guide.bank_airbank_selected') }}</span>
            </span>
            <span aria-hidden="true">{{ airbankOpen ? '−' : '+' }}</span>
          </button>
          <div v-if="airbankOpen" data-testid="guide-airbank-body" class="px-4 pb-4 space-y-3">
            <ol class="list-decimal pl-5 space-y-1.5">
              <li v-for="k in airBankSteps" :key="k">{{ t(`bank_notice_guide.${k}`) }}</li>
            </ol>
            <p class="text-xs text-neutral-500">{{ t('bank_notice_guide.ab_note') }}</p>
            <p class="bg-neutral-50 border border-neutral-200 rounded-md px-3 py-2">{{ t('bank_notice_guide.ab_mail') }}</p>
          </div>
        </div>

        <div class="border border-neutral-200 rounded-md px-4 py-3">
          <div class="font-medium">{{ t('bank_notice_guide.bank_other_title') }}</div>
          <p class="mt-1">{{ t('bank_notice_guide.bank_other_text') }}</p>
        </div>
      </div>

      <div data-testid="guide-step-2" class="space-y-3">
        <h3 class="text-base font-semibold text-neutral-900">{{ t('bank_notice_guide.step2_title') }}</h3>
        <p>{{ t('bank_notice_guide.step2_intro') }}</p>
        <div class="bg-neutral-50 border border-neutral-200 rounded-md px-3 py-2">
          <div class="font-medium">{{ t('bank_notice_guide.mb_catchall_title') }}</div>
          <p class="mt-1">{{ t('bank_notice_guide.mb_catchall_text') }}</p>
        </div>
        <p>{{ t('bank_notice_guide.mb_scope_note') }}</p>
        <p>{{ t('bank_notice_guide.mb_limit_note') }}</p>

        <div class="grid md:grid-cols-2 gap-3">
          <div v-for="p in providers" :key="p.key" :data-testid="`guide-mail-${p.key}`"
            class="border rounded-md px-4 py-3 space-y-1.5"
            :class="mailProvider === p.key ? 'border-primary-500 ring-1 ring-primary-500' : 'border-neutral-200'">
            <div class="font-medium">
              {{ t(`bank_notice_guide.${p.title}`) }}
              <span v-if="mailProvider === p.key" class="ml-2 text-xs font-normal text-primary-600">{{ t('bank_notice_guide.mb_detected') }}</span>
            </div>
            <p v-for="l in p.lines" :key="l">{{ t(`bank_notice_guide.${l}`) }}</p>
          </div>
        </div>
      </div>

      <div data-testid="guide-step-3" class="space-y-3">
        <h3 class="text-base font-semibold text-neutral-900">{{ t('bank_notice_guide.step3_title') }}</h3>
        <p>{{ t('bank_notice_guide.step3_intro') }}</p>
        <ol class="list-decimal pl-5 space-y-1.5">
          <li v-for="k in step3" :key="k">{{ t(`bank_notice_guide.${k}`) }}</li>
        </ol>
      </div>

      <div data-testid="guide-step-4" class="space-y-3">
        <h3 class="text-base font-semibold text-neutral-900">{{ t('bank_notice_guide.step4_title') }}</h3>
        <ol class="list-decimal pl-5 space-y-1.5">
          <li v-for="k in verify" :key="k">{{ t(`bank_notice_guide.${k}`) }}</li>
        </ol>
        <div class="font-medium">{{ t('bank_notice_guide.ts_title') }}</div>
        <ul class="list-disc pl-5 space-y-1.5">
          <li v-for="k in troubles" :key="k">{{ t(`bank_notice_guide.${k}`) }}</li>
        </ul>
        <p class="text-xs text-neutral-500">{{ t('bank_notice_guide.ts_6') }}</p>
      </div>
    </div>
  </section>
</template>
