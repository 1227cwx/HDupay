<template>
  <n-space v-if="!loaded" justify="center" align="center" :style="{ minHeight: '420px' }">
    <n-spin size="large" />
  </n-space>

  <n-space v-else-if="!hasActiveRootWallet" justify="center" :style="{ width: '100%' }">
    <n-card :bordered="false" :style="{ width: '760px', maxWidth: '100%' }">
      <n-space vertical size="large">
        <n-space vertical align="center" size="small">
          <n-gradient-text type="info" :size="28">初始化根钱包</n-gradient-text>
          <n-text depth="3">系统只允许存在一个根钱包。请离线备份助记词，丢失后无法恢复资产。</n-text>
        </n-space>
        <n-form :model="initForm" label-placement="top">
          <n-form-item label="根钱包名称">
            <n-input v-model:value="initForm.name" placeholder="例如：default" />
          </n-form-item>
          <n-form-item label="助记词（可选，留空则自动生成）">
            <n-grid cols="3" responsive="screen" :x-gap="10" :y-gap="10">
              <n-gi v-for="(_, index) in mnemonicWords" :key="index">
                <n-input v-model:value="mnemonicWords[index]" type="password" show-password-on="click" :placeholder="`第 ${index + 1} 个单词`" clearable @keydown.enter="initialize" />
              </n-gi>
            </n-grid>
          </n-form-item>
          <n-space justify="space-between" align="center">
            <n-button quaternary @click="clearMnemonicWords">清空助记词</n-button>
            <n-button type="primary" size="large" :loading="initializing" @click="initialize">
              <template #icon><n-icon><KeyOutline /></n-icon></template>
              确认初始化根钱包
            </n-button>
          </n-space>
        </n-form>
      </n-space>
    </n-card>
  </n-space>

  <n-space v-else vertical size="large">
    <n-modal
      v-model:show="mnemonicBackupShow"
      preset="card"
      title="请立即备份根钱包助记词"
      :bordered="false"
      :closable="false"
      :mask-closable="false"
      :close-on-esc="false"
      :style="{ width: '680px', maxWidth: '92vw' }"
    >
      <n-space vertical size="large">
        <n-alert type="warning" title="这是恢复资产的唯一凭证" :bordered="false">
          请将助记词离线抄写并妥善保存。任何人拿到助记词都可以转走资产，丢失后系统无法找回。
        </n-alert>
        <n-grid cols="1 s:2 m:3" responsive="screen" :x-gap="10" :y-gap="10">
          <n-gi v-for="(word, index) in mnemonicWordList" :key="`${index}-${word}`">
            <n-card size="small" :bordered="true" :content-style="mnemonicWordContentStyle">
              <n-text depth="3">{{ index + 1 }}</n-text>
              <n-text strong :style="{ fontSize: '16px' }">{{ word }}</n-text>
            </n-card>
          </n-gi>
        </n-grid>
        <n-alert type="error" title="关闭前请确认已经完成备份" :bordered="false">
          弹窗显示期间刷新或关闭页面会触发浏览器确认提示。倒计时结束后也只能通过下方按钮关闭，点击空白处不会关闭。
        </n-alert>
      </n-space>
      <template #footer>
        <n-space justify="end">
          <n-button type="primary" :disabled="mnemonicCloseSeconds > 0" @click="closeMnemonicBackup">
            {{ mnemonicCloseSeconds > 0 ? `我已保存，${mnemonicCloseSeconds} 秒后可关闭` : '我已保存，关闭弹窗' }}
          </n-button>
        </n-space>
      </template>
    </n-modal>

    <n-card title="根钱包" :bordered="false">
      <template #header-extra>
        <n-button secondary type="primary" :loading="loading" @click="load">
          <template #icon><n-icon><RefreshOutline /></n-icon></template>
          刷新
        </n-button>
      </template>
      <n-data-table :columns="masterColumns" :data="masters" :loading="loading" :scroll-x="900" :pagination="{ pageSize: 10 }" />
    </n-card>

    <n-card title="网络账户" :bordered="false">
      <template #header-extra>
        <n-button type="primary" secondary :disabled="availableNetworks.length === 0" @click="openCreateAccount">
          <template #icon><n-icon><AddCircleOutline /></n-icon></template>
          初始化缺失网络
        </n-button>
      </template>
      <n-data-table :columns="accountColumns" :data="accounts" :loading="loading" :scroll-x="1200" :pagination="{ pageSize: 10 }" />
    </n-card>

    <n-modal v-model:show="exportShow" preset="dialog" title="验证助记词导出根钱包密钥">
      <n-form :model="exportForm" label-placement="top">
        <n-form-item label="根钱包 ID"><n-input-number v-model:value="exportForm.wallet_master_id" disabled /></n-form-item>
        <n-form-item label="助记词"><n-input v-model:value="exportForm.mnemonic" type="password" show-password-on="click" placeholder="请输入完整 12 个助记词" /></n-form-item>
      </n-form>
      <n-alert v-if="exportResult.root_private_key" type="success" title="导出结果" :bordered="false">
        <n-space vertical>
          <n-text>根私钥：</n-text>
          <n-text code>{{ exportResult.root_private_key }}</n-text>
          <n-text>链码：</n-text>
          <n-text code>{{ exportResult.chain_code }}</n-text>
          <n-text>根扩展私钥 xprv：</n-text>
          <n-input :value="exportResult.root_extended_private_key" type="textarea" readonly autosize />
        </n-space>
      </n-alert>
      <template #action>
        <n-space justify="end">
          <n-button @click="closeExport">关闭</n-button>
          <n-button type="primary" :loading="exporting" @click="exportRootPrivateKey">验证并导出</n-button>
        </n-space>
      </template>
    </n-modal>

    <n-modal v-model:show="deleteShow" preset="dialog" title="验证助记词删除根钱包">
      <n-space vertical size="medium">
        <n-alert type="error" title="删除风险提示" :bordered="false">
          <n-space vertical size="small">
            <n-text>删除根钱包将会删除所有交易记录与配置。</n-text>
            <n-text>包含：订单记录、归集记录、归集钱包（这里只删除根钱包生成的归集钱包，自己添加的不删）、Gas 钱包、地址池。</n-text>
            <n-text>保留：RPC 节点、网络配置、代理池、系统设置。</n-text>
            <n-text strong>请确认后删除。</n-text>
          </n-space>
        </n-alert>
        <n-form :model="deleteForm" label-placement="top">
          <n-form-item label="助记词（空格分割）"><n-input v-model:value="deleteForm.mnemonic" type="password" show-password-on="click" placeholder="请输入完整 12 个助记词" /></n-form-item>
        </n-form>
      </n-space>
      <template #action>
        <n-space justify="end">
          <n-button @click="closeDelete">取消</n-button>
          <n-button type="error" :loading="deleting" @click="deleteRootWallet">验证并删除</n-button>
        </n-space>
      </template>
    </n-modal>

    <n-modal v-model:show="accountCreateShow" preset="dialog" title="初始化缺失网络">
      <n-form :model="accountCreateForm" label-placement="top">
        <n-form-item label="选择缺失网络">
          <n-select v-model:value="accountCreateForm.network_code" :options="availableNetworks" placeholder="请选择要初始化的缺失网络" :render-label="renderNetworkSelectLabel" :render-tag="renderNetworkSelectTag" />
        </n-form-item>
      </n-form>
      <template #action>
        <n-space justify="end">
          <n-button @click="accountCreateShow = false">取消</n-button>
          <n-button type="primary" :loading="accountCreating" :disabled="!accountCreateForm.network_code" @click="createAccount">确认初始化</n-button>
        </n-space>
      </template>
    </n-modal>

    <n-modal v-model:show="accountSettingShow" preset="dialog" title="网络账户设置">
      <n-form :model="accountForm" label-placement="top">
        <n-form-item label="网络账户"><NetworkTag :code="accountForm.network_code" :label="networkLabel(accountForm.network_code)" /></n-form-item>
        <n-form-item label="子地址超时时间（分钟）">
          <n-input-number v-model:value="accountForm.deposit_timeout_minutes" :min="1" :max="1440" :step="1" />
        </n-form-item>
      </n-form>
      <template #action>
        <n-space justify="end">
          <n-button @click="accountSettingShow = false">取消</n-button>
          <n-button type="primary" :loading="accountSaving" @click="saveAccountSetting">保存设置</n-button>
        </n-space>
      </template>
    </n-modal>
  </n-space>
</template>

<script setup lang="ts">
import { computed, h, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { NButton, NSpace, NSwitch, NTag, NText, useDialog, useMessage } from 'naive-ui'
import { AddCircleOutline, KeyOutline, RefreshOutline } from '@vicons/ionicons5'
import { api } from '../api'
import { NetworkTag, networkLabel, renderNetworkSelectLabel, renderNetworkSelectTag, renderNetworkTag } from '../utils/networks'
import { renderShortText } from '../utils/shortText'

const message = useMessage()
const dialog = useDialog()
const loaded = ref(false)
const loading = ref(false)
const initializing = ref(false)
const exporting = ref(false)
const deleting = ref(false)
const accountCreating = ref(false)
const accountSaving = ref(false)
const exportShow = ref(false)
const deleteShow = ref(false)
const accountCreateShow = ref(false)
const accountSettingShow = ref(false)
const masters = ref<any[]>([])
const accounts = ref<any[]>([])
const availableNetworks = ref<any[]>([])
const mnemonic = ref('')
const mnemonicWords = ref<string[]>(Array.from({ length: 12 }, () => ''))
const initForm = reactive({ name: 'default' })
const exportForm = reactive<any>({ wallet_master_id: 0, mnemonic: '' })
const deleteForm = reactive<any>({ wallet_master_id: 0, mnemonic: '' })
const exportResult = reactive<any>({})
const accountCreateForm = reactive<any>({ network_code: null })
const accountForm = reactive<any>({ id: 0, network_code: '', deposit_timeout_minutes: 10 })
const accountToggleLoading = reactive<Record<string, boolean>>({})
const mnemonicBackupShow = ref(false)
const mnemonicCloseSeconds = ref(0)
const mnemonicCloseTimer = ref<number | null>(null)
const mnemonicWordContentStyle = { display: 'flex', alignItems: 'center', gap: '10px', padding: '10px 12px' }

const hasActiveRootWallet = computed(() => masters.value.some(master => master.status === 'active'))
const mnemonicWordList = computed(() => String(mnemonic.value || '').trim().split(/\s+/).filter(Boolean))

const masterColumns = [
  { title: '名称', key: 'name', width: 160 },
  { title: '状态', key: 'status', width: 100, render: (row: any) => h(NTag, { type: row.status === 'active' ? 'success' : 'default', bordered: false }, { default: () => row.status === 'active' ? '启用' : row.status }) },
  { title: '助记词指纹', key: 'mnemonic_fingerprint', width: 180, render: (row: any) => renderShortText(row.mnemonic_fingerprint, 12) },
  { title: '创建时间', key: 'created_at', width: 170 },
  {
    title: '操作', key: 'actions', width: 230, fixed: 'right', render: (row: any) => h(NSpace, { wrap: false }, { default: () => [
      h(NButton, { size: 'small', type: 'primary', secondary: true, onClick: () => openExport(row) }, { default: () => '导出密钥' }),
      h(NButton, { size: 'small', type: 'error', secondary: true, onClick: () => openDelete(row) }, { default: () => '删除' })
    ] })
  }
]

const accountColumns = [
  { title: '网络', key: 'network_code', width: 140, render: renderNetworkTag },
  { title: '归集地址', key: 'collection_address', width: 130, render: (row: any) => renderShortText(row.collection_address) },
  { title: '下个地址索引', key: 'next_index', width: 120 },
  { title: '子地址超时', key: 'deposit_timeout_minutes', width: 120, render: (row: any) => `${row.deposit_timeout_minutes || 10} 分钟` },
  {
    title: '启用', key: 'status', width: 120, render: (row: any) => h(NSwitch, {
      value: row.status === 'active',
      loading: accountToggleLoading[row.id],
      'onUpdate:value': (value: boolean) => toggleAccount(row, value)
    })
  },
  { title: '创建时间', key: 'created_at', width: 170 },
  { title: '操作', key: 'actions', width: 120, fixed: 'right', render: (row: any) => h(NButton, { size: 'small', type: 'primary', secondary: true, disabled: row.status !== 'active', onClick: () => openAccountSetting(row) }, { default: () => '设置' }) }
]

async function load() {
  loading.value = true
  try {
    const data: any = await api.get('/admin/wallet/overview')
    masters.value = data.masters || []
    accounts.value = data.accounts || []
    availableNetworks.value = data.available_networks || []
  } catch (e: any) {
    message.error(e.message)
  } finally {
    loading.value = false
    loaded.value = true
  }
}

function clearMnemonicWords() {
  mnemonicWords.value = Array.from({ length: 12 }, () => '')
}

function showMnemonicBackup() {
  mnemonicBackupShow.value = true
  startMnemonicCloseCountdown()
  window.addEventListener('beforeunload', handleMnemonicBeforeUnload)
}

function startMnemonicCloseCountdown() {
  clearMnemonicCloseTimer()
  mnemonicCloseSeconds.value = 10
  mnemonicCloseTimer.value = window.setInterval(() => {
    mnemonicCloseSeconds.value = Math.max(0, mnemonicCloseSeconds.value - 1)
    if (mnemonicCloseSeconds.value <= 0) {
      clearMnemonicCloseTimer()
    }
  }, 1000)
}

function clearMnemonicCloseTimer() {
  if (mnemonicCloseTimer.value !== null) {
    window.clearInterval(mnemonicCloseTimer.value)
    mnemonicCloseTimer.value = null
  }
}

function handleMnemonicBeforeUnload(event: BeforeUnloadEvent) {
  if (!mnemonicBackupShow.value) {
    return
  }
  event.preventDefault()
  event.returnValue = '根钱包助记词尚未确认备份，离开页面可能导致无法再次查看。'
}

function closeMnemonicBackup() {
  if (mnemonicCloseSeconds.value > 0) {
    return
  }
  mnemonicBackupShow.value = false
  mnemonic.value = ''
  clearMnemonicCloseTimer()
  window.removeEventListener('beforeunload', handleMnemonicBeforeUnload)
}

async function initialize() {
  initializing.value = true
  try {
    const words = mnemonicWords.value.map(item => item.trim()).filter(Boolean)
    const data: any = await api.post('/admin/wallet/initialize', { name: initForm.name, mnemonic: words.length ? words.join(' ') : '' })
    mnemonic.value = data.mnemonic || ''
    clearMnemonicWords()
    message.success('根钱包初始化成功，请立即离线备份助记词')
    await load()
    if (mnemonic.value) {
      showMnemonicBackup()
    }
  } catch (e: any) {
    message.error(e.message)
  } finally {
    initializing.value = false
  }
}

function openExport(row: any) {
  Object.assign(exportResult, {})
  exportForm.wallet_master_id = row.id
  exportForm.mnemonic = ''
  exportShow.value = true
}

function closeExport() {
  exportShow.value = false
  exportForm.mnemonic = ''
  Object.keys(exportResult).forEach(key => delete exportResult[key])
}

async function exportRootPrivateKey() {
  exporting.value = true
  try {
    const data: any = await api.post('/admin/wallet/root-private-key/export', exportForm)
    Object.assign(exportResult, data)
  } catch (e: any) {
    message.error(e.message)
  } finally {
    exporting.value = false
  }
}

function openDelete(row: any) {
  deleteForm.wallet_master_id = row.id
  deleteForm.mnemonic = ''
  deleteShow.value = true
}

function closeDelete() {
  deleteShow.value = false
  deleteForm.mnemonic = ''
}

async function deleteRootWallet() {
  if (!String(deleteForm.mnemonic || '').trim()) {
    message.error('请输入完整 12 个助记词')
    return
  }
  dialog.warning({
    title: '二次确认删除根钱包',
    content: () => h(NSpace, { vertical: true, size: 'small' }, {
      default: () => [
        h(NText, null, { default: () => '该操作会删除所有订单记录、归集记录、地址池、Gas 钱包，以及根钱包生成的归集钱包。' }),
        h(NText, { type: 'error', strong: true }, { default: () => '删除后不可恢复，请确认已经离线备份助记词。' })
      ]
    }),
    positiveText: '确认删除',
    negativeText: '取消',
    onPositiveClick: async () => {
      deleting.value = true
      try {
        await api.post('/admin/wallet/root/delete', deleteForm)
        message.success('根钱包已删除')
        closeDelete()
        await load()
      } catch (e: any) {
        message.error(e.message)
      } finally {
        deleting.value = false
      }
    }
  })
}

function openCreateAccount() {
  accountCreateForm.network_code = null
  accountCreateShow.value = true
}

async function createAccount() {
  accountCreating.value = true
  try {
    await api.post('/admin/wallet/account/create', accountCreateForm)
    message.success('缺失网络已初始化')
    accountCreateShow.value = false
    await load()
  } catch (e: any) {
    message.error(e.message)
  } finally {
    accountCreating.value = false
  }
}

async function toggleAccount(row: any, value: boolean) {
  accountToggleLoading[row.id] = true
  try {
    await api.post('/admin/wallet/account/toggle', { id: row.id, enabled: value })
    message.success('网络账户状态已更新')
    await load()
  } catch (e: any) {
    message.error(e.message)
  } finally {
    accountToggleLoading[row.id] = false
  }
}

function openAccountSetting(row: any) {
  Object.assign(accountForm, { id: row.id, network_code: row.network_code, deposit_timeout_minutes: Number(row.deposit_timeout_minutes || 10) })
  accountSettingShow.value = true
}

async function saveAccountSetting() {
  accountSaving.value = true
  try {
    await api.post('/admin/wallet/account/save', accountForm)
    message.success('网络账户设置已保存')
    accountSettingShow.value = false
    await load()
  } catch (e: any) {
    message.error(e.message)
  } finally {
    accountSaving.value = false
  }
}

onMounted(load)
onBeforeUnmount(() => {
  clearMnemonicCloseTimer()
  window.removeEventListener('beforeunload', handleMnemonicBeforeUnload)
})
</script>
