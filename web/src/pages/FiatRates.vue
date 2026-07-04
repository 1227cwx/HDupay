<template>
  <n-space vertical size="large">
    <n-card title="稳定币汇率设置" :bordered="false">
      <n-space vertical size="large">
        <n-form :model="fiatRateForm" label-placement="top">
          <n-grid cols="1 m:2 xl:4" responsive="screen" :x-gap="14" :y-gap="8">
            <n-gi><n-form-item label="接口提供商"><n-select v-model:value="fiatRateForm.provider" :options="providerOptions" /></n-form-item></n-gi>
            <n-gi><n-form-item label="请求方式"><n-select v-model:value="fiatRateForm.proxy_id" :options="requestOptions" /></n-form-item></n-gi>
            <n-gi><n-form-item label="同步间隔（分钟）"><n-input-number v-model:value="fiatRateForm.sync_interval_minutes" :min="1" :max="1440" :precision="0" button-placement="both" /></n-form-item></n-gi>
            <n-gi><n-form-item label="禁用接口缓存"><n-switch v-model:value="fiatRateForm.disable_cache" /></n-form-item></n-gi>
          </n-grid>
          <n-form-item>
            <n-space>
              <n-button type="primary" :loading="fiatRateSaving" @click="saveFiatRate"><template #icon><n-icon><SaveOutline /></n-icon></template>保存汇率设置</n-button>
              <n-button secondary type="primary" :loading="fiatRateTesting" @click="testFiatRate">测试接口</n-button>
              <n-button secondary type="success" :loading="fiatRateRefreshing" @click="refreshFiatRate"><template #icon><n-icon><RefreshOutline /></n-icon></template>立即同步</n-button>
            </n-space>
          </n-form-item>
        </n-form>
        <n-descriptions label-placement="left" bordered :column="2">
          <n-descriptions-item label="当前提供商">{{ providerLabel(fiatRateForm.provider) }}</n-descriptions-item>
          <n-descriptions-item label="请求方式">{{ requestLabel }}</n-descriptions-item>
          <n-descriptions-item label="同步间隔">{{ fiatRateForm.sync_interval_minutes }} 分钟</n-descriptions-item>
          <n-descriptions-item label="接口缓存">{{ fiatRateForm.disable_cache ? '已禁用' : '允许缓存' }}</n-descriptions-item>
          <n-descriptions-item label="最后同步">{{ fiatRateLastRefreshAt || '-' }}</n-descriptions-item>
        </n-descriptions>
      </n-space>
    </n-card>


    <n-card title="稳定币汇率数据" :bordered="false">
      <n-data-table :columns="rateColumns" :data="groupedFiatRates" :pagination="{ pageSize: 10 }" :loading="settingsLoading" />
    </n-card>

    <n-modal v-model:show="rateTestShow" preset="card" title="汇率接口测试结果" :style="{ width: '760px', maxWidth: '94vw' }">
      <n-space v-if="rateTestResult" vertical size="large">
        <n-descriptions label-placement="left" bordered :column="1">
          <n-descriptions-item label="接口提供商">{{ providerLabel(rateTestResult.provider) }}</n-descriptions-item>
          <n-descriptions-item label="请求地址">{{ rateTestResult.request_url }}</n-descriptions-item>
          <n-descriptions-item label="返回数量">{{ rateTestResult.rates_count }}</n-descriptions-item>
          <n-descriptions-item label="数据日期">{{ rateTestResult.source_date || '-' }}</n-descriptions-item>
        </n-descriptions>
        <n-data-table :columns="sampleRateColumns" :data="sampleRateRows" :pagination="false" />
      </n-space>
    </n-modal>
  </n-space>
</template>

<script setup lang="ts">
import { computed, h, onMounted, reactive, ref } from 'vue'
import { NButton, NSpace, NSwitch, NTag, NText, NTooltip, useMessage } from 'naive-ui'
import { RefreshOutline, SaveOutline } from '@vicons/ionicons5'
import { api } from '../api'
import { fallbackFiatOptions, renderTokenInline, renderTokenTag } from '../utils/money'

const message = useMessage()
const settingsLoading = ref(false)
const fiatRateSaving = ref(false)
const fiatRateTesting = ref(false)
const fiatRateRefreshing = ref(false)
const togglingCurrency = ref('')
const fiatRateForm = reactive({ provider: 'coingecko', proxy_id: 0, sync_interval_minutes: 60, disable_cache: false })
const proxies = ref<any[]>([])
const fiatRates = ref<any[]>([])
const fiatRateLastRefreshAt = ref('')
const rateTestShow = ref(false)
const rateTestResult = ref<any>(null)
const providerOptions = [{ label: 'CoinGecko', value: 'coingecko' }]
const stableTokens = ['USDC', 'USDT']
const proxyOptions = computed(() => proxies.value.map(item => ({ label: `${item.name || '未命名代理'}（${String(item.proxy_type || '').toUpperCase()} ${item.host}:${item.port}）`, value: Number(item.id) })))
const requestOptions = computed(() => [{ label: '直连', value: 0 }, ...proxyOptions.value])
const requestLabel = computed(() => Number(fiatRateForm.proxy_id || 0) ? (requestOptions.value.find(item => item.value === Number(fiatRateForm.proxy_id))?.label || '代理') : '直连')
const groupedFiatRates = computed(() => groupRatesByFiat(fiatRates.value))

const rateColumns = [
  { title: '法币', key: 'fiat_currency', width: 180, render: (row: any) => h(NText, null, { default: () => `${currencyLabel(row.fiat_currency)} ${row.fiat_currency}` }) },
  { title: () => renderTokenTag('USDC'), key: 'usdc_rate', minWidth: 210, render: (row: any) => renderTokenRate(row, 'USDC') },
  { title: () => renderTokenTag('USDT'), key: 'usdt_rate', minWidth: 210, render: (row: any) => renderTokenRate(row, 'USDT') },
  { title: '自动同步', key: 'auto_update', width: 120, render: (row: any) => h(NSwitch, { value: Boolean(Number(row.auto_update)), loading: togglingCurrency.value === row.fiat_currency, 'onUpdate:value': (value: boolean) => toggleCurrency(row, value) }) },
  { title: '状态', key: 'status', width: 120, render: (row: any) => h(NTag, { type: rateStatusType(row.status), bordered: false }, { default: () => row.status_text || '-' }) },
  { title: '数据日期', key: 'source_date', width: 130, render: (row: any) => row.source_date || '-' },
  { title: '最后同步', key: 'last_refresh_at', width: 180, render: (row: any) => row.last_refresh_at || '-' },
  { title: '最近同步失败', key: 'error_message', minWidth: 160, render: renderSyncError }
]
const sampleRateColumns = [
  { title: '稳定币', key: 'token_code', render: (row: any) => renderTokenTag(row.token_code) },
  { title: '法币', key: 'currency' },
  { title: '汇率', key: 'rate', render: (row: any) => h(NSpace, { align: 'center', wrap: false, size: 6 }, { default: () => [h(NText, null, { default: () => '1' }), renderTokenInline(row.token_code, row.token_code, 15), h(NText, null, { default: () => `= ${row.rate_value} ${row.currency}` })] }) }
]
const sampleRateRows = computed(() => (rateTestResult.value?.sample_rates || []).map((item: any) => ({ token_code: item.token_code, currency: item.fiat_currency, rate_value: item.rate, rate: `1 ${item.token_code} = ${item.rate} ${item.fiat_currency}` })))

async function loadSystemSettings() {
  settingsLoading.value = true
  try {
    const [settings, options]: any[] = await Promise.all([api.get('/admin/system/settings'), api.get('/api/deposit/options')])
    proxies.value = settings.proxies || []
    applyFiatRate(settings.fiat_rate || {})
    fiatRates.value = settings.fiat_rates || buildFallbackRates(options.fiat_currencies || fallbackFiatOptions)
  } catch (e: any) {
    message.error(e.message)
  } finally {
    settingsLoading.value = false
  }
}

async function saveFiatRate() {
  fiatRateSaving.value = true
  try {
    const data: any = await api.post('/admin/system/fiat-rate/save', fiatRatePayload())
    applyFiatRate(data.fiat_rate || data)
    fiatRates.value = data.fiat_rates || fiatRates.value
    message.success('汇率设置已保存')
  } catch (e: any) { message.error(e.message) } finally { fiatRateSaving.value = false }
}
async function testFiatRate() {
  fiatRateTesting.value = true
  try { rateTestResult.value = await api.post('/admin/system/fiat-rate/test', fiatRatePayload()); rateTestShow.value = true; message.success('汇率接口测试成功') } catch (e: any) { message.error(e.message) } finally { fiatRateTesting.value = false }
}
async function refreshFiatRate() {
  fiatRateRefreshing.value = true
  try { const data: any = await api.post('/admin/system/fiat-rate/refresh'); fiatRates.value = data.rates || fiatRates.value; fiatRateLastRefreshAt.value = data.last_refresh_at || fiatRateLastRefreshAt.value; message.success('汇率同步成功') } catch (e: any) { message.error(e.message); await loadSystemSettings() } finally { fiatRateRefreshing.value = false }
}
async function toggleCurrency(row: any, value: boolean) {
  togglingCurrency.value = row.fiat_currency
  try {
    let latestData: any = null
    for (const tokenCode of stableTokens) latestData = await api.post('/admin/system/fiat-rate/toggle-currency', { token_code: tokenCode, fiat_currency: row.fiat_currency, auto_update: value })
    fiatRates.value = latestData?.rates || fiatRates.value
    message.success('法币同步状态已更新')
  } catch (e: any) { message.error(e.message) } finally { togglingCurrency.value = '' }
}
function fiatRatePayload() { const proxyId = Number(fiatRateForm.proxy_id || 0); return { provider: fiatRateForm.provider, proxy_mode: proxyId > 0 ? 'proxy' : 'direct', proxy_id: proxyId, sync_interval_minutes: Number(fiatRateForm.sync_interval_minutes || 60), disable_cache: fiatRateForm.disable_cache } }
function applyFiatRate(data: any) { fiatRateForm.provider = data.provider || 'coingecko'; fiatRateForm.proxy_id = data.proxy_mode === 'proxy' ? Number(data.proxy_id || 0) : 0; fiatRateForm.sync_interval_minutes = Number(data.sync_interval_minutes || 60); fiatRateForm.disable_cache = Boolean(Number(data.disable_cache || 0)); fiatRateLastRefreshAt.value = data.last_refresh_at || '' }
function providerLabel(provider: string) { return providerOptions.find(item => item.value === provider)?.label || provider || '-' }
function currencyLabel(currency: string) { return fallbackFiatOptions.find(item => item.value === currency)?.label?.replace(` ${currency}`, '') || currency }
function rateStatusType(status: string) { if (status === 'success') return 'success'; if (status === 'failed') return 'error'; return 'warning' }
function renderTokenRate(row: any, tokenCode: string) {
  const item = row.tokens?.[tokenCode]
  if (!item || !item.rate || item.rate === '0') return '-'
  return h(NSpace, { align: 'center', wrap: false, size: 6 }, {
    default: () => [
      h(NText, null, { default: () => '1' }),
      renderTokenInline(tokenCode, tokenCode, 15),
      h(NText, null, { default: () => `= ${item.rate} ${row.fiat_currency}` })
    ]
  })
}
function renderSyncError(row: any) {
  if (!row.error_message) return '-'
  const isNormal = row.status === 'success'
  return h(NTooltip, { trigger: 'hover', maxWidth: 520, contentStyle: { whiteSpace: 'pre-line', wordBreak: 'break-all' } }, {
    trigger: () => h(NButton, { text: true, type: isNormal ? 'warning' : 'error' }, { default: () => isNormal ? '最近失败' : '查看' }),
    default: () => h(NText, { depth: 1 }, { default: () => row.error_message })
  })
}
function groupRatesByFiat(rows: any[]) {
  const grouped = new Map<string, any>()
  for (const row of rows || []) {
    const fiatCurrency = String(row.fiat_currency || '').toUpperCase(); const tokenCode = String(row.token_code || '').toUpperCase(); if (!fiatCurrency || !tokenCode) continue
    if (!grouped.has(fiatCurrency)) grouped.set(fiatCurrency, { fiat_currency: fiatCurrency, label: row.label || currencyLabel(fiatCurrency), symbol: row.symbol || '', tokens: {} })
    grouped.get(fiatCurrency).tokens[tokenCode] = row
  }
  const ordered = fallbackFiatOptions.map(option => grouped.get(option.value)).filter(Boolean)
  const extras = Array.from(grouped.values()).filter(item => !fallbackFiatOptions.some(option => option.value === item.fiat_currency))
  return [...ordered, ...extras].map(buildRateGroupRow)
}
function buildRateGroupRow(group: any) {
  const tokenRows = stableTokens.map(tokenCode => group.tokens?.[tokenCode]).filter(Boolean)
  const failedRows = tokenRows.filter((row: any) => row.status === 'failed')
  const successRows = tokenRows.filter((row: any) => row.status === 'success')
  const pendingRows = tokenRows.filter((row: any) => row.status !== 'success' && row.status !== 'failed')
  const errorMessage = stableTokens.map(tokenCode => { const messageText = String(group.tokens?.[tokenCode]?.error_message || '').trim(); return messageText ? `${tokenCode}：${messageText}` : '' }).filter(Boolean).join('\n')
  let status = 'pending'; let statusText = '待同步'
  if (tokenRows.length > 0 && successRows.length === tokenRows.length) { status = 'success'; statusText = '正常' } else if (tokenRows.length > 0 && failedRows.length === tokenRows.length) { status = 'failed'; statusText = '失败' } else if (failedRows.length > 0) { status = 'partial_failed'; statusText = '部分失败' } else if (successRows.length > 0 && pendingRows.length > 0) { status = 'partial_pending'; statusText = '部分待同步' }
  return { ...group, auto_update: tokenRows.length > 0 && tokenRows.every((row: any) => Boolean(Number(row.auto_update))), status, status_text: statusText, source_date: latestText(tokenRows.map((row: any) => row.source_date)), last_refresh_at: latestText(tokenRows.map((row: any) => row.last_refresh_at)), error_message: errorMessage }
}
function latestText(values: any[]) { return values.map(value => String(value || '').trim()).filter(Boolean).sort().reverse()[0] || '' }
function buildFallbackRates(options: any[]) { return stableTokens.flatMap(token => options.map(item => ({ label: item.label, symbol: item.symbol, token_code: token, fiat_currency: item.value, rate: '0', auto_update: 1, provider: 'coingecko', status: 'pending', status_text: '待同步', error_message: '', source_date: '', last_refresh_at: '' }))) }
onMounted(loadSystemSettings)
</script>
