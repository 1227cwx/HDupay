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
              <n-button type="primary" :loading="loading" @click="search"><template #icon><n-icon><SearchOutline /></n-icon></template>搜索</n-button>
              <n-button @click="resetSearch">重置</n-button>
              <n-button secondary type="primary" :loading="loading" @click="load"><template #icon><n-icon><RefreshOutline /></n-icon></template>刷新</n-button>
              <n-button secondary type="success" :loading="savingConfig" @click="saveConfig">保存设置</n-button>
              <n-button type="warning" secondary :loading="syncingAll" @click="syncAll">同步全部余额</n-button>
            </n-space>
          </n-form-item>
        </n-gi>
      </n-grid>
    </n-card>

    <n-card title="归集钱包" :bordered="false">
      <n-tabs v-if="filteredAccounts.length" v-model:value="activeTab" type="line" animated>
        <n-tab-pane v-for="account in filteredAccounts" :key="account.id" :name="account.network_code">
          <template #tab><NetworkTag :code="account.network_code" :label="networkLabel(account.network_code)" /></template>
          <n-space vertical size="medium">
            <n-space justify="space-between" align="center">
              <n-space align="center"><NetworkTag :code="account.network_code" :label="networkLabel(account.network_code)" /><n-tag :type="account.status === 'active' ? 'success' : 'default'" :bordered="false">{{ account.status === 'active' ? '启用' : '停用' }}</n-tag></n-space>
              <n-button type="primary" secondary :disabled="account.status !== 'active'" @click="openAdd(account)">添加第三方归集地址</n-button>
            </n-space>
            <n-data-table :columns="columns" :data="account.collection_addresses || []" :loading="loading" :scroll-x="1500" :pagination="{ pageSize: 10 }" />
          </n-space>
        </n-tab-pane>
      </n-tabs>
      <n-empty v-else description="暂无网络账户" />
    </n-card>

    <n-modal v-model:show="addShow" preset="dialog" title="添加第三方归集地址">
      <n-form :model="addForm" label-placement="top">
        <n-form-item label="网络账户"><NetworkTag :code="addForm.network_code" :label="networkLabel(addForm.network_code)" /></n-form-item>
        <n-form-item label="第三方归集地址"><n-input v-model:value="addForm.address" placeholder="请输入当前网络 EVM 地址" /></n-form-item>
      </n-form>
      <template #action>
        <n-space justify="end"><n-button @click="addShow = false">取消</n-button><n-button type="primary" :loading="adding" @click="addAddress">保存</n-button></n-space>
      </template>
    </n-modal>

  </n-space>
</template>

<script setup lang="ts">
import { computed, h, onMounted, reactive, ref } from 'vue'
import { NButton, NPopconfirm, NSpace, NSwitch, NTag, NText, NTooltip, useMessage } from 'naive-ui'
import { RefreshOutline, SearchOutline } from '@vicons/ionicons5'
import { api } from '../api'
import { NetworkTag, renderNetworkSelectLabel, renderNetworkSelectTag, shortNetworkOptions, networkLabel } from '../utils/networks'
import { renderShortText } from '../utils/shortText'
import { renderTokenAmount, renderTokenTag } from '../utils/money'

const message = useMessage()
const loading = ref(false)
const savingConfig = ref(false)
const syncingAll = ref(false)
const adding = ref(false)
const addShow = ref(false)
const accounts = ref<any[]>([])
const activeTab = ref('')
const syncEnabled = ref(true)
const syncInterval = ref(60)
const filters = reactive<any>({ network_code: null })
const appliedFilters = reactive<any>({ network_code: null })
const addForm = reactive<any>({ wallet_account_id: 0, network_code: '', address: '' })
const rowLoading = reactive<Record<string, boolean>>({})
const networkOptions = shortNetworkOptions

const filteredAccounts = computed(() => accounts.value.filter(account => !appliedFilters.network_code || account.network_code === appliedFilters.network_code))

const columns = [
  { title: '地址', key: 'address', width: 140, render: (row: any) => renderShortText(row.address) },
  { title: '类型', key: 'address_type', width: 100, render: (row: any) => h(NTag, { type: row.address_type === 'system' ? 'success' : 'warning', bordered: false }, { default: () => row.address_type === 'system' ? '系统生成' : '第三方' }) },
  { title: '当前启用', key: 'is_active', width: 110, render: (row: any) => h(NSwitch, { value: Number(row.is_active) === 1, loading: rowLoading[`active-${row.id}`], 'onUpdate:value': (value: boolean) => toggleActive(row, value) }) },
  { title: '参与同步', key: 'sync_enabled', width: 110, render: (row: any) => h(NSwitch, { value: Number(row.sync_enabled) === 1, loading: rowLoading[`sync-${row.id}`], 'onUpdate:value': (value: boolean) => toggleSync(row, value) }) },
  { title: () => renderTokenTag('USDC'), key: 'usdc_balance', width: 150, render: (row: any) => renderTokenAmount(row.usdc_balance || '0', 'USDC') },
  { title: () => renderTokenTag('USDT'), key: 'usdt_balance', width: 150, render: (row: any) => renderTokenAmount(row.usdt_balance || '0', 'USDT') },
  { title: 'Gas', key: 'native_balance', width: 120, render: (row: any) => `${row.native_balance || '0'} ${row.native_symbol || ''}` },
  { title: '同步状态', key: 'sync_status', width: 110, render: renderSyncStatus },
  { title: '最后同步', key: 'last_balance_sync_at', width: 170, render: (row: any) => row.last_balance_sync_at || '-' },
  { title: '操作', key: 'actions', width: 260, fixed: 'right', render: renderActions }
]

function renderSyncStatus(row: any) {
  const tag = () => h(NTag, { type: row.sync_status === 'success' ? 'success' : row.sync_status === 'failed' ? 'error' : 'warning', bordered: false }, { default: () => row.sync_status === 'success' ? '正常' : row.sync_status === 'failed' ? '失败' : '待同步' })
  if (row.sync_status !== 'failed' || !row.sync_error) return tag()
  return h(NTooltip, { trigger: 'hover', maxWidth: 520, contentStyle: { whiteSpace: 'normal', wordBreak: 'break-all' } }, { trigger: tag, default: () => row.sync_error })
}

function renderActions(row: any) {
  return h(NSpace, { wrap: false }, { default: () => [
    h(NButton, { size: 'small', secondary: true, type: 'primary', loading: rowLoading[`sync-one-${row.id}`], onClick: () => syncOne(row) }, { default: () => '同步' }),
    row.address_type === 'third_party' ? h(NPopconfirm, { onPositiveClick: () => deleteAddress(row) }, { trigger: () => h(NButton, { size: 'small', secondary: true, type: 'error' }, { default: () => '删除' }), default: () => '确认删除该归集地址？' }) : null
  ] })
}

async function load() {
  loading.value = true
  try {
    const data: any = await api.get('/admin/wallet/collection-wallets')
    accounts.value = data.accounts || []
    syncEnabled.value = Number(data.settings?.balance_sync_enabled ?? 1) === 1
    syncInterval.value = Number(data.settings?.balance_sync_interval_minutes || 60)
    if (!activeTab.value && accounts.value.length) activeTab.value = accounts.value[0].network_code
  } catch (e: any) { message.error(e.message) } finally { loading.value = false }
}
function search() { Object.assign(appliedFilters, filters); if (filteredAccounts.value.length) activeTab.value = filteredAccounts.value[0].network_code }
function resetSearch() { filters.network_code = null; appliedFilters.network_code = null; if (accounts.value.length) activeTab.value = accounts.value[0].network_code }
async function saveConfig() { savingConfig.value = true; try { const data: any = await api.post('/admin/wallet/collection-wallets/config/save', { balance_sync_enabled: syncEnabled.value, balance_sync_interval_minutes: syncInterval.value }); accounts.value = data.accounts || accounts.value; syncEnabled.value = Number(data.settings?.balance_sync_enabled ?? 1) === 1; syncInterval.value = Number(data.settings?.balance_sync_interval_minutes || syncInterval.value); message.success('余额同步设置已保存') } catch (e: any) { message.error(e.message) } finally { savingConfig.value = false } }
async function syncAll() { syncingAll.value = true; try { await api.post('/admin/wallet/collection-wallets/sync-all'); message.success('已提交同步全部归集钱包余额'); await load() } catch (e: any) { message.error(e.message) } finally { syncingAll.value = false } }
function openAdd(account: any) { Object.assign(addForm, { wallet_account_id: account.id, network_code: account.network_code, address: '' }); addShow.value = true }
async function addAddress() { adding.value = true; try { const data: any = await api.post('/admin/wallet/collection-address/add', addForm); accounts.value = data.accounts || accounts.value; addShow.value = false; message.success('第三方归集地址已添加') } catch (e: any) { message.error(e.message) } finally { adding.value = false } }
async function toggleActive(row: any, value: boolean) { rowLoading[`active-${row.id}`] = true; try { const data: any = await api.post('/admin/wallet/collection-address/toggle', { id: row.id, active: value }); accounts.value = data.accounts || accounts.value; message.success('当前归集地址已切换') } catch (e: any) { message.error(e.message) } finally { rowLoading[`active-${row.id}`] = false } }
async function toggleSync(row: any, value: boolean) { rowLoading[`sync-${row.id}`] = true; try { const data: any = await api.post('/admin/wallet/collection-address/sync-toggle', { id: row.id, sync_enabled: value }); accounts.value = data.accounts || accounts.value; message.success('同步状态已更新') } catch (e: any) { message.error(e.message) } finally { rowLoading[`sync-${row.id}`] = false } }
async function syncOne(row: any) { rowLoading[`sync-one-${row.id}`] = true; try { const data: any = await api.post('/admin/wallet/collection-address/sync', { id: row.id }); accounts.value = data.accounts || accounts.value; message.success('余额同步完成') } catch (e: any) { message.error(e.message) } finally { rowLoading[`sync-one-${row.id}`] = false } }
async function deleteAddress(row: any) { try { const data: any = await api.post('/admin/wallet/collection-address/delete', { id: row.id }); accounts.value = data.accounts || accounts.value; message.success('归集地址已删除') } catch (e: any) { message.error(e.message) } }
onMounted(load)
</script>
