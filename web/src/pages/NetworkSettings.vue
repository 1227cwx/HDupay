<template>
  <n-space vertical size="large">
    <n-card title="筛选条件" :bordered="false">
      <n-grid cols="1 m:3 xl:5" responsive="screen" :x-gap="12" :y-gap="12">
        <n-gi>
          <n-form-item label="网络" :show-feedback="false">
            <n-select v-model:value="filters.network_code" clearable placeholder="全部网络" :options="networkOptions" :render-label="renderNetworkSelectLabel" :render-tag="renderNetworkSelectTag" />
          </n-form-item>
        </n-gi>
        <n-gi>
          <n-form-item label="自动监听" :show-feedback="false">
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

    <n-card title="网络配置" :bordered="false">
      <n-data-table :columns="columns" :data="filteredRows" :loading="loading" :scroll-x="1500" :pagination="{ pageSize: 10 }" />
    </n-card>

    <n-modal v-model:show="formShow" preset="dialog" title="编辑网络配置">
      <n-form :model="form" label-placement="top">
        <n-form-item label="网络">
          <n-select v-model:value="form.network_code" :options="networkOptions" disabled :render-label="renderNetworkSelectLabel" :render-tag="renderNetworkSelectTag" />
        </n-form-item>
        <n-form-item label="启用自动监听">
          <n-switch v-model:value="form.enabled" />
        </n-form-item>
        <n-form-item label="USDC 合约地址">
          <n-input v-model:value="form.contract_address" placeholder="请输入当前网络 USDC 合约地址" />
        </n-form-item>
        <n-form-item label="USDT 合约地址">
          <n-input v-model:value="form.usdt_contract_address" placeholder="请输入当前网络 USDT 合约地址" />
        </n-form-item>
        <n-grid cols="1 m:2" responsive="screen" :x-gap="12">
          <n-gi>
            <n-form-item label="监听间隔（秒）">
              <n-input-number v-model:value="form.monitor_interval_seconds" :min="2" :max="3600" />
            </n-form-item>
          </n-gi>
          <n-gi>
            <n-form-item label="扫描步长">
              <n-input-number v-model:value="form.scan_step_blocks" :min="1" :max="100000" />
            </n-form-item>
          </n-gi>
        </n-grid>
        <n-grid cols="1 m:2" responsive="screen" :x-gap="12">
          <n-gi>
            <n-form-item label="最小确认区块数">
              <n-input-number v-model:value="form.min_confirm_blocks" :min="1" :max="10000" />
            </n-form-item>
          </n-gi>
          <n-gi>
            <n-form-item label="最大确认区块数">
              <n-input-number v-model:value="form.confirm_blocks" :min="1" :max="10000" />
            </n-form-item>
          </n-gi>
        </n-grid>
        <n-form-item label="大额阈值（USDC/USDT）">
          <n-input v-model:value="form.large_amount_threshold" placeholder="超过该数量使用最大确认区块数" />
        </n-form-item>
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
import { NButton, NSpace, NSwitch, NTag, useMessage } from 'naive-ui'
import { CreateOutline, RefreshOutline, SearchOutline } from '@vicons/ionicons5'
import { api } from '../api'
import { renderNetworkSelectLabel, renderNetworkSelectTag, renderNetworkTag } from '../utils/networks'
import { renderShortText } from '../utils/shortText'

const message = useMessage()
const loading = ref(false)
const saving = ref(false)
const formShow = ref(false)
const rows = ref<any[]>([])
const networkOptions = ref<any[]>([])
const filters = reactive<any>({ network_code: null, enabled: null })
const appliedFilters = reactive<any>({ network_code: null, enabled: null })
const form = reactive<any>({})

const enabledOptions = [
  { label: '启用', value: 1 },
  { label: '停用', value: 0 }
]

const filteredRows = computed(() => rows.value.filter(row => {
  if (appliedFilters.network_code && row.network_code !== appliedFilters.network_code) return false
  if (appliedFilters.enabled !== null && appliedFilters.enabled !== undefined && Number(row.enabled || 0) !== Number(appliedFilters.enabled)) return false
  return true
}))

const columns = [
  { title: '网络', key: 'network_code', width: 140, render: renderNetworkTag },
  { title: '自动监听', key: 'enabled', width: 120, render: (row: any) => h(NTag, { type: Number(row.enabled) ? 'success' : 'default', bordered: false }, { default: () => Number(row.enabled) ? '启用' : '停用' }) },
  { title: '监听间隔', key: 'monitor_interval_seconds', width: 110, render: (row: any) => `${row.monitor_interval_seconds} 秒` },
  { title: '确认区块', key: 'confirm_range', width: 140, render: (row: any) => `${row.min_confirm_blocks || row.confirm_blocks} / ${row.confirm_blocks}` },
  { title: '大额阈值', key: 'large_amount_threshold', width: 150, render: (row: any) => `${row.large_amount_threshold || '100'} USDC/USDT` },
  { title: '扫描步长', key: 'scan_step_blocks', width: 100 },
  { title: 'USDC 合约', key: 'contract_address', width: 130, render: (row: any) => renderShortText(row.contract_address) },
  { title: 'USDT 合约', key: 'usdt_contract_address', width: 130, render: (row: any) => renderShortText(row.usdt_contract_address) },
  { title: '最后监听时间', key: 'last_monitor_at', width: 170, render: (row: any) => row.last_monitor_at || '-' },
  {
    title: '操作',
    key: 'actions',
    width: 100,
    fixed: 'right',
    render: (row: any) => h(NButton, { size: 'small', type: 'primary', secondary: true, onClick: () => edit(row) }, {
      icon: () => h(CreateOutline),
      default: () => '编辑'
    })
  }
]

async function load() {
  loading.value = true
  try {
    const data: any = await api.get('/admin/rpc-config/list')
    rows.value = data.network_settings || []
    networkOptions.value = data.networks || []
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
  filters.enabled = null
  appliedFilters.network_code = null
  appliedFilters.enabled = null
}

function edit(row: any) {
  Object.assign(form, {
    network_code: row.network_code,
    enabled: Number(row.enabled || 0) === 1,
    contract_address: row.contract_address || row.usdc_contract_address || '',
    usdt_contract_address: row.usdt_contract_address || '',
    monitor_interval_seconds: Number(row.monitor_interval_seconds || 10),
    min_confirm_blocks: Number(row.min_confirm_blocks || row.confirm_blocks || 12),
    confirm_blocks: Number(row.confirm_blocks || 12),
    scan_step_blocks: Number(row.scan_step_blocks || 500),
    large_amount_threshold: String(row.large_amount_threshold || '100')
  })
  formShow.value = true
}

async function save() {
  saving.value = true
  try {
    await api.post('/admin/rpc-config/network/save', { ...form, enabled: form.enabled })
    message.success('网络配置已保存')
    formShow.value = false
    await load()
  } catch (e: any) {
    message.error(e.message)
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>
