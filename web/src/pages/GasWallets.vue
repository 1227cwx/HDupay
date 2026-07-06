<template>
  <n-space vertical size="large">
    <n-card title="筛选条件" :bordered="false">
      <n-grid cols="1 m:3 xl:6" responsive="screen" :x-gap="12" :y-gap="12">
        <n-gi>
          <n-form-item label="网络" :show-feedback="false">
            <n-select v-model:value="filters.network_code" clearable placeholder="全部网络" :options="networkOptions" :render-label="renderNetworkSelectLabel" :render-tag="renderNetworkSelectTag" />
          </n-form-item>
        </n-gi>
        <n-gi>
          <n-form-item label="余额同步间隔（分钟）" :show-feedback="false">
            <n-input-number v-model:value="syncInterval" :min="1" :max="1440" />
          </n-form-item>
        </n-gi>
        <n-gi>
          <n-form-item label="自动同步" :show-feedback="false">
            <n-switch v-model:value="syncEnabled">
              <template #checked>开启</template>
              <template #unchecked>关闭</template>
            </n-switch>
          </n-form-item>
        </n-gi>
        <n-gi span="1 m:3 xl:4">
          <n-form-item label="操作" :show-feedback="false">
            <n-space>
              <n-button type="primary" :loading="loading" @click="search">
                <template #icon><n-icon><SearchOutline /></n-icon></template>
                搜索
              </n-button>
              <n-button @click="resetSearch">重置</n-button>
              <n-button secondary type="primary" :loading="loading" @click="load">
                <template #icon><n-icon><RefreshOutline /></n-icon></template>
                刷新
              </n-button>
              <n-button secondary type="success" :loading="savingConfig" @click="saveConfig">保存设置</n-button>
              <n-button type="warning" secondary :loading="syncingAll" @click="syncAll">同步全部余额</n-button>
            </n-space>
          </n-form-item>
        </n-gi>
      </n-grid>
    </n-card>

    <n-card title="全局 Gas 钱包" :bordered="false">
      <n-space vertical size="medium">
        <n-alert type="info" :bordered="false">
          当前根钱包只有一个全局 Gas 地址；不同网络分别记录各自原生币余额。
        </n-alert>
        <n-space align="center" :wrap="false">
          <n-text depth="3">钱包地址</n-text>
          <n-text code>{{ wallet?.address_lower || '-' }}</n-text>
          <n-button v-if="wallet?.address_lower" size="tiny" quaternary @click="copy(wallet.address_lower)">复制</n-button>
        </n-space>
        <n-data-table :columns="columns" :data="filteredRows" :loading="loading" :scroll-x="1200" :pagination="{ pageSize: 10 }" />
      </n-space>
    </n-card>
  </n-space>
</template>

<script setup lang="ts">
import { computed, h, onMounted, reactive, ref } from 'vue'
import { NButton, NSpace, NSwitch, NTag, NText, NTooltip, useMessage } from 'naive-ui'
import { RefreshOutline, SearchOutline } from '@vicons/ionicons5'
import { api } from '../api'
import { renderNetworkSelectLabel, renderNetworkSelectTag, renderNetworkTag, shortNetworkOptions } from '../utils/networks'

const message = useMessage()
const loading = ref(false)
const savingConfig = ref(false)
const syncingAll = ref(false)
const syncEnabled = ref(true)
const syncInterval = ref(60)
const rows = ref<any[]>([])
const wallet = ref<any>(null)
const filters = reactive<any>({ network_code: null })
const appliedFilters = reactive<any>({ network_code: null })
const rowLoading = reactive<Record<string, boolean>>({})
const networkOptions = shortNetworkOptions

const filteredRows = computed(() => rows.value.filter(row => !appliedFilters.network_code || row.network_code === appliedFilters.network_code))

const columns = [
  { title: '网络', key: 'network_code', width: 150, render: renderNetworkTag },
  { title: 'Gas 地址', key: 'address', width: 180, render: () => wallet.value?.address_lower || '-' },
  { title: 'Gas 余额', key: 'balance', width: 150, render: (row: any) => `${row.balance || '0'} ${row.native_symbol || ''}` },
  {
    title: '参与同步',
    key: 'sync_enabled',
    width: 120,
    render: (row: any) => h(NSwitch, {
      value: Number(row.sync_enabled ?? 1) === 1,
      loading: rowLoading[`sync-${row.network_code}`],
      'onUpdate:value': (value: boolean) => toggleSync(row, value)
    })
  },
  { title: '同步状态', key: 'sync_status', width: 120, render: renderSyncStatus },
  { title: '最后同步', key: 'last_balance_sync_at', width: 170, render: (row: any) => row.last_balance_sync_at || '-' },
  { title: '创建时间', key: 'created_at', width: 170 },
  { title: '操作', key: 'actions', width: 120, fixed: 'right', render: renderActions }
]

function renderSyncStatus(row: any) {
  const status = row.sync_status || 'pending'
  const tag = () => h(NTag, { type: status === 'success' ? 'success' : status === 'failed' ? 'error' : 'warning', bordered: false }, {
    default: () => status === 'success' ? '正常' : status === 'failed' ? '失败' : '待同步'
  })
  if (status !== 'failed' || !row.sync_error) {
    return tag()
  }
  return h(NTooltip, { trigger: 'hover', maxWidth: 520, contentStyle: { whiteSpace: 'normal', wordBreak: 'break-all' } }, {
    trigger: tag,
    default: () => row.sync_error
  })
}

function renderActions(row: any) {
  return h(NSpace, { wrap: false }, {
    default: () => [
      h(NButton, { size: 'small', secondary: true, type: 'primary', loading: rowLoading[`sync-one-${row.network_code}`], onClick: () => syncOne(row) }, { default: () => '同步' })
    ]
  })
}

async function load() {
  loading.value = true
  try {
    const data: any = await api.get('/admin/wallet/gas-wallets')
    rows.value = data.balances || []
    wallet.value = data.wallet || null
    syncEnabled.value = Number(data.settings?.balance_sync_enabled ?? 1) === 1
    syncInterval.value = Number(data.settings?.balance_sync_interval_minutes || 60)
  } catch (e: any) {
    message.error(e.message)
  } finally {
    loading.value = false
  }
}

function search() {
  Object.assign(appliedFilters, filters)
}

function resetSearch() {
  filters.network_code = null
  appliedFilters.network_code = null
}

async function saveConfig() {
  savingConfig.value = true
  try {
    const data: any = await api.post('/admin/wallet/gas-wallets/config/save', { balance_sync_enabled: syncEnabled.value, balance_sync_interval_minutes: syncInterval.value })
    rows.value = data.balances || rows.value
    wallet.value = data.wallet || wallet.value
    syncEnabled.value = Number(data.settings?.balance_sync_enabled ?? 1) === 1
    syncInterval.value = Number(data.settings?.balance_sync_interval_minutes || syncInterval.value)
    message.success('Gas 钱包余额同步设置已保存')
  } catch (e: any) {
    message.error(e.message)
  } finally {
    savingConfig.value = false
  }
}

async function syncAll() {
  syncingAll.value = true
  try {
    await api.post('/admin/wallet/gas-wallets/sync-all')
    message.success('已提交同步全部 Gas 钱包余额')
    await load()
  } catch (e: any) {
    message.error(e.message)
  } finally {
    syncingAll.value = false
  }
}

async function toggleSync(row: any, value: boolean) {
  rowLoading[`sync-${row.network_code}`] = true
  try {
    const data: any = await api.post('/admin/wallet/gas-wallet/sync-toggle', { network_code: row.network_code, sync_enabled: value })
    rows.value = data.balances || rows.value
    wallet.value = data.wallet || wallet.value
    message.success('同步状态已更新')
  } catch (e: any) {
    message.error(e.message)
  } finally {
    rowLoading[`sync-${row.network_code}`] = false
  }
}

async function syncOne(row: any) {
  rowLoading[`sync-one-${row.network_code}`] = true
  try {
    const data: any = await api.post('/admin/wallet/gas-wallet/sync', { network_code: row.network_code })
    rows.value = data.balances || rows.value
    wallet.value = data.wallet || wallet.value
    message.success('Gas 钱包余额同步完成')
  } catch (e: any) {
    message.error(e.message)
  } finally {
    rowLoading[`sync-one-${row.network_code}`] = false
  }
}

async function copy(value: string) {
  if (!value) return
  try {
    await navigator.clipboard.writeText(value)
    message.success('地址已复制')
  } catch {
    message.error('复制失败，请手动复制')
  }
}

onMounted(load)
</script>
