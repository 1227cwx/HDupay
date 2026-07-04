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
          <n-form-item label="代币" :show-feedback="false">
            <n-select v-model:value="filters.token_code" clearable placeholder="全部代币" :options="tokenOptions" :render-label="renderTokenSelectLabel" :render-tag="renderTokenSelectTag" />
          </n-form-item>
        </n-gi>
        <n-gi>
          <n-form-item label="自动转出" :show-feedback="false">
            <n-select v-model:value="filters.enabled" clearable placeholder="全部状态" :options="enabledOptions" />
          </n-form-item>
        </n-gi>
        <n-gi span="1 m:3 xl:3">
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
            </n-space>
          </n-form-item>
        </n-gi>
      </n-grid>
    </n-card>

    <n-card title="转出设置" :bordered="false">
      <n-data-table :columns="columns" :data="filteredRows" :loading="loading" :scroll-x="1400" :pagination="{ pageSize: 10 }" />
    </n-card>

    <n-modal v-model:show="formShow" preset="dialog" title="编辑转出设置">
      <n-form :model="form" label-placement="top">
        <n-form-item label="网络"><NetworkTag :code="form.network_code" :label="networkLabel(form.network_code)" /></n-form-item>
        <n-form-item label="代币"><n-select v-model:value="form.token_code" :options="tokenOptions" disabled :render-label="renderTokenSelectLabel" :render-tag="renderTokenSelectTag" /></n-form-item>
        <n-form-item label="自动转出"><n-switch v-model:value="form.enabled" /></n-form-item>
        <n-form-item label="转出接收地址"><n-input v-model:value="form.target_address" placeholder="请输入当前网络的 EVM 地址" clearable /></n-form-item>
        <n-form-item label="最小转出数量"><n-input v-model:value="form.min_amount" placeholder="默认 0，表示只要有余额就转出" /></n-form-item>
        <n-form-item label="最大重试次数"><n-input-number v-model:value="form.max_retry_count" :min="0" :max="100" :step="1" /></n-form-item>
      </n-form>
      <template #action>
        <n-space justify="end">
          <n-button @click="formShow = false">取消</n-button>
          <n-button type="primary" :loading="saving" @click="save">保存</n-button>
        </n-space>
      </template>
    </n-modal>
  </n-space>
</template>

<script setup lang="ts">
import { computed, h, onMounted, reactive, ref } from 'vue'
import { NButton, NSpace, NSwitch, NTag, NTooltip, useMessage } from 'naive-ui'
import { RefreshOutline, SearchOutline } from '@vicons/ionicons5'
import { api } from '../api'
import { NetworkTag, networkLabel, renderNetworkSelectLabel, renderNetworkSelectTag, renderNetworkTag, shortNetworkOptions } from '../utils/networks'
import { renderShortText } from '../utils/shortText'
import { fallbackTokenOptions, renderTokenAmount, renderTokenSelectLabel, renderTokenSelectTag, renderTokenTag } from '../utils/money'

const message = useMessage()
const loading = ref(false)
const saving = ref(false)
const formShow = ref(false)
const rows = ref<any[]>([])
const filters = reactive<any>({ network_code: null, token_code: null, enabled: null })
const appliedFilters = reactive<any>({ network_code: null, token_code: null, enabled: null })
const form = reactive<any>({ id: 0, wallet_account_id: 0, network_code: '', token_code: '', enabled: false, target_address: '', min_amount: '0', max_retry_count: 3 })
const networkOptions = shortNetworkOptions
const tokenOptions = fallbackTokenOptions
const enabledOptions = [
  { label: '启用', value: 1 },
  { label: '停用', value: 0 }
]

const filteredRows = computed(() => rows.value.filter(row => {
  if (appliedFilters.network_code && row.network_code !== appliedFilters.network_code) return false
  if (appliedFilters.token_code && row.token_code !== appliedFilters.token_code) return false
  if (appliedFilters.enabled !== null && appliedFilters.enabled !== undefined && Number(row.enabled || 0) !== Number(appliedFilters.enabled)) return false
  return true
}))

const columns = [
  { title: '网络', key: 'network_code', width: 140, render: renderNetworkTag },
  { title: '代币', key: 'token_code', width: 120, render: (row: any) => renderTokenTag(row.token_code || 'USDC') },
  { title: '自动转出', key: 'enabled', width: 110, render: (row: any) => h(NTag, { type: Number(row.enabled) ? 'success' : 'default', bordered: false }, { default: () => Number(row.enabled) ? '启用' : '停用' }) },
  { title: '接收地址', key: 'target_address', width: 140, render: (row: any) => row.target_address ? renderShortText(row.target_address) : '-' },
  { title: '最小转出数量', key: 'min_amount', width: 160, render: (row: any) => row.min_amount === '0' ? '不限制' : renderTokenAmount(row.min_amount || '0', row.token_code) },
  { title: '最大重试次数', key: 'max_retry_count', width: 120, render: (row: any) => String(row.max_retry_count ?? 3) },
  { title: '最后执行时间', key: 'last_run_at', width: 170, render: (row: any) => row.last_run_at || '-' },
  { title: '状态', key: 'status', width: 120, render: renderStatus },
  { title: '更新时间', key: 'updated_at', width: 170 },
  { title: '操作', key: 'actions', width: 100, fixed: 'right', render: (row: any) => h(NButton, { size: 'small', type: 'primary', secondary: true, onClick: () => edit(row) }, { default: () => '编辑' }) }
]

function renderStatus(row: any) {
  const tag = () => h(NTag, { type: row.status === 'failed' ? 'error' : Number(row.enabled) ? 'success' : 'default', bordered: false }, {
    default: () => row.status === 'failed' ? '失败' : Number(row.enabled) ? '启用' : '停用'
  })
  if (row.status !== 'failed' || !row.error_message) {
    return tag()
  }
  return h(NTooltip, { trigger: 'hover', maxWidth: 520, contentStyle: { whiteSpace: 'normal', wordBreak: 'break-all' } }, {
    trigger: tag,
    default: () => row.error_message
  })
}

async function load() {
  loading.value = true
  try {
    const data: any = await api.get('/admin/withdraw/settings')
    rows.value = data.settings || []
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
  filters.token_code = null
  filters.enabled = null
  appliedFilters.network_code = null
  appliedFilters.token_code = null
  appliedFilters.enabled = null
}

function edit(row: any) {
  Object.assign(form, {
    id: row.id,
    wallet_account_id: row.wallet_account_id,
    network_code: row.network_code,
    token_code: row.token_code,
    enabled: Number(row.enabled || 0) === 1,
    target_address: row.target_address || '',
    min_amount: row.min_amount || '0',
    max_retry_count: Number(row.max_retry_count ?? 3)
  })
  formShow.value = true
}

async function save() {
  saving.value = true
  try {
    const data: any = await api.post('/admin/withdraw/setting/save', form)
    rows.value = data.settings || rows.value
    formShow.value = false
    message.success('转出设置已保存')
  } catch (e: any) {
    message.error(e.message)
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>
