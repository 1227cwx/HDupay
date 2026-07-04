<template>
  <n-space vertical size="large">
    <n-card title="筛选条件" :bordered="false">
      <n-grid cols="1 m:4 xl:6" responsive="screen" :x-gap="12" :y-gap="12">
        <n-gi>
          <n-form-item label="代理类型" :show-feedback="false">
            <n-select v-model:value="filters.proxy_type" clearable placeholder="全部类型" :options="typeOptions" />
          </n-form-item>
        </n-gi>
        <n-gi>
          <n-form-item label="状态" :show-feedback="false">
            <n-select v-model:value="filters.status" clearable placeholder="全部状态" :options="statusOptions" />
          </n-form-item>
        </n-gi>
        <n-gi>
          <n-form-item label="关键词" :show-feedback="false">
            <n-input v-model:value="filters.keyword" clearable placeholder="名称 / 地址 / 用户名" />
          </n-form-item>
        </n-gi>
        <n-gi span="1 m:4 xl:3">
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
                新增代理
              </n-button>
            </n-space>
          </n-form-item>
        </n-gi>
      </n-grid>
    </n-card>

    <n-card title="代理池" :bordered="false">
      <template #header-extra>
        <n-tag :bordered="false" type="info">共 {{ filteredRows.length }} 条</n-tag>
      </template>
      <n-space vertical size="large">
        <n-data-table :columns="columns" :data="pagedRows" :loading="loading" :scroll-x="1200" />
        <n-space justify="end">
          <n-pagination v-model:page="page" :page-size="perPage" :item-count="filteredRows.length" />
        </n-space>
      </n-space>
    </n-card>

    <n-modal v-model:show="formShow" preset="dialog" :title="form.id ? '编辑代理' : '新增代理'">
      <n-form :model="form" label-placement="top">
        <n-form-item label="代理名称">
          <n-input v-model:value="form.name" placeholder="例如：香港 SOCKS5H" />
        </n-form-item>
        <n-grid cols="1 m:2" responsive="screen" :x-gap="12">
          <n-gi>
            <n-form-item label="代理类型">
              <n-select v-model:value="form.proxy_type" :options="typeOptions" />
            </n-form-item>
          </n-gi>
          <n-gi>
            <n-form-item label="代理端口">
              <n-input-number v-model:value="form.port" :min="1" :max="65535" />
            </n-form-item>
          </n-gi>
        </n-grid>
        <n-form-item label="代理地址">
          <n-input v-model:value="form.host" placeholder="只填写域名或 IP；如果带 socks5:// 或 socks5h:// 后端也会自动去掉" />
        </n-form-item>
        <n-grid cols="1 m:2" responsive="screen" :x-gap="12">
          <n-gi>
            <n-form-item label="用户名（可选）">
              <n-input v-model:value="form.username" placeholder="无认证可留空" />
            </n-form-item>
          </n-gi>
          <n-gi>
            <n-form-item label="密码（可选）">
              <n-input v-model:value="form.password" type="password" show-password-on="click" placeholder="编辑时留空表示不修改" />
            </n-form-item>
          </n-gi>
        </n-grid>
        <n-form-item label="启用代理">
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

    <n-modal v-model:show="testShow" preset="dialog" title="代理测试过程">
      <n-space vertical size="large">
        <n-alert v-if="testResult.ok === true" type="success" title="代理测试成功" :bordered="false" />
        <n-alert v-else-if="testResult.ok === false" type="error" title="代理测试失败" :bordered="false">
          {{ testResult.error || '请检查代理地址、端口、用户名、密码或代理网络。' }}
        </n-alert>
        <n-spin :show="testing">
          <n-timeline>
            <n-timeline-item
              v-for="(step, index) in testSteps"
              :key="index"
              :type="timelineType(step.status)"
              :title="step.title"
              :content="step.message"
            />
          </n-timeline>
        </n-spin>
      </n-space>
      <template #action>
        <n-button @click="testShow = false">关闭</n-button>
      </template>
    </n-modal>
  </n-space>
</template>

<script setup lang="ts">
import { computed, h, onMounted, reactive, ref, watch } from 'vue'
import { NButton, NPopconfirm, NSpace, NTag, useMessage } from 'naive-ui'
import { AddCircleOutline, RefreshOutline, SearchOutline } from '@vicons/ionicons5'
import { api } from '../api'

const message = useMessage()
const loading = ref(false)
const saving = ref(false)
const testing = ref(false)
const rows = ref<any[]>([])
const page = ref(1)
const perPage = ref(10)
const filters = reactive<any>({ proxy_type: null, status: null, keyword: '' })
const appliedFilters = reactive<any>({ proxy_type: null, status: null, keyword: '' })
const formShow = ref(false)
const testShow = ref(false)
const testResult = ref<any>({})
const testSteps = ref<any[]>([])
const form = reactive<any>({
  id: 0,
  name: '',
  proxy_type: 'socks5h',
  host: '',
  port: 1080,
  username: '',
  password: '',
  enabled: true
})
const typeOptions = [
  { label: 'HTTP', value: 'http' },
  { label: 'HTTPS', value: 'https' },
  { label: 'SOCKS5', value: 'socks5' },
  { label: 'SOCKS5H', value: 'socks5h' }
]
const statusOptions = [
  { label: '启用', value: 'enabled' },
  { label: '禁用', value: 'disabled' }
]
const columns = [
  { title: '名称', key: 'name', width: 180, ellipsis: { tooltip: true } },
  { title: '类型', key: 'proxy_type', width: 110, render: (row: any) => h(NTag, { type: String(row.proxy_type).startsWith('socks5') ? 'info' : 'success', bordered: false }, { default: () => row.proxy_type.toUpperCase() }) },
  { title: '地址', key: 'host', width: 220, ellipsis: { tooltip: true } },
  { title: '端口', key: 'port', width: 100 },
  { title: '用户名', key: 'username', width: 140, render: (row: any) => row.username || '-' },
  { title: '密码', key: 'password_masked', width: 140, render: (row: any) => row.password_masked || '-' },
  { title: '状态', key: 'status', width: 100, render: (row: any) => h(NTag, { type: row.status === 'enabled' ? 'success' : 'default', bordered: false }, { default: () => row.status === 'enabled' ? '启用' : '禁用' }) },
  { title: '最后测试', key: 'last_test_status', width: 180, render: (row: any) => lastTest(row) },
  {
    title: '操作',
    key: 'actions',
    width: 330,
    render: (row: any) => h(NSpace, null, {
      default: () => [
        h(NButton, { size: 'small', type: 'primary', secondary: true, onClick: () => edit(row) }, { default: () => '编辑' }),
        h(NButton, { size: 'small', type: row.status === 'enabled' ? 'warning' : 'success', secondary: true, onClick: () => toggle(row) }, { default: () => row.status === 'enabled' ? '禁用' : '启用' }),
        h(NButton, { size: 'small', type: 'info', secondary: true, onClick: () => test(row) }, { default: () => '测试' }),
        h(NPopconfirm, { onPositiveClick: () => remove(row) }, {
          trigger: () => h(NButton, { size: 'small', type: 'error', secondary: true }, { default: () => '删除' }),
          default: () => '确认删除该代理？如果 RPC 正在使用，后端会拒绝删除。'
        })
      ]
    })
  }
]

const filteredRows = computed(() => rows.value.filter((row: any) => {
  if (appliedFilters.proxy_type && row.proxy_type !== appliedFilters.proxy_type) return false
  if (appliedFilters.status && row.status !== appliedFilters.status) return false
  const keyword = String(appliedFilters.keyword || '').trim().toLowerCase()
  if (keyword) {
    const haystack = [row.name, row.host, row.username, row.proxy_type, row.port].map(item => String(item || '').toLowerCase()).join(' ')
    if (!haystack.includes(keyword)) return false
  }
  return true
}))

const pagedRows = computed(() => {
  const start = (page.value - 1) * perPage.value
  return filteredRows.value.slice(start, start + perPage.value)
})

watch(filteredRows, () => {
  const maxPage = Math.max(1, Math.ceil(filteredRows.value.length / perPage.value))
  if (page.value > maxPage) page.value = maxPage
})

function search() {
  Object.assign(appliedFilters, filters)
  page.value = 1
}

function resetSearch() {
  Object.assign(filters, { proxy_type: null, status: null, keyword: '' })
  Object.assign(appliedFilters, { proxy_type: null, status: null, keyword: '' })
  page.value = 1
}

function lastTest(row: any) {
  if (!row.last_test_status) {
    return '-'
  }
  return h(NTag, { type: row.last_test_status === 'success' ? 'success' : 'error', bordered: false }, {
    default: () => row.last_test_status === 'success' ? '成功' : '失败'
  })
}

function openCreate() {
  Object.assign(form, { id: 0, name: '', proxy_type: 'socks5h', host: '', port: 1080, username: '', password: '', enabled: true })
  formShow.value = true
}

function edit(row: any) {
  Object.assign(form, {
    id: row.id,
    name: row.name,
    proxy_type: row.proxy_type,
    host: row.host,
    port: Number(row.port),
    username: row.username || '',
    password: '',
    enabled: row.status === 'enabled'
  })
  formShow.value = true
}

async function load() {
  loading.value = true
  try {
    rows.value = await api.get('/admin/proxy/list')
  } catch (e: any) {
    message.error(e.message)
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  try {
    await api.post('/admin/proxy/save', { ...form, status: form.enabled ? 'enabled' : 'disabled' })
    message.success('代理已保存')
    formShow.value = false
    await load()
  } catch (e: any) {
    message.error(e.message)
  } finally {
    saving.value = false
  }
}

async function toggle(row: any) {
  try {
    await api.post('/admin/proxy/toggle', { id: row.id, status: row.status === 'enabled' ? 'disabled' : 'enabled' })
    message.success('代理状态已更新')
    await load()
  } catch (e: any) {
    message.error(e.message)
  }
}

async function remove(row: any) {
  try {
    await api.post('/admin/proxy/delete', { id: row.id })
    message.success('代理已删除')
    await load()
  } catch (e: any) {
    message.error(e.message)
  }
}

async function test(row: any) {
  testShow.value = true
  testing.value = true
  testResult.value = {}
  testSteps.value = [
    { title: '读取代理配置', status: 'process', message: row.name },
    { title: '通过代理请求测试地址', status: 'wait', message: '等待执行' },
    { title: '代理测试完成', status: 'wait', message: '等待执行' }
  ]
  try {
    const data: any = await api.post('/admin/proxy/test', { id: row.id })
    testResult.value = data
    testSteps.value = data.steps || []
    if (data.ok) {
      message.success('代理测试成功')
    } else {
      message.error(data.error || '代理测试失败')
    }
    await load()
  } catch (e: any) {
    testResult.value = { ok: false, error: e.message }
    testSteps.value = [{ title: '代理测试失败', status: 'error', message: e.message }]
    message.error(e.message)
  } finally {
    testing.value = false
  }
}

function timelineType(status: string) {
  if (status === 'finish') return 'success'
  if (status === 'error') return 'error'
  if (status === 'process') return 'info'
  return 'default'
}

onMounted(load)
</script>
