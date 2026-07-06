<template>
  <n-space v-if="!loaded" justify="center" align="center" :style="{ minHeight: '420px' }">
    <n-spin size="large" />
  </n-space>

  <n-space v-else-if="!hasActiveRootWallet" justify="center" :style="{ width: '100%' }">
    <n-card :bordered="false" :style="{ width: '760px', maxWidth: '100%' }">
      <n-space vertical size="large">
        <n-space vertical align="center" size="small">
          <n-gradient-text type="info" :size="28">初始化根钱包</n-gradient-text>
        </n-space>

        <n-form :model="initForm" label-placement="top">
          <n-form-item label="根钱包名称">
            <n-input v-model:value="initForm.name" placeholder="例如：default" />
          </n-form-item>

          <n-form-item label="助记词（可选，留空则自动生成）">
            <n-grid cols="3" responsive="screen" :x-gap="10" :y-gap="10">
              <n-gi v-for="(_, index) in mnemonicWords" :key="index">
                <n-input
                  v-model:value="mnemonicWords[index]"
                  type="password"
                  show-password-on="click"
                  :placeholder="`第 ${index + 1} 个单词`"
                  clearable
                  @keydown.enter="initialize"
                />
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
    <n-card :bordered="false" content-style="padding: 20px;">
      <template #header>
        <n-space vertical :size="2">
          <n-text strong>钱包概览</n-text>
        </n-space>
      </template>
      <template #header-extra>
        <n-space>
          <NetworkTag v-for="item in supportedNetworks" :key="item.value" :code="item.value" :label="networkLabel(item.value, item.label)" />
          <TokenInline code="USDC" />
          <TokenInline code="USDT" />
        </n-space>
      </template>
      <n-grid cols="1 s:2 m:3 xl:6" responsive="screen" :x-gap="14" :y-gap="14">
        <n-gi v-for="item in summaryCards" :key="item.label">
          <n-card :bordered="false" :style="statCardStyle(item.color)" content-style="padding: 16px;">
            <n-space justify="space-between" align="center" :wrap="false">
              <n-space vertical :size="2">
                <n-text depth="3">{{ item.label }}</n-text>
                <n-statistic :value="item.value" />
                <n-text depth="3">{{ item.caption }}</n-text>
              </n-space>
              <n-avatar round :color="item.color" :size="40">
                <n-icon size="22"><component :is="item.icon" /></n-icon>
              </n-avatar>
            </n-space>
          </n-card>
        </n-gi>
      </n-grid>
    </n-card>

    <n-alert v-if="mnemonic" type="success" title="新助记词" :bordered="false">
      <n-space vertical>
        <n-text code>{{ mnemonic }}</n-text>
      </n-space>
    </n-alert>

    <n-card :bordered="false" content-style="padding-top: 4px;">
      <template #header>
        <n-space vertical :size="2">
          <n-text strong>根钱包</n-text>
        </n-space>
      </template>
      <template #header-extra>
        <n-button quaternary type="primary" :loading="loading" @click="load">
          <template #icon><n-icon><RefreshOutline /></n-icon></template>
          刷新
        </n-button>
      </template>
      <n-data-table :columns="masterColumns" :data="masters" :loading="loading" :scroll-x="980" :pagination="{ pageSize: 10 }" />
    </n-card>

    <n-card :bordered="false" content-style="padding-top: 4px;">
      <template #header>
        <n-space vertical :size="2">
          <n-text strong>网络账户总览</n-text>
        </n-space>
      </template>
      <template #header-extra>
        <n-space align="center">
          <n-tag type="info" :bordered="false">已创建 {{ accounts.length }} 个</n-tag>
          <n-button type="primary" secondary :disabled="availableNetworks.length === 0" @click="openCreateAccount">
            <template #icon><n-icon><AddCircleOutline /></n-icon></template>
            添加网络账户
          </n-button>
        </n-space>
      </template>
      <n-grid v-if="accounts.length" cols="1" responsive="screen" :y-gap="18">
        <n-gi v-for="account in accounts" :key="account.id">
          <n-card :bordered="false" :style="networkCardStyle(account.network_code)" content-style="padding: 20px;">
            <template #header>
              <n-space align="center" :wrap="false">
                <n-space vertical :size="2">
                  <NetworkTag :code="account.network_code" :label="networkLabel(account.network_code, account.network_name || account.network_code)" />
                  <n-space size="small">
                    <n-tag size="small" :type="networkTagType(account.network_code)" :bordered="false">Chain ID：{{ account.chain_id || '-' }}</n-tag>
                    <n-tag size="small" type="default" :bordered="false">超时 {{ account.deposit_timeout_minutes || 10 }} 分钟</n-tag>
                    <n-button size="tiny" text type="success" :disabled="accountDisabled(account)" @click="openCollectionRecords(account.network_code)">
                      归集 {{ account.collection_stats.collected }}
                    </n-button>
                  </n-space>
                </n-space>
              </n-space>
            </template>
            <template #header-extra>
              <n-space align="center" :wrap="false">
                <n-switch
                  :value="account.status === 'active'"
                  :loading="accountToggleLoading[account.id]"
                  @update:value="(value) => toggleAccount(account, value)"
                />
                <n-tag :type="account.status === 'active' ? 'success' : 'default'" :bordered="false">{{ statusText(account.status) }}</n-tag>
                <n-button size="tiny" secondary :disabled="accountDisabled(account)" @click="openAccountSetting(account)">
                  <template #icon><n-icon><SettingsOutline /></n-icon></template>
                  设置
                </n-button>
              </n-space>
            </template>

            <n-grid cols="1 l:3" responsive="screen" :x-gap="16" :y-gap="16" :style="accountPanelStateStyle(account)">
              <n-gi>
                <n-card :bordered="false" :style="panelCardStyle('#2563eb')" content-style="padding: 18px;">
                  <n-thing>
                  <template #avatar>
                    <n-avatar round color="#2563eb"><n-icon><LayersOutline /></n-icon></n-avatar>
                  </template>
                  <template #header>入账地址分支</template>
                  <n-space vertical size="small">
                    <n-space size="small">
                      <n-tag type="info" :bordered="false">总数 {{ account.address_stats.total }}</n-tag>
                      <n-tag type="success" :bordered="false">可用 {{ account.address_stats.available }}</n-tag>
                      <n-tag type="warning" :bordered="false">已分配 {{ account.address_stats.assigned + account.address_stats.paid_detected }}</n-tag>
                    </n-space>
                    <n-progress type="line" :percentage="addressUsagePercent(account)" :height="10" :show-indicator="false" />
                  </n-space>
                  </n-thing>
                </n-card>
              </n-gi>

              <n-gi>
                <n-card :bordered="false" :style="panelCardStyle('#16a34a')" content-style="padding: 18px;">
                  <n-thing>
                  <template #avatar>
                    <n-avatar round color="#16a34a"><n-icon><CashOutline /></n-icon></n-avatar>
                  </template>
                  <template #header>归集钱包</template>
                  <template #header-extra>
                    <n-space align="center" size="small" :wrap="false">
                      <TokenAmount
                        v-for="item in balanceTokenItems(account, 'collection')"
                        :key="item.token_code"
                        :amount="item.token_balance"
                        :code="item.token_code"
                      />
                      <n-tag size="small" type="default" :bordered="false">{{ balanceNativeSymbol(account, 'collection') }}：{{ balanceNativeDisplay(account, 'collection') }}</n-tag>
                      <n-button
                        circle
                        quaternary
                        size="tiny"
                        :disabled="!account.collection_address"
                        :loading="balanceLoading[balanceKey(account, 'collection')]"
                        @click="toggleBalance(account, 'collection')"
                      >
                        <template #icon>
                          <n-icon><component :is="isBalanceVisible(account, 'collection') ? EyeOffOutline : EyeOutline" /></n-icon>
                        </template>
                      </n-button>
                    </n-space>
                  </template>
                  <n-space vertical size="small">
                    <n-ellipsis style="max-width: 100%;"><n-text>{{ account.collection_address || '-' }}</n-text></n-ellipsis>
                    <n-space size="small">
                      <n-tag size="small" :type="account.collection_type === 'exchange' ? 'warning' : 'success'" :bordered="false">
                        {{ collectionTypeText(account.collection_type) }}
                      </n-tag>
                      <n-button size="tiny" quaternary @click="copy(account.collection_address)">
                        <template #icon><n-icon><CopyOutline /></n-icon></template>
                        复制地址
                      </n-button>
                      <n-button size="tiny" text type="success" @click="openCollectionRecords(account.network_code)">
                        已归集 {{ account.collection_stats.collected }}
                      </n-button>
                      <n-button size="tiny" secondary type="primary" @click="openCollectionTarget(account)">
                        修改
                      </n-button>
                    </n-space>
                  </n-space>
                  </n-thing>
                </n-card>
              </n-gi>

              <n-gi>
                <n-card :bordered="false" :style="panelCardStyle('#f59e0b')" content-style="padding: 18px;">
                  <n-thing>
                  <template #avatar>
                    <n-avatar round color="#f59e0b"><n-icon><FlashOutline /></n-icon></n-avatar>
                  </template>
                  <template #header>Gas 钱包</template>
                  <template #header-extra>
                    <n-space align="center" size="small" :wrap="false">
                      <n-tag size="small" type="warning" :bordered="false">{{ balanceNativeSymbol(account, 'gas') }}：{{ balanceNativeDisplay(account, 'gas') }}</n-tag>
                      <n-button
                        circle
                        quaternary
                        size="tiny"
                        :disabled="!globalGasAddress()"
                        :loading="balanceLoading[balanceKey(account, 'gas')]"
                        @click="toggleBalance(account, 'gas')"
                      >
                        <template #icon>
                          <n-icon><component :is="isBalanceVisible(account, 'gas') ? EyeOffOutline : EyeOutline" /></n-icon>
                        </template>
                      </n-button>
                    </n-space>
                  </template>
                  <n-space vertical size="small">
                    <n-ellipsis style="max-width: 100%;"><n-text>{{ globalGasAddress() || '-' }}</n-text></n-ellipsis>
                    <n-space size="small">
                      <n-button size="tiny" quaternary :disabled="!globalGasAddress()" @click="copy(globalGasAddress())">
                        <template #icon><n-icon><CopyOutline /></n-icon></template>
                        复制地址
                      </n-button>
                      <n-tag type="warning" :bordered="false">需人工充值原生币</n-tag>
                    </n-space>
                  </n-space>
                  </n-thing>
                </n-card>
              </n-gi>
            </n-grid>
          </n-card>
        </n-gi>
      </n-grid>
      <n-empty v-else description="还没有网络账户，请点击添加网络账户" />
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
      <n-form :model="deleteForm" label-placement="top">
        <n-form-item label="根钱包 ID"><n-input-number v-model:value="deleteForm.wallet_master_id" disabled /></n-form-item>
        <n-form-item label="助记词（空格分割）"><n-input v-model:value="deleteForm.mnemonic" type="password" show-password-on="click" placeholder="请输入完整 12 个助记词" /></n-form-item>
      </n-form>
      <template #action>
        <n-space justify="end">
          <n-button @click="closeDelete">取消</n-button>
          <n-button type="error" :loading="deleting" @click="deleteRootWallet">验证并删除</n-button>
        </n-space>
      </template>
    </n-modal>

    <n-modal v-model:show="accountCreateShow" preset="dialog" title="添加网络账户">
      <n-form :model="accountCreateForm" label-placement="top">
        <n-form-item label="选择网络">
          <n-select
            v-model:value="accountCreateForm.network_code"
            :options="availableNetworks"
            placeholder="请选择要添加的网络"
            :render-label="renderNetworkSelectLabel"
            :render-tag="renderNetworkSelectTag"
          />
        </n-form-item>
      </n-form>
      <template #action>
        <n-space justify="end">
          <n-button @click="accountCreateShow = false">取消</n-button>
          <n-button type="primary" :loading="accountCreating" :disabled="!accountCreateForm.network_code" @click="createAccount">
            确认添加
          </n-button>
        </n-space>
      </template>
    </n-modal>

    <n-modal v-model:show="accountSettingShow" preset="dialog" title="网络账户设置">
      <n-form :model="accountForm" label-placement="top">
        <n-form-item label="网络账户">
          <NetworkTag :code="accountForm.network_code" :label="networkLabel(accountForm.network_code)" />
        </n-form-item>
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

    <n-modal v-model:show="collectionTargetShow" preset="dialog" title="修改归集目标">
      <n-form :model="collectionTargetForm" label-placement="top">
        <n-form-item label="网络账户">
          <NetworkTag :code="collectionTargetForm.network_code" :label="networkLabel(collectionTargetForm.network_code)" />
        </n-form-item>
        <n-form-item label="归集类型">
          <n-radio-group v-model:value="collectionTargetForm.collection_type">
            <n-space>
              <n-radio value="local">本地根钱包派生</n-radio>
              <n-radio value="exchange">交易所/第三方地址</n-radio>
            </n-space>
          </n-radio-group>
        </n-form-item>
        <n-form-item v-if="collectionTargetForm.collection_type === 'exchange'" label="交易所/第三方归集地址">
          <n-input
            v-model:value="collectionTargetForm.collection_address"
            placeholder="请输入当前网络的交易所/第三方 EVM 地址，必须是 0x 开头的地址"
            clearable
          />
        </n-form-item>
      </n-form>
      <template #action>
        <n-space justify="end">
          <n-button @click="collectionTargetShow = false">取消</n-button>
          <n-button type="primary" :loading="collectionTargetSaving" :disabled="!canSaveCollectionTarget()" @click="saveCollectionTarget">
            保存归集目标
          </n-button>
        </n-space>
      </template>
    </n-modal>

  </n-space>
</template>

<script setup lang="ts">
import { computed, h, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { NButton, NIcon, NSpace, NTag, NText, useMessage } from 'naive-ui'
import {
  AddCircleOutline,
  CashOutline,
  CheckmarkCircleOutline,
  CopyOutline,
  EyeOffOutline,
  EyeOutline,
  FlashOutline,
  GitNetworkOutline,
  KeyOutline,
  LayersOutline,
  RefreshOutline,
  SettingsOutline,
  ShieldCheckmarkOutline,
  TrashOutline,
  WalletOutline,
  WarningOutline
} from '@vicons/ionicons5'
import { api } from '../api'
import { NetworkTag, networkColor, networkLabel, networkTagType, renderNetworkSelectLabel, renderNetworkSelectTag } from '../utils/networks'
import { TokenAmount, TokenInline } from '../utils/money'

type BalanceType = 'collection' | 'gas'

const message = useMessage()
const router = useRouter()
const loaded = ref(false)
const loading = ref(false)
const initializing = ref(false)
const mnemonic = ref('')
const exportShow = ref(false)
const exporting = ref(false)
const exportResult = ref<any>({})
const deleteShow = ref(false)
const deleting = ref(false)
const accountSettingShow = ref(false)
const accountSaving = ref(false)
const accountCreateShow = ref(false)
const accountCreating = ref(false)
const collectionTargetShow = ref(false)
const collectionTargetSaving = ref(false)
const initForm = reactive({ name: 'default', mnemonic: '' })
const mnemonicWords = ref<string[]>(Array.from({ length: 12 }, () => ''))
const exportForm = reactive<any>({ wallet_master_id: null, mnemonic: '' })
const deleteForm = reactive<any>({ wallet_master_id: null, mnemonic: '' })
const accountForm = reactive<any>({ id: null, network_code: '', deposit_timeout_minutes: 10 })
const accountCreateForm = reactive<any>({ network_code: null })
const collectionTargetForm = reactive<any>({ id: null, network_code: '', collection_type: 'local', collection_address: '' })
const overview = ref<any>({ masters: [], accounts: [], summary: {} })
const accountBalances = reactive<Record<string, any>>({})
const balanceVisible = reactive<Record<string, boolean>>({})
const balanceLoading = reactive<Record<string, boolean>>({})
const accountToggleLoading = reactive<Record<number, boolean>>({})

const masters = computed(() => overview.value.masters || [])
const accounts = computed(() => overview.value.accounts || [])
const globalGasWallet = computed(() => overview.value.global_gas_wallet || null)
const supportedNetworks = computed(() => overview.value.supported_networks || [])
const availableNetworks = computed(() => overview.value.available_networks || [])
const hasActiveRootWallet = computed(() => masters.value.some((item: any) => item.status === 'active'))
const summary = computed(() => overview.value.summary || {})
const summaryCards = computed(() => [
  { label: '根钱包', value: summary.value.root_wallets || 0, caption: '系统只允许 1 个', color: '#2563eb', icon: WalletOutline },
  { label: '网络账户', value: summary.value.network_accounts || 0, caption: `启用 ${summary.value.network_accounts_active || 0} 个`, color: '#7c3aed', icon: GitNetworkOutline },
  { label: '入账地址', value: summary.value.addresses_total || 0, caption: `可用 ${summary.value.addresses_available || 0}`, color: '#0891b2', icon: LayersOutline },
  { label: '已分配地址', value: summary.value.addresses_assigned || 0, caption: '等待用户付款', color: '#f59e0b', icon: WarningOutline },
  { label: '待归集任务', value: summary.value.collections_pending || 0, caption: '含补 Gas / 归集中', color: '#dc2626', icon: FlashOutline },
  { label: '已归集任务', value: summary.value.collections_done || 0, caption: '稳定币已进入归集钱包', color: '#16a34a', icon: CheckmarkCircleOutline }
])

function statCardStyle(color: string) {
  return {
    background: '#ffffff',
    border: `1px solid ${hexToRgba(color, 0.16)}`,
    boxShadow: '0 8px 22px rgba(15, 23, 42, 0.04)'
  }
}

function networkCardStyle(networkCode: string) {
  const color = networkColor(networkCode)
  return {
    background: '#ffffff',
    border: `1px solid ${hexToRgba(color, 0.18)}`,
    boxShadow: '0 10px 28px rgba(15, 23, 42, 0.05)'
  }
}

function panelCardStyle(color: string) {
  return {
    background: hexToRgba(color, 0.035),
    border: `1px solid ${hexToRgba(color, 0.16)}`
  }
}

function hexToRgba(hex: string, alpha: number) {
  const value = hex.replace('#', '')
  const r = parseInt(value.slice(0, 2), 16)
  const g = parseInt(value.slice(2, 4), 16)
  const b = parseInt(value.slice(4, 6), 16)
  return `rgba(${r}, ${g}, ${b}, ${alpha})`
}

const masterColumns = [
  { title: 'ID', key: 'id', width: 90 },
  { title: '名称', key: 'name', width: 160 },
  { title: '助记词指纹', key: 'mnemonic_fingerprint', width: 360, ellipsis: { tooltip: true } },
  { title: '状态', key: 'status', width: 120, render: (row: any) => h(NTag, { type: row.status === 'active' ? 'success' : 'default', bordered: false }, { default: () => statusText(row.status) }) },
  { title: '创建时间', key: 'created_at', width: 180 },
  {
    title: '操作',
    key: 'actions',
    width: 260,
    render: (row: any) => h(NSpace, null, {
      default: () => [
        h(NButton, { size: 'small', type: 'warning', secondary: true, onClick: () => openExport(row) }, { icon: () => h(NIcon, null, { default: () => h(ShieldCheckmarkOutline) }), default: () => '导出密钥' }),
        h(NButton, { size: 'small', type: 'error', secondary: true, onClick: () => openDelete(row) }, { icon: () => h(NIcon, null, { default: () => h(TrashOutline) }), default: () => '删除根钱包' })
      ]
    })
  }
]

async function load() {
  loading.value = true
  try {
    overview.value = await api.get('/admin/wallet/overview')
  } catch (e: any) {
    message.error(e.message)
  } finally {
    loading.value = false
    loaded.value = true
  }
}

function openCollectionRecords(networkCode: string) {
  router.push({ path: '/hdupay/collections', query: { network_code: networkCode } })
}

function collectionTypeText(type: string) {
  return type === 'exchange' ? '交易所' : '本地'
}

function balanceKey(account: any, type: BalanceType) {
  return `${account.network_code}:${type}`
}

function globalGasAddress() {
  return globalGasWallet.value?.address_lower || globalGasWallet.value?.address || ''
}

function isBalanceVisible(account: any, type: BalanceType) {
  return balanceVisible[balanceKey(account, type)] === true
}

function balanceNativeSymbol(account: any, type: BalanceType) {
  return accountBalances[balanceKey(account, type)]?.native_symbol || account.native_symbol || networkNativeSymbol(account.network_code)
}

function networkNativeSymbol(networkCode: string) {
  const map: Record<string, string> = {
    ethereum: 'ETH',
    base: 'ETH',
    celo: 'CELO',
    polygon: 'POL'
  }
  return map[String(networkCode || '').toLowerCase()] || 'ETH'
}

function balanceNativeDisplay(account: any, type: BalanceType) {
  const key = balanceKey(account, type)
  if (!balanceVisible[key]) {
    return '******'
  }
  return accountBalances[key]?.native_balance || '-'
}

function balanceTokenDisplay(account: any, type: BalanceType) {
  const key = balanceKey(account, type)
  if (!balanceVisible[key]) {
    return '******'
  }
  return accountBalances[key]?.token_balance || '-'
}

function balanceTokenItems(account: any, type: BalanceType) {
  const key = balanceKey(account, type)
  if (!balanceVisible[key]) {
    return [
      { token_code: 'USDC', token_balance: '******' },
      { token_code: 'USDT', token_balance: '******' }
    ]
  }
  const items = accountBalances[key]?.token_balances
  if (Array.isArray(items) && items.length) {
    return items.map((item: any) => ({
      token_code: item.token_code || 'USDC',
      token_balance: item.token_balance || '-'
    }))
  }
  return [{ token_code: 'USDC', token_balance: balanceTokenDisplay(account, type) }]
}

async function toggleBalance(account: any, type: BalanceType) {
  const key = balanceKey(account, type)
  if (balanceVisible[key]) {
    balanceVisible[key] = false
    return
  }
  if (accountBalances[key]) {
    balanceVisible[key] = true
    return
  }

  balanceLoading[key] = true
  try {
    const query = new URLSearchParams()
    query.set('network_code', account.network_code)
    query.set('type', type)
    accountBalances[key] = await api.get('/admin/wallet/account/balance?' + query.toString())
    balanceVisible[key] = true
  } catch (e: any) {
    message.error(e.message)
  } finally {
    balanceLoading[key] = false
  }
}

async function initialize() {
  const words = mnemonicWords.value.map(word => word.trim()).filter(Boolean)
  if (words.length > 0 && words.length !== 12) {
    message.error('如果手动填写助记词，必须完整填写 12 个单词')
    return
  }
  initForm.mnemonic = words.length === 12 ? words.join(' ') : ''

  initializing.value = true
  try {
    const data: any = await api.post('/admin/wallet/initialize', initForm)
    mnemonic.value = data.mnemonic || ''
    clearMnemonicWords()
    message.success('根钱包初始化成功')
    await load()
  } catch (e: any) {
    message.error(e.message)
  } finally {
    initializing.value = false
  }
}

function clearMnemonicWords() {
  mnemonicWords.value = Array.from({ length: 12 }, () => '')
}

function openExport(row: any) {
  exportForm.wallet_master_id = row.id
  exportForm.mnemonic = ''
  exportResult.value = {}
  exportShow.value = true
}

function closeExport() {
  exportShow.value = false
  exportForm.mnemonic = ''
  exportResult.value = {}
}

async function exportRootPrivateKey() {
  exporting.value = true
  try {
    exportResult.value = await api.post('/admin/wallet/root-private-key/export', exportForm)
    message.success('导出成功，请立即离线保存')
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
  deleting.value = true
  try {
    const data: any = await api.post('/admin/wallet/root/delete', deleteForm)
    message.success(`根钱包已删除，已删除 ${data.deleted_network_accounts || 0} 个网络账户`)
    closeDelete()
    await load()
  } catch (e: any) {
    message.error(e.message)
  } finally {
    deleting.value = false
  }
}

function openCreateAccount() {
  accountCreateForm.network_code = availableNetworks.value[0]?.value || null
  accountCreateShow.value = true
}

async function createAccount() {
  if (!accountCreateForm.network_code) {
    message.error('请选择要添加的网络')
    return
  }
  accountCreating.value = true
  try {
    await api.post('/admin/wallet/account/create', {
      network_code: accountCreateForm.network_code
    })
    message.success('网络账户已添加')
    accountCreateShow.value = false
    await load()
  } catch (e: any) {
    message.error(e.message)
  } finally {
    accountCreating.value = false
  }
}

async function toggleAccount(account: any, enabled: boolean) {
  accountToggleLoading[account.id] = true
  try {
    await api.post('/admin/wallet/account/toggle', {
      id: account.id,
      enabled
    })
    message.success(enabled ? '网络账户已启用' : '网络账户已停用')
    await load()
  } catch (e: any) {
    message.error(e.message)
  } finally {
    accountToggleLoading[account.id] = false
  }
}

function openAccountSetting(account: any) {
  accountForm.id = account.id
  accountForm.network_code = account.network_code
  accountForm.deposit_timeout_minutes = Number(account.deposit_timeout_minutes || 10)
  accountSettingShow.value = true
}

async function saveAccountSetting() {
  accountSaving.value = true
  try {
    await api.post('/admin/wallet/account/save', {
      id: accountForm.id,
      deposit_timeout_minutes: accountForm.deposit_timeout_minutes
    })
    message.success('网络账户设置已保存')
    accountSettingShow.value = false
    await load()
  } catch (e: any) {
    message.error(e.message)
  } finally {
    accountSaving.value = false
  }
}

function openCollectionTarget(account: any) {
  collectionTargetForm.id = account.id
  collectionTargetForm.network_code = account.network_code
  collectionTargetForm.collection_type = account.collection_type === 'exchange' ? 'exchange' : 'local'
  collectionTargetForm.collection_address = account.collection_address || ''
  collectionTargetShow.value = true
}

function canSaveCollectionTarget() {
  if (collectionTargetForm.collection_type === 'local') {
    return Boolean(collectionTargetForm.id)
  }
  return Boolean(collectionTargetForm.id) && isValidEvmAddress(collectionTargetForm.collection_address)
}

async function saveCollectionTarget() {
  collectionTargetSaving.value = true
  try {
    await api.post('/admin/wallet/account/collection-target/save', {
      id: collectionTargetForm.id,
      collection_type: collectionTargetForm.collection_type,
      collection_address: collectionTargetForm.collection_address
    })
    clearBalanceCache(collectionTargetForm.network_code, 'collection')
    message.success('归集目标已保存')
    collectionTargetShow.value = false
    await load()
  } catch (e: any) {
    message.error(e.message)
  } finally {
    collectionTargetSaving.value = false
  }
}

function isValidEvmAddress(address: string) {
  return /^0x[a-fA-F0-9]{40}$/.test(String(address || '').trim())
}

function clearBalanceCache(networkCode: string, type: BalanceType) {
  const key = `${networkCode}:${type}`
  delete accountBalances[key]
  delete balanceVisible[key]
}

async function copy(value: string) {
  if (!value) {
    return
  }
  try {
    await navigator.clipboard.writeText(value)
    message.success('地址已复制')
  } catch {
    message.error('复制失败，请手动复制')
  }
}

function statusText(status: string) {
  const map: Record<string, string> = { active: '启用', disabled: '停用' }
  return map[status] || status || '-'
}

function accountDisabled(account: any) {
  return account.status !== 'active'
}

function accountPanelStateStyle(account: any) {
  if (!accountDisabled(account)) {
    return {}
  }
  return {
    filter: 'blur(3px)',
    opacity: 0.48,
    pointerEvents: 'none',
    userSelect: 'none'
  }
}

function addressUsagePercent(account: any) {
  const stats = account.address_stats || {}
  const total = Number(stats.total || 0)
  if (total <= 0) return 0
  const used = Number(stats.assigned || 0) + Number(stats.paid_detected || 0) + Number(stats.frozen || 0) + Number(stats.collected || 0)
  return Math.min(100, Math.round((used / total) * 100))
}

onMounted(load)
</script>
