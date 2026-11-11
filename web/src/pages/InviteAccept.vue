<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import AppShell from '@/components/layout/AppShell.vue'
import { authApi, type InviteInfo } from '@/api/auth'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toast = useToast()

type Phase = 'loading' | 'ready' | 'dead' | 'throttled'

const token = ref('')
const phase = ref<Phase>('loading')
const info = ref<InviteInfo | null>(null)
const password = ref('')
const passwordConfirm = ref('')
const submitting = ref(false)
const error = ref('')

onMounted(async () => {
  token.value = typeof route.query.token === 'string' ? route.query.token : ''
  if (!token.value) {
    phase.value = 'dead'
    return
  }
  try {
    info.value = await authApi.inviteInfo(token.value)
    phase.value = 'ready'
  } catch (e: any) {
    phase.value = e?.response?.status === 429 ? 'throttled' : 'dead'
  }
})

const minLength = computed(() => info.value?.password_min_length ?? 12)
const maxLength = computed(() => info.value?.password_max_length ?? 128)
const passwordOk = computed(() => password.value.length >= minLength.value && password.value.length <= maxLength.value)
const passwordMatch = computed(() => password.value === passwordConfirm.value)
const valid = computed(() => passwordOk.value && passwordMatch.value)

async function submit() {
  if (!valid.value || submitting.value) return
  submitting.value = true
  error.value = ''
  try {
    const user = await auth.acceptInvite(token.value, password.value, passwordConfirm.value)
    if (user === null) {
      // The account already owns a passkey/TOTP: the password is set, but the person signs in as usual.
      toast.success(t('user_invite.password_set_sign_in'))
      router.replace('/login')
      return
    }
    router.replace((auth.mustSetupMfa || auth.mustSetupTotp) ? '/setup-mfa' : '/')
  } catch (e: any) {
    const code = e?.response?.data?.error?.code
    if (code === 'invite_invalid') phase.value = 'dead'
    else if (e?.response?.status === 429) phase.value = 'throttled'
    else error.value = e?.response?.data?.error?.message || t('errors.generic')
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <AppShell :title="t('user_invite.title')">
    <div class="w-full max-w-sm">
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-6">
        <p v-if="phase === 'loading'" class="text-sm text-neutral-500">{{ t('user_invite.loading') }}</p>

        <div v-else-if="phase === 'dead'" data-testid="invite-dead">
          <h2 class="text-xl font-semibold mb-2">{{ t('user_invite.dead_link_title') }}</h2>
          <p class="text-sm text-neutral-700">{{ t('user_invite.dead_link') }}</p>
          <RouterLink to="/login" class="inline-block mt-4 text-sm text-primary-600 hover:text-primary-700">
            {{ t('user_invite.to_login') }}
          </RouterLink>
        </div>

        <div v-else-if="phase === 'throttled'">
          <p class="text-sm text-neutral-700">{{ t('user_invite.too_many') }}</p>
        </div>

        <template v-else>
          <h2 class="text-xl font-semibold mb-1">{{ t('user_invite.welcome', { name: info?.name }) }}</h2>
          <p class="text-sm text-neutral-600">{{ t('user_invite.intro') }}</p>
          <p v-if="info?.email_codes" class="text-xs text-neutral-500 mt-2">{{ t('user_invite.email_codes_note') }}</p>

          <form @submit.prevent="submit" class="space-y-4 mt-4">
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('user_invite.new_password') }}</label>
              <input v-model="password" type="password" required autocomplete="new-password"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
              <p class="text-xs mt-1" :class="password && !passwordOk ? 'text-danger-500' : 'text-neutral-500'">
                {{ t('user_invite.password_rules', { min: minLength, max: maxLength }) }}
              </p>
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('user_invite.new_password_confirm') }}</label>
              <input v-model="passwordConfirm" type="password" required autocomplete="new-password"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
              <p v-if="passwordConfirm && !passwordMatch" class="text-xs text-danger-500 mt-1">{{ t('user_invite.passwords_dont_match') }}</p>
            </div>

            <div v-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
              {{ error }}
            </div>

            <button type="submit" :disabled="!valid || submitting"
              class="w-full h-10 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md cursor-pointer disabled:cursor-not-allowed">
              {{ submitting ? t('user_invite.submitting') : t('user_invite.submit') }}
            </button>
          </form>
        </template>
      </div>
    </div>
  </AppShell>
</template>
