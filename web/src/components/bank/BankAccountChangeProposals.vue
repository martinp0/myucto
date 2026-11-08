<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { settingsApi, type BankAccountChangeProposal } from '@/api/settings'
import { authApi } from '@/api/auth'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import { formatDateTime } from '@/composables/useFormat'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import { getCredential, isWebAuthnAvailable, webAuthnErrorKey } from '@/security/webauthn'
import Modal from '@/components/ui/Modal.vue'
import EmptyState from '@/components/ui/EmptyState.vue'

const { t } = useI18n()
const toast = useToast()
const auth = useAuthStore()

const items = ref<BankAccountChangeProposal[]>([])
const loading = ref(false)
const busy = ref(false)
const passkeyBusy = ref(false)
const active = ref<BankAccountChangeProposal | null>(null)
const decision = ref<'approve' | 'reject' | null>(null)
const reason = ref('')
const totpCode = ref('')
const proof = ref('')
const formError = ref('')

const fields = ['account_number', 'bank_code', 'bank_name', 'iban', 'bic'] as const
const passkeySupported = isWebAuthnAvailable()

onMounted(() => {
  if (auth.isCompanyAdminRole) void load()
})

async function load() {
  loading.value = true
  try {
    items.value = await settingsApi.listBankChangeProposals()
  } catch (e) {
    toast.error(apiErrorMessage(e, t('bank_accounts.proposals_load_failed')))
  } finally {
    loading.value = false
  }
}

function fieldLabel(field: string): string {
  return t(`bank_accounts.proposal_field_${field}`)
}

function shown(item: BankAccountChangeProposal, field: string): string {
  const value = item.proposed[field]
  if (value === undefined) return t('bank_accounts.proposal_unchanged')
  return value || '—'
}

function baseline(item: BankAccountChangeProposal, field: string): string {
  return item.baseline[field] || '—'
}

function open(item: BankAccountChangeProposal, next: 'approve' | 'reject') {
  active.value = item
  decision.value = next
  reason.value = ''
  totpCode.value = ''
  proof.value = ''
  formError.value = ''
}

function close() {
  if (busy.value || passkeyBusy.value) return
  active.value = null
  decision.value = null
}

async function verifyPasskey() {
  if (!active.value || !decision.value || !passkeySupported) return
  passkeyBusy.value = true
  formError.value = ''
  proof.value = ''
  const operation = `bank_account_proposal.${decision.value}:${active.value.id}`
  try {
    const flow = await authApi.passkeyStepUpOptions(operation)
    const credential = await getCredential(flow.public_key)
    proof.value = await authApi.passkeyStepUpVerify(flow.flow_token, operation, credential)
    totpCode.value = ''
  } catch (e) {
    const ceremonyError = webAuthnErrorKey(e)
    formError.value = ceremonyError !== null
      ? t(ceremonyError)
      : apiErrorMessage(e, t('bank_accounts.proposals_passkey_failed'))
  } finally {
    passkeyBusy.value = false
  }
}

async function submit() {
  if (!active.value || !decision.value) return
  busy.value = true
  formError.value = ''
  const operation = `bank_account_proposal.${decision.value}:${active.value.id}`
  try {
    let stepUp = proof.value
    if (!stepUp && /^\d{6}$/.test(totpCode.value)) {
      stepUp = await authApi.totpStepUp(operation, totpCode.value)
    }
    if (!stepUp) {
      formError.value = t('bank_accounts.proposals_step_up_required')
      return
    }
    if (decision.value === 'approve') {
      await settingsApi.approveBankChangeProposal(active.value.id, stepUp)
      toast.success(t('bank_accounts.proposals_approved'))
    } else {
      await settingsApi.rejectBankChangeProposal(active.value.id, stepUp, reason.value.trim())
      toast.success(t('bank_accounts.proposals_rejected'))
    }
    active.value = null
    decision.value = null
    await load()
  } catch (e) {
    proof.value = ''
    totpCode.value = ''
    formError.value = apiErrorMessage(e, t('common.error'))
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
    <header class="px-5 py-3 border-b border-neutral-200">
      <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('bank_accounts.proposals_title') }}</h2>
      <p class="text-xs text-neutral-500 mt-0.5">{{ t('bank_accounts.proposals_subtitle') }}</p>
    </header>
    <p v-if="loading" class="px-5 py-4 text-sm text-neutral-500">{{ t('common.loading') }}</p>
    <EmptyState v-else-if="items.length === 0" dense icon="coin" :title="t('bank_accounts.proposals_empty')" />
    <div v-else class="divide-y divide-neutral-100">
      <article v-for="item in items" :key="item.id" class="px-5 py-4 space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div class="min-w-0">
            <div class="font-medium">{{ item.currency_code || item.currency_id }}</div>
            <p class="text-xs text-neutral-500">
              {{ t('bank_accounts.proposals_origin', {
                prefix: item.origin.token_prefix,
                user: item.origin.user_name || '—',
                ip: item.origin.ip || '—',
                at: formatDateTime(item.created_at),
              }) }}
            </p>
          </div>
          <span class="px-2 py-0.5 rounded text-xs font-medium bg-neutral-100 text-neutral-700">
            {{ t(`bank_accounts.proposal_status_${item.status}`) }}
          </span>
        </div>
        <dl class="grid gap-2 sm:grid-cols-2 text-sm">
          <div v-for="field in fields" :key="field" class="min-w-0">
            <dt class="text-xs text-neutral-500">{{ fieldLabel(field) }}</dt>
            <dd class="font-mono text-xs break-all">
              <span class="text-neutral-500">{{ baseline(item, field) }}</span>
              <span class="text-neutral-400"> → </span>
              <span>{{ shown(item, field) }}</span>
            </dd>
          </div>
        </dl>
        <div v-if="item.status === 'pending'" class="flex flex-wrap gap-2">
          <button type="button" :class="btnFilled('success')" @click="open(item, 'approve')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            <span class="whitespace-nowrap">{{ t('bank_accounts.proposals_approve') }}</span>
          </button>
          <button type="button" :class="btnOutline('danger')" @click="open(item, 'reject')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            <span class="whitespace-nowrap">{{ t('bank_accounts.proposals_reject') }}</span>
          </button>
        </div>
        <p v-if="item.decision_reason" class="text-xs text-neutral-600">{{ item.decision_reason }}</p>
      </article>
    </div>

    <Modal v-if="active && decision" width-class="max-w-md" :title="t(decision === 'approve' ? 'bank_accounts.proposals_approve' : 'bank_accounts.proposals_reject')" @close="close">
      <div class="space-y-3">
        <p class="text-sm text-neutral-600">{{ t('bank_accounts.proposals_step_up_hint') }}</p>
        <label v-if="decision === 'reject'" class="block text-sm">
          <span class="text-neutral-700 font-medium">{{ t('bank_accounts.proposals_reason') }}</span>
          <input v-model="reason" type="text" maxlength="200" class="mt-1 w-full h-10 px-3 border border-neutral-300 rounded-md" />
        </label>
        <div v-if="passkeySupported" class="flex flex-wrap gap-2">
          <button type="button" :class="btnOutline('primary')" :disabled="passkeyBusy || busy" @click="verifyPasskey">
            {{ proof ? t('bank_accounts.proposals_passkey_ok') : t('bank_accounts.proposals_passkey') }}
          </button>
        </div>
        <label class="block text-sm">
          <span class="text-neutral-700 font-medium">{{ t('bank_accounts.proposals_totp') }}</span>
          <input v-model="totpCode" type="text" inputmode="numeric" maxlength="6" autocomplete="one-time-code"
            class="mt-1 w-full h-10 px-3 border border-neutral-300 rounded-md font-mono" />
        </label>
        <p v-if="formError" class="text-sm text-danger-600">{{ formError }}</p>
        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" :disabled="busy" @click="close">{{ t('common.cancel') }}</button>
          <button type="button" :class="decision === 'approve' ? btnFilled('success') : btnFilled('danger')" :disabled="busy" @click="submit">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" :d="decision === 'approve' ? ICONS.check : ICONS.x" />
            </svg>
            <span class="whitespace-nowrap">{{ t(decision === 'approve' ? 'bank_accounts.proposals_approve' : 'bank_accounts.proposals_reject') }}</span>
          </button>
        </div>
      </div>
    </Modal>
  </section>
</template>
