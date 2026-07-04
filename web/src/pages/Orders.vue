<template>
  <n-space vertical size="large">
    <n-card title="订单搜索" :bordered="false">
      <n-grid cols="1 m:3 xl:6" responsive="screen" :x-gap="12" :y-gap="12">
        <n-gi><n-input v-model:value="filters.keyword" clearable placeholder="订单号 / 地址 / 哈希" /></n-gi>
        <n-gi><n-select v-model:value="filters.network_code" clearable placeholder="选择网络" :options="networkOptions" :render-label="renderNetworkSelectLabel" :render-tag="renderNetworkSelectTag" /></n-gi>
        <n-gi><n-select v-model:value="filters.status" clearable placeholder="选择状态" :options="statusOptions" /></n-gi>
        <n-gi><n-select v-model:value="filters.source" clearable placeholder="选择来源" :options="sourceOptions" /></n-gi>
        <n-gi><n-select v-model:value="filters.fiat_currency" clearable placeholder="选择法币" :options="fiatOptions" /></n-gi>
        <n-gi>
          <n-space>
            <n-button type="primary" :loading="loading" @click="search">
              <template #icon><n-icon><SearchOutline /></n-icon></template>
              搜索
            </n-button>
            <n-button @click="resetSearch">重置</n-button>
            <n-button secondary type="primary" :loading="loading" @click="() => load()">
              <template #icon><n-icon><RefreshOutline /></n-icon></template>
              刷新
            </n-button>
          </n-space>
        </n-gi>
      </n-grid>
    </n-card>

    <n-card title="交易订单列表" :bordered="false">
      <n-space vertical size="large">
        <n-data-table :columns="columns" :data="rows" :loading="loading" :scroll-x="1780" />
        <n-space justify="end">
          <n-pagination v-model:page="page" :page-size="perPage" :item-count="total" @update:page="() => load()" />
        </n-space>
      </n-space>
    </n-card>

    <n-modal v-model:show="detailShow" preset="card" title="订单详情" :style="{ width: '860px', maxWidth: '94vw' }">
      <n-space v-if="detail" vertical size="large">
        <n-descriptions title="基础信息" bordered label-placement="left" :column="1">
          <n-descriptions-item label="订单 ID">{{ detail.id }}</n-descriptions-item>
          <n-descriptions-item label="订单号">{{ detail.order_no }}</n-descriptions-item>
          <n-descriptions-item label="网络"><NetworkTag :code="detail.network_code" :label="networkName(detail.network_code)" /></n-descriptions-item>
          <n-descriptions-item label="状态">{{ detail.status_text }}</n-descriptions-item>
          <n-descriptions-item label="来源">{{ detail.source_text }}</n-descriptions-item>
          <n-descriptions-item label="来源 IP">{{ detail.source_ip || '-' }}</n-descriptions-item>
          <n-descriptions-item label="创建时间">{{ detail.created_at }}</n-descriptions-item>
          <n-descriptions-item label="到期时间">{{ detail.expire_at }}</n-descriptions-item>
          <n-descriptions-item label="确认时间">{{ detail.confirmed_at || '-' }}</n-descriptions-item>
        </n-descriptions>

        <n-descriptions title="金额信息" bordered label-placement="left" :column="1">
          <n-descriptions-item label="货币"><TokenInline :code="detail.token_code" /></n-descriptions-item>
          <n-descriptions-item label="法币">{{ detail.fiat_currency }}</n-descriptions-item>
          <n-descriptions-item label="法币金额">{{ formatFiat(detail.fiat_amount, detail.fiat_currency, fiatOptions) }}</n-descriptions-item>
          <n-descriptions-item label="汇率">
            <n-flex align="center" :wrap="false" :size="8" inline>
              <n-text>1</n-text>
              <TokenInline :code="detail.token_code" :size="16" />
              <n-text>= {{ detail.exchange_rate || '-' }} {{ detail.fiat_currency }}</n-text>
            </n-flex>
          </n-descriptions-item>
          <n-descriptions-item label="应付数量"><TokenAmount :amount="detail.token_amount || detail.amount_display" :code="detail.token_code" /></n-descriptions-item>
          <n-descriptions-item label="实付数量">
            <TokenAmount v-if="paidTokenAmount(detail)" :amount="paidTokenAmount(detail)" :code="detail.token_code" />
            <span v-else>-</span>
          </n-descriptions-item>
        </n-descriptions>

        <n-descriptions title="链上信息" bordered label-placement="left" :column="1">
          <n-descriptions-item label="发送地址">{{ detail.from_address || '-' }}</n-descriptions-item>
          <n-descriptions-item label="接收地址">{{ detail.to_address || detail.address || '-' }}</n-descriptions-item>
          <n-descriptions-item label="交易哈希">{{ detail.tx_hash || '-' }}</n-descriptions-item>
          <n-descriptions-item label="交易区块">{{ detail.tx_block_number || '-' }}</n-descriptions-item>
          <n-descriptions-item label="确认进度">{{ detail.confirmation_percent || 0 }}%</n-descriptions-item>
        </n-descriptions>

        <n-descriptions v-if="detail.source === 'api'" title="API 信息" bordered label-placement="left" :column="1">
          <n-descriptions-item label="API 名称">{{ detail.api_client?.name || '-' }}</n-descriptions-item>
          <n-descriptions-item label="回调地址">{{ detail.api_client?.callback_url || '-' }}</n-descriptions-item>
          <n-descriptions-item label="回调状态">{{ detail.callback_status_text || '-' }}</n-descriptions-item>
          <n-descriptions-item label="回调结果">{{ detail.callback_log?.response_body || detail.callback_log?.error_message || '-' }}</n-descriptions-item>
        </n-descriptions>

        <n-descriptions v-if="detail.source === 'epay'" title="api-epay 信息" bordered label-placement="left" :column="1">
          <n-descriptions-item label="API 名称">{{ detail.api_client?.name || '-' }}</n-descriptions-item>
          <n-descriptions-item label="api-epay 订单号">{{ detail.easypay_order?.epay_order_no || '-' }}</n-descriptions-item>
          <n-descriptions-item label="商户订单号">{{ detail.easypay_order?.out_trade_no || '-' }}</n-descriptions-item>
          <n-descriptions-item label="商品名称">{{ detail.easypay_order?.name || '-' }}</n-descriptions-item>
          <n-descriptions-item label="异步回调">{{ detail.easypay_order?.notify_url || '-' }}</n-descriptions-item>
          <n-descriptions-item label="同步跳转">{{ detail.easypay_order?.return_url || '-' }}</n-descriptions-item>
          <n-descriptions-item label="回调状态">{{ detail.callback_status_text || '-' }}</n-descriptions-item>
          <n-descriptions-item label="回调结果">{{ detail.easypay_order?.notify_response || detail.easypay_order?.notify_error || '-' }}</n-descriptions-item>
        </n-descriptions>
      </n-space>
    </n-modal>
  </n-space>
</template>

<script setup lang="ts">
import { h, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { NButton, NFlex, NProgress, NSpace, NTag, NText, useMessage } from 'naive-ui'
import { RefreshOutline, SearchOutline } from '@vicons/ionicons5'
import { api } from '../api'
import { renderShortText } from '../utils/shortText'
import { NetworkTag, networkLabel, renderNetworkSelectLabel, renderNetworkSelectTag, renderNetworkTag, shortNetworkOptions } from '../utils/networks'
import { fallbackFiatOptions, formatFiat, renderTokenAmount, renderTokenTag, TokenAmount, TokenInline } from '../utils/money'

const message = useMessage()
const loading = ref(false)
const fetching = ref(false)
const rows = ref<any[]>([])
const total = ref(0)
const page = ref(1)
const perPage = ref(10)
const refreshTimer = ref<number | null>(null)
const detailShow = ref(false)
const detail = ref<any>(null)
const filters = reactive<any>({ keyword: '', network_code: null, status: null, source: null, fiat_currency: null })
const networkOptions = shortNetworkOptions
const fiatOptions = ref<any[]>(fallbackFiatOptions)
const statusOptions = [
  { label: '等待支付', value: 'waiting' },
  { label: '确认中', value: 'confirming' },
  { label: '交易成功', value: 'success' },
  { label: '已过期', value: 'expired' },
  { label: '失败', value: 'failed' }
]
const sourceOptions = [
  { label: '后台', value: 'admin' },
  { label: '前台', value: 'frontend' },
  { label: 'API', value: 'api' },
  { label: 'api-epay', value: 'epay' }
]

const columns = [
  { title: 'ID', key: 'id', width: 80 },
  { title: '订单号', key: 'order_no', width: 190, render: (row: any) => renderShortText(row.order_no, 12) },
  { title: '网络', key: 'network_code', width: 120, render: (row: any) => renderNetworkTag(row) },
  { title: '货币', key: 'token_code', width: 120, render: (row: any) => renderTokenTag(row.token_code || 'USDC') },
  { title: '法币', key: 'fiat_currency', width: 90 },
  { title: '法币金额', key: 'fiat_amount', width: 130, render: (row: any) => formatFiat(row.fiat_amount, row.fiat_currency, fiatOptions.value) },
  { title: '代币数量', key: 'token_amount', width: 160, render: (row: any) => renderTokenAmount(row.token_amount || row.amount_display, row.token_code) },
  { title: '收款地址', key: 'address', width: 120, render: (row: any) => renderShortText(row.address) },
  { title: '状态', key: 'status', width: 180, render: renderStatus },
  { title: '回调', key: 'callback_status', width: 110, render: renderCallbackStatus },
  { title: '来源', key: 'source', width: 90, render: (row: any) => sourceText(row.source) },
  { title: '创建时间', key: 'created_at', width: 170 },
  { title: '确认时间', key: 'confirmed_at', width: 170, render: (row: any) => row.confirmed_at || '-' },
  { title: '操作', key: 'actions', width: 170, fixed: 'right', render: renderActions }
]

async function load(silent = false) {
  if (fetching.value) return
  fetching.value = true
  if (!silent) loading.value = true
  try {
    const query = new URLSearchParams()
    query.set('page', String(page.value))
    query.set('per_page', String(perPage.value))
    if (filters.keyword) query.set('keyword', filters.keyword)
    if (filters.network_code) query.set('network_code', filters.network_code)
    if (filters.status) query.set('status', filters.status)
    if (filters.source) query.set('source', filters.source)
    if (filters.fiat_currency) query.set('fiat_currency', filters.fiat_currency)
    const data: any = await api.get('/admin/deposit/list?' + query.toString())
    rows.value = data.items
    total.value = data.total
  } catch (e: any) {
    if (!silent) message.error(e.message)
  } finally {
    fetching.value = false
    if (!silent) loading.value = false
  }
}

async function loadOptions() {
  try {
    const options: any = await api.get('/api/deposit/options')
    fiatOptions.value = options.fiat_currencies || fallbackFiatOptions
  } catch {
    fiatOptions.value = fallbackFiatOptions
  }
}

function search() {
  page.value = 1
  load()
}

function resetSearch() {
  Object.assign(filters, { keyword: '', network_code: null, status: null, source: null, fiat_currency: null })
  page.value = 1
  load()
}

function renderStatus(row: any) {
  if (row.status === 'confirming') {
    const percentage = Math.min(99, Math.max(1, Number(row.confirmation_percent || 0)))
    return h(NSpace, { vertical: true, size: 4 }, {
      default: () => [
        h(NProgress, { type: 'line', percentage, height: 8, showIndicator: false, status: 'info' }),
        h(NText, { depth: 3 }, { default: () => `${percentage}%` })
      ]
    })
  }
  return h(NTag, { type: statusType(row.status), bordered: false }, { default: () => statusText(row.status) })
}

function renderCallbackStatus(row: any) {
  if (row.callback_status === 'none') return '-'
  return h(NTag, { type: row.callback_status === 'success' ? 'success' : row.callback_status === 'failed' ? 'error' : 'warning', bordered: false }, {
    default: () => row.callback_status_text || '-'
  })
}

function renderActions(row: any) {
  return h(NSpace, { wrap: false }, {
    default: () => [
      h(NButton, { size: 'small', type: 'primary', secondary: true, onClick: () => openDetail(row) }, { default: () => '详情' }),
      ['api', 'epay'].includes(row.source) ? h(NButton, {
        size: 'small',
        type: 'success',
        secondary: true,
        disabled: !(row.status === 'success' && row.callback_status !== 'success' && row.callback_status !== 'none'),
        onClick: () => callbackOrder(row)
      }, { default: () => '回调' }) : null
    ].filter(Boolean)
  })
}

async function openDetail(row: any) {
  try {
    detail.value = await api.get('/admin/deposit/detail?order_no=' + encodeURIComponent(row.order_no))
    detailShow.value = true
  } catch (e: any) {
    message.error(e.message)
  }
}

async function callbackOrder(row: any) {
  try {
    await api.post('/admin/deposit/callback', { order_no: row.order_no })
    message.success('回调已提交')
    await load(true)
  } catch (e: any) {
    message.error(e.message)
  }
}

function statusText(status: string) {
  const map: Record<string, string> = { waiting: '等待支付', confirming: '确认中', success: '交易成功', expired: '已过期', failed: '失败' }
  return map[status] || status || '-'
}

function statusType(status: string) {
  if (status === 'success') return 'success'
  if (status === 'waiting') return 'warning'
  if (status === 'expired' || status === 'failed') return 'error'
  return 'info'
}

function sourceText(source: string) {
  const map: Record<string, string> = { admin: '后台', frontend: '前台', api: 'API', epay: 'api-epay' }
  return map[source] || '-'
}

function networkName(code: string) {
  return networkLabel(code, code)
}

function paidTokenAmount(row: any) {
  const text = String(row?.paid_amount_display || '').trim()
  if (!text) return ''
  const token = String(row?.token_code || '').trim()
  return token ? text.replace(new RegExp(`\\s*${token}\\s*$`, 'i'), '').trim() : text
}

onMounted(async () => {
  await loadOptions()
  load()
  refreshTimer.value = window.setInterval(() => load(true), 5000)
})

onBeforeUnmount(() => {
  if (refreshTimer.value !== null) window.clearInterval(refreshTimer.value)
})
</script>
