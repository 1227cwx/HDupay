<template>
  <n-space vertical size="large">
    <n-card title="筛选条件" :bordered="false">
      <n-grid cols="1 m:3 xl:5" responsive="screen" :x-gap="12" :y-gap="12">
        <n-gi>
          <n-form-item label="关键词" :show-feedback="false">
            <n-input v-model:value="filters.keyword" clearable placeholder="名称 / API Key" />
          </n-form-item>
        </n-gi>
        <n-gi>
          <n-form-item label="状态" :show-feedback="false">
            <n-select v-model:value="filters.status" clearable placeholder="全部状态" :options="statusOptions" />
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
              <n-button type="primary" @click="openCreate">
                <template #icon><n-icon><AddCircleOutline /></n-icon></template>
                添加 API
              </n-button>
            </n-space>
          </n-form-item>
        </n-gi>
      </n-grid>
    </n-card>

    <n-card title="开放 API" :bordered="false">
      <n-space vertical size="large">
        <n-data-table :columns="columns" :data="rows" :loading="loading" :scroll-x="1560" />
        <n-space justify="end">
          <n-pagination v-model:page="page" :page-size="perPage" :item-count="total" @update:page="load" />
        </n-space>
      </n-space>
    </n-card>

    <n-modal v-model:show="formShow" preset="dialog" :title="form.id ? '编辑 API' : '添加 API'">
      <n-form :model="form" label-placement="top">
        <n-form-item label="名称">
          <n-input v-model:value="form.name" placeholder="请输入 API 名称" />
        </n-form-item>
        <n-form-item label="回调地址">
          <n-input v-model:value="form.callback_url" placeholder="可留空，留空则不回调" />
        </n-form-item>
        <n-form-item label="IP 白名单">
          <n-input
            v-model:value="form.ip_whitelist"
            type="textarea"
            placeholder="默认 0.0.0.0，一行一个 IP"
            :autosize="{ minRows: 4, maxRows: 10 }"
          />
        </n-form-item>
        <n-form-item label="启用">
          <n-switch v-model:value="form.enabled" />
        </n-form-item>
      </n-form>
      <template #action>
        <n-space justify="end">
          <n-button @click="formShow = false">取消</n-button>
          <n-button type="primary" :loading="saving" @click="save">保存</n-button>
        </n-space>
      </template>
    </n-modal>

    <n-modal v-model:show="secretShow" preset="dialog" title="API 密钥">
      <n-descriptions label-placement="left" bordered :column="1">
        <n-descriptions-item label="API Key">{{ createdSecret.api_key }}</n-descriptions-item>
        <n-descriptions-item label="API Key Secret">{{ createdSecret.api_secret }}</n-descriptions-item>
      </n-descriptions>
      <template #action>
        <n-button type="primary" @click="secretShow = false">关闭</n-button>
      </template>
    </n-modal>
  </n-space>
</template>

<script setup lang="ts">
import { h, onMounted, reactive, ref } from 'vue'
import { NButton, NPopconfirm, NPopover, NSpace, NTag, NText, useMessage } from 'naive-ui'
import { AddCircleOutline, RefreshOutline, SearchOutline } from '@vicons/ionicons5'
import { api } from '../api'
import { renderShortText } from '../utils/shortText'

const message = useMessage()
const loading = ref(false)
const saving = ref(false)
const formShow = ref(false)
const secretShow = ref(false)
const rows = ref<any[]>([])
const total = ref(0)
const page = ref(1)
const perPage = ref(10)
const filters = reactive({ keyword: '', status: null as string | null })
const form = reactive<any>({ id: 0, name: '', callback_url: '', ip_whitelist: '0.0.0.0', enabled: true })
const createdSecret = ref<any>({})
const statusOptions = [
  { label: '启用', value: 'enabled' },
  { label: '禁用', value: 'disabled' }
]

const columns = [
  { title: '名称', key: 'name', width: 160, ellipsis: { tooltip: true } },
  { title: 'API Key', key: 'api_key', width: 260, render: (row: any) => renderShortText(row.api_key, 18) },
  { title: '回调地址', key: 'callback_url', width: 260, render: (row: any) => row.callback_url ? renderShortText(row.callback_url, 18) : '-' },
  { title: 'IP 白名单', key: 'ip_whitelist', width: 160, render: renderWhitelist },
  { title: '状态', key: 'status', width: 100, render: (row: any) => h(NTag, { type: row.status === 'enabled' ? 'success' : 'default', bordered: false }, { default: () => row.status === 'enabled' ? '启用' : '禁用' }) },
  { title: '最后调用', key: 'last_used_at', width: 170, render: (row: any) => row.last_used_at || '-' },
  { title: '创建时间', key: 'created_at', width: 170 },
  { title: '操作', key: 'actions', width: 340, fixed: 'right', render: renderActions }
]

function renderWhitelist(row: any) {
  const value = String(row.ip_whitelist || '0.0.0.0')
  const list = value.split(',').map(item => item.trim()).filter(Boolean)
  if (list.length <= 2 && value.length <= 28) {
    return value
  }
  return h(NPopover, { trigger: 'click', width: 260 }, {
    trigger: () => h(NButton, { text: true, type: 'primary' }, { default: () => '查看' }),
    default: () => h(NSpace, { vertical: true, size: 4 }, {
      default: () => list.map(ip => h(NText, { code: true }, { default: () => ip }))
    })
  })
}

function renderActions(row: any) {
  return h(NSpace, { wrap: false }, {
    default: () => [
      h(NButton, { size: 'small', type: 'primary', secondary: true, onClick: () => edit(row) }, { default: () => '编辑' }),
      h(NPopconfirm, { onPositiveClick: () => resetSecret(row) }, {
        trigger: () => h(NButton, { size: 'small', type: 'info', secondary: true }, { default: () => '重置密钥' }),
        default: () => '重置后旧的 API Key Secret 将失效，确认继续？'
      }),
      h(NButton, { size: 'small', type: row.status === 'enabled' ? 'warning' : 'success', secondary: true, onClick: () => toggle(row) }, { default: () => row.status === 'enabled' ? '禁用' : '启用' }),
      h(NPopconfirm, { onPositiveClick: () => remove(row) }, {
        trigger: () => h(NButton, { size: 'small', type: 'error', secondary: true }, { default: () => '删除' }),
        default: () => '确认删除该 API？'
      })
    ]
  })
}

async function load() {
  loading.value = true
  try {
    const query = new URLSearchParams()
    query.set('page', String(page.value))
    query.set('per_page', String(perPage.value))
    if (filters.keyword) query.set('keyword', filters.keyword)
    if (filters.status) query.set('status', filters.status)
    const data: any = await api.get('/admin/open-api/list?' + query.toString())
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
  filters.keyword = ''
  filters.status = null
  page.value = 1
  load()
}

function openCreate() {
  Object.assign(form, { id: 0, name: '', callback_url: '', ip_whitelist: '0.0.0.0', enabled: true })
  formShow.value = true
}

function edit(row: any) {
  Object.assign(form, {
    id: row.id,
    name: row.name,
    callback_url: row.callback_url || '',
    ip_whitelist: formatWhitelistForInput(row.ip_whitelist || '0.0.0.0'),
    enabled: row.status === 'enabled'
  })
  formShow.value = true
}

function formatWhitelistForInput(value: string) {
  return String(value || '0.0.0.0').split(',').map(item => item.trim()).filter(Boolean).join('\n') || '0.0.0.0'
}

async function save() {
  saving.value = true
  try {
    const data: any = await api.post('/admin/open-api/save', { ...form, status: form.enabled ? 'enabled' : 'disabled' })
    message.success('API 信息已保存')
    formShow.value = false
    if (data.api_secret) {
      createdSecret.value = data
      secretShow.value = true
    }
    await load()
  } catch (e: any) {
    message.error(e.message)
  } finally {
    saving.value = false
  }
}

async function toggle(row: any) {
  try {
    await api.post('/admin/open-api/toggle', { id: row.id, status: row.status === 'enabled' ? 'disabled' : 'enabled' })
    message.success('API 状态已更新')
    await load()
  } catch (e: any) {
    message.error(e.message)
  }
}

async function remove(row: any) {
  try {
    await api.post('/admin/open-api/delete', { id: row.id })
    message.success('API 已删除')
    await load()
  } catch (e: any) {
    message.error(e.message)
  }
}

async function resetSecret(row: any) {
  try {
    const data: any = await api.post('/admin/open-api/secret/reset', { id: row.id })
    createdSecret.value = data
    secretShow.value = true
    message.success('API Key Secret 已重置')
    await load()
  } catch (e: any) {
    message.error(e.message)
  }
}

onMounted(load)
</script>
