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
              v-model:value="collectionConfig.auto_collect_interval_seconds"
              :min="1"
              :max="3600"
              :step="1"
              placeholder="默认 10 秒"
              clearable
            />
          </n-form-item>
        </n-gi>
        <n-gi>
          <n-form-item label="最大重试次数" :show-feedback="false">
            <n-input-number
              v-model:value="collectionConfig.auto_collect_max_retry_count"
              :min="0"
              :max="100"
              :step="1"
              placeholder="默认 3 次，0 表示不自动重试"
              clearable
            />
          </n-form-item>
        </n-gi>
        <n-gi span="1 m:3 xl:2">
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
                全部归集
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

    <n-card title="归集记录" :bordered="false">
      <n-space vertical size="large">
        <n-data-table :columns="columns" :data="rows" :loading="loading" :scroll-x="1910" />
        <n-space justify="end">
          <n-pagination v-model:page="page" :page-size="perPage" :item-count="total" @update:page="() => load()" />
        </n-space>
      </n-space>
    </n-card>
  </n-space>
</template>

<script setup lang="ts">
import { h, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { NButton, NProgress, NSpace, NTag, NText, NTooltip, useMessage } from 'naive-ui'
import { PlayOutline, RefreshOutline, SearchOutline } from '@vicons/ionicons5'
import { api } from '../api'
import { renderShortText } from '../utils/shortText'
import { renderNetworkSelectLabel, renderNetworkSelectTag, renderNetworkTag, shortNetworkOptions } from '../utils/networks'
import { renderTokenAmount } from '../utils/money'

type ProgressStatus = 'success' | 'error' | 'warning' | 'info'

const message = useMessage()
const route = useRoute()
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
const collectionConfig = reactive({ auto_collect_interval_seconds: 10, auto_collect_max_retry_count: 3 })
const networkOptions = shortNetworkOptions
const statusOptions = [
  { label: '待归集', value: 'pending_collect' },
  { label: '处理中', value: 'processing' },
  { label: '补充 Gas 中', value: 'gas_funding' },
  { label: '归集中', value: 'collecting' },
  { label: '已归集', value: 'collected' },
  { label: '归集失败', value: 'collect_failed' },
  { label: '需人工处理', value: 'manual_required' }
]
const columns = [
  { title: '网络', key: 'network_code', width: 120, render: (row: any) => renderNetworkTag(row) },
  { title: '来源地址', key: 'from_address', width: 120, render: (row: any) => renderShortText(row.from_address) },
  { title: '归集地址', key: 'to_address', width: 120, render: (row: any) => renderShortText(row.to_address) },
  { title: '归集类型', key: 'collection_type', width: 100, render: renderCollectionType },
  { title: '代币数量', key: 'amount_int', width: 150, render: (row: any) => renderTokenAmount(formatTokenAmount(row.amount_int), row.token_code) },
  { title: '实际消耗原生币', key: 'actual_gas_fee_wei', width: 150, render: (row: any) => formatNativeAmount(row.actual_gas_fee_wei, row.native_symbol) },
  { title: '归集进度', key: 'current_confirmations', width: 180, render: renderProgress },
  { title: 'Gas 补充交易', key: 'gas_funding_tx_hash', width: 120, render: (row: any) => renderShortText(row.gas_funding_tx_hash) },
  { title: '归集交易', key: 'collect_tx_hash', width: 120, render: (row: any) => renderShortText(row.collect_tx_hash) },
  { title: '状态', key: 'status', width: 120, render: renderStatus },
  { title: '重试次数', key: 'retry_count', width: 110, render: (row: any) => `${row.retry_count ?? 0} / ${collectionConfig.auto_collect_max_retry_count}` },
  { title: '最后重试时间', key: 'last_retry_at', width: 160, render: (row: any) => row.last_retry_at || '-' },
  { title: '更新时间', key: 'updated_at', width: 160 },
  { title: '操作', key: 'actions', width: 120, fixed: 'right', render: renderActions }
]

function renderCollectionType(row: any) {
  const isExchange = row.collection_type === 'exchange'
  return h(NTag, { type: isExchange ? 'warning' : 'success', bordered: false }, { default: () => isExchange ? '交易所' : '本地' })
}

async function loadConfig() {
  try {
    const data: any = await api.get('/admin/collection/config')
    collectionConfig.auto_collect_interval_seconds = Number(data.auto_collect_interval_seconds || 10)
    collectionConfig.auto_collect_max_retry_count = Number(data.auto_collect_max_retry_count ?? 3)
  } catch (e: any) {
    message.error(e.message)
  }
}

async function saveConfig() {
  configSaving.value = true
  try {
    const data: any = await api.post('/admin/collection/config/save', {
      auto_collect_interval_seconds: collectionConfig.auto_collect_interval_seconds || 10,
      auto_collect_max_retry_count: collectionConfig.auto_collect_max_retry_count ?? 3
    })
    collectionConfig.auto_collect_interval_seconds = Number(data.auto_collect_interval_seconds || 10)
    collectionConfig.auto_collect_max_retry_count = Number(data.auto_collect_max_retry_count ?? 3)
    message.success('自动归集设置已保存')
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
    const data: any = await api.get('/admin/collection/list?' + query.toString())
    rows.value = data.items
    total.value = data.total
  } catch (e: any) {
    if (!silent) message.error(e.message)
  } finally {
    fetching.value = false
    if (!silent) loading.value = false
  }
}

function applyRouteFilters() {
  const networkCode = Array.isArray(route.query.network_code) ? route.query.network_code[0] : route.query.network_code
  filters.network_code = networkCode || null
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
    const result: any = await api.post('/admin/collection/process-all')
    message.success(queueStatsMessage(result, '归集'))
    await load(true)
  } catch (e: any) {
    message.error(e.message)
  } finally {
    allProcessing.value = false
  }
}

function queueStatsMessage(result: any, name: string) {
  const created = Number(result?.created ?? 0)
  const skipped = Number(result?.skipped ?? 0)
  const failed = Number(result?.failed ?? 0)
  return `已创建${created}条${name}队列，跳过${skipped}条，失败${failed}条`
}

async function processOne(id: number) {
  rowProcessingId.value = id
  try {
    await api.post('/admin/collection/process-one', { id })
    message.success('已加入归集队列')
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
    disabled: !canProcess(row),
    loading: rowProcessingId.value === Number(row.id),
    onClick: () => processOne(Number(row.id))
  }, { default: () => '归集' })
}

function hasActiveQueue(row: any) {
  return Number(row.queue_active) === 1 || (Number(row.queue_is_invalid) === 0 && ['queued', 'processing'].includes(String(row.queue_process_status || '')))
}

function effectiveStatus(row: any) {
  if (row.status === 'collected') return 'collected'
  if (hasActiveQueue(row)) return String(row.queue_process_status || row.status)
  return row.status
}

function canProcess(row: any) {
  return ['collect_failed', 'manual_required'].includes(row.status) && !hasActiveQueue(row)
}

function statusText(status: string) {
  const map: Record<string, string> = {
    queued: '已入队',
    pending_collect: '待归集',
    processing: '处理中',
    gas_funding: '补充 Gas 中',
    collecting: '归集中',
    collected: '已归集',
    collect_failed: '归集失败',
    manual_required: '需人工处理'
  }
  return map[status] || status || '-'
}

function statusType(status: string) {
  if (status === 'collected') return 'success'
  if (status === 'collect_failed' || status === 'manual_required') return 'error'
  if (status === 'processing' || status === 'gas_funding' || status === 'collecting') return 'warning'
  if (status === 'queued') return 'info'
  return 'info'
}

function renderStatus(row: any) {
  const displayStatus = effectiveStatus(row)
  const tag = () => h(NTag, { type: statusType(displayStatus), bordered: false }, { default: () => statusText(displayStatus) })
  const reason = String(row.queue_last_error || row.error_message || '').trim()
  if (!reason || (!hasActiveQueue(row) && !['collect_failed', 'manual_required'].includes(row.status))) {
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
  const progress = collectionProgress(row)
  return h(NSpace, { vertical: true, size: 4 }, {
    default: () => [
      h(NProgress, { type: 'line', percentage: progress.percentage, height: 8, showIndicator: false, status: progress.status }),
      h(NText, { depth: 3 }, { default: () => progress.text })
    ]
  })
}

function collectionProgress(row: any): { percentage: number, status: ProgressStatus, text: string } {
  const displayStatus = effectiveStatus(row)
  if (displayStatus === 'queued') {
    return { percentage: 0, status: 'info', text: '已入队' }
  }
  if (displayStatus === 'processing' && ['pending_collect', 'processing', 'collect_failed', 'manual_required'].includes(row.status)) {
    return { percentage: 10, status: 'warning', text: '处理中' }
  }
  if (row.status === 'collected') {
    return { percentage: 100, status: 'success', text: '已完成' }
  }
  if (row.status === 'manual_required') {
    return { percentage: 0, status: 'error', text: '需人工处理' }
  }
  if (row.status === 'collect_failed') {
    return { percentage: 0, status: 'error', text: '归集失败' }
  }
  if (row.status === 'gas_funding') {
    return { percentage: 25, status: 'warning', text: '补充 Gas 中' }
  }
  if (row.status === 'collecting') {
    const required = Number(row.required_confirmations || 0)
    const current = Math.min(required, Number(row.current_confirmations || 0))
    if (required > 0 && row.collect_tx_hash) {
      const percentage = Math.min(99, 50 + Math.floor(current * 50 / required))
      return { percentage, status: 'info', text: `确认中 ${current} / ${required}` }
    }
    return { percentage: 50, status: 'info', text: '交易已发送' }
  }
  return { percentage: 0, status: 'info', text: '待归集' }
}

onMounted(() => {
  applyRouteFilters()
  loadConfig()
  load()
  refreshTimer.value = window.setInterval(() => load(true), 5000)
})

onBeforeUnmount(() => {
  if (refreshTimer.value !== null) {
    window.clearInterval(refreshTimer.value)
  }
})

watch(() => route.query.network_code, () => {
  applyRouteFilters()
  page.value = 1
  load()
})
</script>
