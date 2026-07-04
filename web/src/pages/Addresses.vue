<template>
  <n-space vertical size="large">
    <n-card title="筛选条件" :bordered="false">
      <n-grid cols="1 m:3 xl:5" responsive="screen" :x-gap="12" :y-gap="12">
        <n-gi><n-select v-model:value="filters.network_code" clearable placeholder="选择网络" :options="networkOptions" :render-label="renderNetworkSelectLabel" :render-tag="renderNetworkSelectTag" /></n-gi>
        <n-gi><n-select v-model:value="filters.status" clearable placeholder="选择状态" :options="statusOptions" /></n-gi>
        <n-gi span="1 m:3 xl:3">
          <n-space>
            <n-button type="primary" :loading="loading" @click="search">
              <template #icon><n-icon><SearchOutline /></n-icon></template>
              查询地址
            </n-button>
            <n-button @click="resetSearch">重置</n-button>
            <n-button secondary type="primary" :loading="loading" @click="load">
              <template #icon><n-icon><RefreshOutline /></n-icon></template>
              刷新
            </n-button>
          </n-space>
        </n-gi>
      </n-grid>
    </n-card>

    <n-card title="地址池列表" :bordered="false">
      <n-space vertical size="large">
        <n-data-table :columns="columns" :data="rows" :loading="loading" :scroll-x="900" />
        <n-space justify="end">
          <n-pagination v-model:page="page" :page-size="perPage" :item-count="total" @update:page="load" />
        </n-space>
      </n-space>
    </n-card>
  </n-space>
</template>

<script setup lang="ts">
import { h, onMounted, reactive, ref } from 'vue'
import { NTag, useMessage } from 'naive-ui'
import { RefreshOutline, SearchOutline } from '@vicons/ionicons5'
import { api } from '../api'
import { renderNetworkSelectLabel, renderNetworkSelectTag, renderNetworkTag, shortNetworkOptions } from '../utils/networks'

const message = useMessage()
const loading = ref(false)
const rows = ref<any[]>([])
const total = ref(0)
const page = ref(1)
const perPage = ref(10)
const filters = reactive<any>({ network_code: null, status: null })
const networkOptions = shortNetworkOptions
const statusOptions = [
  { label: '可用', value: 'available' },
  { label: '已分配', value: 'assigned' },
  { label: '已检测付款', value: 'paid_detected' },
  { label: '已冻结', value: 'frozen' },
  { label: '已归集', value: 'collected' },
  { label: '已过期', value: 'expired' }
]
const columns = [
  { title: '网络', key: 'network_code', width: 130, render: (row: any) => renderNetworkTag(row) },
  { title: '地址', key: 'address_lower', width: 420, render: (row: any) => row.address_lower || '-' },
  { title: '状态', key: 'status', width: 140, render: (row: any) => h(NTag, { type: statusType(row.status), bordered: false }, { default: () => statusText(row.status) }) },
  { title: '订单号', key: 'assigned_order_no', width: 220, ellipsis: { tooltip: true }, render: (row: any) => row.assigned_order_no || '-' }
]

async function load() {
  loading.value = true
  try {
    const query = new URLSearchParams()
    query.set('page', String(page.value))
    query.set('per_page', String(perPage.value))
    if (filters.network_code) query.set('network_code', filters.network_code)
    if (filters.status) query.set('status', filters.status)
    const data: any = await api.get('/admin/address/list?' + query.toString())
    rows.value = data.items
    total.value = data.total
  } catch (e: any) {
    message.error(e.message)
  } finally {
    loading.value = false
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

function statusText(status: string) {
  const map: Record<string, string> = {
    available: '可用',
    assigned: '已分配',
    paid_detected: '已检测付款',
    frozen: '已冻结',
    collected: '已归集',
    expired: '已过期'
  }
  return map[status] || status || '-'
}

function statusType(status: string) {
  if (status === 'available') return 'success'
  if (status === 'assigned' || status === 'paid_detected') return 'warning'
  if (status === 'frozen' || status === 'collected') return 'info'
  return 'default'
}

onMounted(load)
</script>
