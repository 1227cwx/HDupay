<template>
  <n-space vertical size="large">
    <n-card title="筛选条件" :bordered="false">
      <n-grid cols="1 m:3 xl:6" responsive="screen" :x-gap="12" :y-gap="12">
        <n-gi>
          <n-form-item label="网络" :show-feedback="false">
            <n-select v-model:value="filters.network_code" clearable placeholder="选择网络" :options="networkOptions" :render-label="renderNetworkSelectLabel" :render-tag="renderNetworkSelectTag" />
          </n-form-item>
        </n-gi>
        <n-gi>
          <n-form-item label="状态" :show-feedback="false">
            <n-select v-model:value="filters.status" clearable placeholder="选择状态" :options="statusOptions" />
          </n-form-item>
        </n-gi>
        <n-gi>
          <n-form-item label="任务间隔（秒）" :show-feedback="false">
            <n-input-number
              v-model:value="withdrawConfig.auto_withdraw_interval_seconds"
              :min="1"
              :max="3600"
              :step="1"
              placeholder="默认 10 秒"
              clearable
            />
          </n-form-item>
        </n-gi>
        <n-gi span="1 m:3 xl:3">
          <n-form-item label="操作" :show-feedback="false">
            <n-space>
              <n-button type="primary" :loading="loading" @click="search">
                <template #icon><n-icon><SearchOutline /></n-icon></template>
                查询记录
              </n-button>
              <n-button @click="resetSearch">重置</n-button>
              <n-button type="primary" secondary :loading="configSaving" @click="saveConfig">保存设置</n-button>
              <n-button type="warning" :loading="allProcessing" @click="processAll">
                <template #icon><n-icon><PlayOutline /></n-icon></template>
                全部转出
              </n-button>
              <n-button secondary type="primary" :loading="loading" @click="load()">
                <template #icon><n-icon><RefreshOutline /></n-icon></template>
                刷新
              </n-button>
            </n-space>
          </n-form-item>
        </n-gi>
      </n-grid>
    </n-card>

    <n-card title="转出记录" :bordered="false">
      <n-space vertical size="large">
        <n-data-table :columns="columns" :data="rows" :loading="loading" :scroll-x="1580" />
        <n-space justify="end">
          <n-pagination v-model:page="page" :page-size="perPage" :item-count="total" @update:page="() => load()" />
        </n-space>
      </n-space>
    </n-card>
  </n-space>
</template>

<script setup lang="ts">
import { h, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { NButton, NProgress, NSpace, NTag, NText, NTooltip, useMessage } from 'naive-ui'
import { PlayOutline, RefreshOutline, SearchOutline } from '@vicons/ionicons5'
import { api } from '../api'
import { renderShortText } from '../utils/shortText'
import { renderNetworkSelectLabel, renderNetworkSelectTag, renderNetworkTag, shortNetworkOptions } from '../utils/networks'
import { renderTokenAmount } from '../utils/money'

type ProgressStatus = 'success' | 'error' | 'warning' | 'info'

const message = useMessage()
const loading = ref(false)
const fetching = ref(false)
const allProcessing = ref(false)
const configSaving = ref(false)
const rowProcessingId = ref<number | null>(null)
const refreshTimer = ref<number | null>(null)
const rows = ref<any[]>([])
const total = ref(0)
const page = ref(1)
const perPage = ref(10)
const filters = reactive<any>({ network_code: null, status: null })
const withdrawConfig = reactive({ auto_withdraw_interval_seconds: 10 })
const networkOptions = shortNetworkOptions
const statusOptions = [
  { label: '待转出', value: 'pending_withdraw' },
  { label: '补充 Gas 中', value: 'gas_funding' },
  { label: '转出中', value: 'withdrawing' },
  { label: '已转出', value: 'withdrawn' },
  { label: '转出失败', value: 'withdraw_failed' },
  { label: '需人工处理', value: 'manual_required' }
]
const columns = [
  { title: '网络', key: 'network_code', width: 120, render: (row: any) => renderNetworkTag(row) },
  { title: '归集钱包', key: 'from_address', width: 120, render: (row: any) => renderShortText(row.from_address) },
  { title: '接收', key: 'to_address', width: 120, render: (row: any) => renderShortText(row.to_address) },
  { title: '代币数量', key: 'amount_int', width: 150, render: (row: any) => renderTokenAmount(formatTokenAmount(row.amount_int), row.token_code) },
  { title: '消耗原生币', key: 'actual_gas_fee_wei', width: 125, render: (row: any) => formatNativeAmount(row.actual_gas_fee_wei, row.native_symbol) },
  { title: '转出进度', key: 'current_confirmations', width: 150, render: renderProgress },
  { title: 'Gas补充交易', key: 'gas_funding_tx_hash', width: 140, render: (row: any) => renderTxHash(row, 'gas_funding_tx_hash') },
  { title: '转出交易哈希', key: 'withdraw_tx_hash', width: 145, render: (row: any) => renderTxHash(row, 'withdraw_tx_hash') },
  { title: '状态', key: 'status', width: 105, render: renderStatus },
  { title: '重试', key: 'retry_count', width: 90, render: (row: any) => `${row.retry_count ?? 0} / ${row.max_retry_count ?? 3}` },
  { title: '最后重试', key: 'last_retry_at', width: 145, render: (row: any) => row.last_retry_at || '-' },
  { title: '更新', key: 'updated_at', width: 145 },
  { title: '操作', key: 'actions', width: 105, fixed: 'right', render: renderActions }
]

function renderTxHash(row: any, key: 'gas_funding_tx_hash' | 'withdraw_tx_hash') {
  const hash = String(row[key] || '')
  if (!hash) {
    return '-'
  }
  const url = txExplorerUrl(String(row.network_code || ''), hash)
  if (!url) {
    return renderShortText(hash)
  }
  return h(NTooltip, { trigger: 'hover' }, {
    trigger: () => h(NButton, {
      text: true,
      type: 'primary',
      tag: 'a',
      href: url,
      target: '_blank',
      rel: 'noopener noreferrer'
    }, { default: () => shortTxHash(hash) }),
    default: () => hash
  })
}

function txExplorerUrl(networkCode: string, hash: string) {
  const explorers: Record<string, string> = {
    ethereum: 'https://etherscan.io/tx/',
    base: 'https://basescan.org/tx/',
    celo: 'https://celoscan.io/tx/',
    polygon: 'https://polygonscan.com/tx/'
  }
  return explorers[networkCode] ? explorers[networkCode] + hash : ''
}

function shortTxHash(hash: string) {
  const text = String(hash || '')
  return text.length > 16 ? `${text.slice(0, 8)}...${text.slice(-6)}` : text
}

async function loadConfig() {
  try {
    const data: any = await api.get('/admin/withdraw/config')
    withdrawConfig.auto_withdraw_interval_seconds = Number(data.auto_withdraw_interval_seconds || 10)
  } catch (e: any) {
    message.error(e.message)
  }
}

async function saveConfig() {
  configSaving.value = true
  try {
    const data: any = await api.post('/admin/withdraw/config/save', {
      auto_withdraw_interval_seconds: withdrawConfig.auto_withdraw_interval_seconds || 10
    })
    withdrawConfig.auto_withdraw_interval_seconds = Number(data.auto_withdraw_interval_seconds || 10)
    message.success('自动转出任务间隔已保存')
  } catch (e: any) {
    message.error(e.message)
  } finally {
    configSaving.value = false
  }
}

async function load(silent = false) {
  if (fetching.value) return
  fetching.value = true
  if (!silent) loading.value = true
  try {
    const query = new URLSearchParams()
    query.set('page', String(page.value))
    query.set('per_page', String(perPage.value))
    if (filters.network_code) query.set('network_code', filters.network_code)
    if (filters.status) query.set('status', filters.status)
    const data: any = await api.get('/admin/withdraw/list?' + query.toString())
    rows.value = data.items
    total.value = data.total
  } catch (e: any) {
    if (!silent) message.error(e.message)
  } finally {
    fetching.value = false
    if (!silent) loading.value = false
  }
}

function search() {
  page.value = 1
  load()
}

function resetSearch() {
  filters.network_code = null
  filters.status = null
  page.value = 1
  load()
}

async function processAll() {
  allProcessing.value = true
  try {
    const result: any[] = await api.post('/admin/withdraw/process-all')
    message.success(`已执行 ${result.length} 条转出记录`)
    await load(true)
  } catch (e: any) {
    message.error(e.message)
  } finally {
    allProcessing.value = false
  }
}

async function processOne(id: number) {
  rowProcessingId.value = id
  try {
    await api.post('/admin/withdraw/process-one', { id })
    message.success('已执行该转出记录')
    await load(true)
  } catch (e: any) {
    message.error(e.message)
  } finally {
    rowProcessingId.value = null
  }
}

function renderActions(row: any) {
  return h(NButton, {
    size: 'small',
    type: 'primary',
    secondary: true,
    disabled: row.status !== 'withdraw_failed',
    loading: rowProcessingId.value === Number(row.id),
    onClick: () => processOne(Number(row.id))
  }, { default: () => '转出' })
}

function statusText(status: string) {
  const map: Record<string, string> = {
    pending_withdraw: '待转出',
    gas_funding: '补充 Gas 中',
    withdrawing: '转出中',
    withdrawn: '已转出',
    withdraw_failed: '转出失败',
    manual_required: '需人工处理'
  }
  return map[status] || status || '-'
}

function statusType(status: string) {
  if (status === 'withdrawn') return 'success'
  if (status === 'withdraw_failed' || status === 'manual_required') return 'error'
  if (status === 'gas_funding' || status === 'withdrawing') return 'warning'
  return 'info'
}

function renderStatus(row: any) {
  const tag = () => h(NTag, { type: statusType(row.status), bordered: false }, { default: () => statusText(row.status) })
  const reason = String(row.error_message || '').trim()
  if (!reason || !['withdraw_failed', 'manual_required'].includes(row.status)) {
    return tag()
  }
  return h(NTooltip, {
    trigger: 'hover',
    maxWidth: 520,
    contentStyle: {
      color: '#f8fafc',
      lineHeight: '1.7',
      whiteSpace: 'normal',
      wordBreak: 'break-all'
    }
  }, {
    trigger: tag,
    default: () => h(NSpace, { vertical: true, size: 4 }, {
      default: () => [
        '失败原因',
        ...failureReasonLines(reason)
      ]
    })
  })
}

function failureReasonLines(reason: string) {
  return reason
    .replace(/；/g, '；\n')
    .replace(/，/g, '，\n')
    .replace(/。/g, '。\n')
    .split('\n')
    .map((line) => line.trim())
    .filter(Boolean)
}

function formatTokenAmount(amountInt: string) {
  const raw = String(amountInt || '0')
  if (!/^\d+$/.test(raw)) return raw
  const padded = raw.padStart(7, '0')
  const whole = padded.slice(0, -6) || '0'
  const decimal = padded.slice(-6).replace(/0+$/, '')
  const amount = decimal ? `${whole}.${decimal}` : whole
  return amount
}

function formatWeiToEth(wei: string) {
  const raw = String(wei || '0')
  if (!/^\d+$/.test(raw) || raw === '0') return '-'
  const padded = raw.padStart(19, '0')
  const whole = padded.slice(0, -18) || '0'
  const decimal = padded.slice(-18).replace(/0+$/, '').slice(0, 8)
  return decimal ? `${whole}.${decimal}` : whole
}

function formatNativeAmount(wei: string, symbol = 'ETH') {
  const amount = formatWeiToEth(wei)
  return amount === '-' ? '-' : `${amount} ${symbol || 'ETH'}`
}

function renderProgress(row: any) {
  const progress = withdrawProgress(row)
  return h(NSpace, { vertical: true, size: 4 }, {
    default: () => [
      h(NProgress, { type: 'line', percentage: progress.percentage, height: 8, showIndicator: false, status: progress.status }),
      h(NText, { depth: 3 }, { default: () => progress.text })
    ]
  })
}

function withdrawProgress(row: any): { percentage: number, status: ProgressStatus, text: string } {
  if (row.status === 'withdrawn') {
    return { percentage: 100, status: 'success', text: '已完成' }
  }
  if (row.status === 'manual_required') {
    return { percentage: 0, status: 'error', text: '需人工处理' }
  }
  if (row.status === 'withdraw_failed') {
    return { percentage: 0, status: 'error', text: '转出失败' }
  }
  if (row.status === 'gas_funding') {
    return { percentage: 25, status: 'warning', text: '补充 Gas 中' }
  }
  if (row.status === 'withdrawing') {
    const required = Number(row.required_confirmations || 0)
    const current = Math.min(required, Number(row.current_confirmations || 0))
    if (required > 0 && row.withdraw_tx_hash) {
      const percentage = Math.min(99, 50 + Math.floor(current * 50 / required))
      return { percentage, status: 'info', text: `确认中 ${current} / ${required}` }
    }
    return { percentage: 50, status: 'info', text: '交易已发送' }
  }
  return { percentage: 0, status: 'info', text: '待转出' }
}

onMounted(() => {
  loadConfig()
  load()
  refreshTimer.value = window.setInterval(() => load(true), 5000)
})

onBeforeUnmount(() => {
  if (refreshTimer.value !== null) {
    window.clearInterval(refreshTimer.value)
  }
})
</script>

