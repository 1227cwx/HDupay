<template>
  <n-space vertical size="large">
    <n-card title="筛选条件" :bordered="false">
      <n-space vertical size="medium">

        <n-grid cols="1 m:4 xl:6" responsive="screen" :x-gap="12" :y-gap="12">
          <n-gi>
            <n-form-item label="选择网络" :show-feedback="false">
              <n-select v-model:value="filters.network_code" clearable placeholder="全部网络" :options="networkOptions" :render-label="renderNetworkSelectLabel" :render-tag="renderNetworkSelectTag" />
            </n-form-item>
          </n-gi>
          <n-gi>
            <n-form-item label="选择分组" :show-feedback="false">
              <n-select v-model:value="filters.group_id" clearable placeholder="全部分组" :options="searchGroupOptions" />
            </n-form-item>
          </n-gi>
          <n-gi>
            <n-form-item label="选择提供商" :show-feedback="false">
              <n-select v-model:value="filters.provider" clearable placeholder="全部提供商" :options="providerOptions" />
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
                <n-button secondary type="primary" :loading="loading" @click="loadAll">
                  <template #icon><n-icon><RefreshOutline /></n-icon></template>
                  刷新
                </n-button>
                <n-button type="success" secondary @click="openGroupManager">
                  <template #icon><n-icon><GitNetworkOutline /></n-icon></template>
                  分组
                </n-button>
                <n-button type="primary" secondary @click="createNode">
                  <template #icon><n-icon><AddCircleOutline /></n-icon></template>
                  添加 RPC 节点
                </n-button>
              </n-space>
            </n-form-item>
          </n-gi>
        </n-grid>
      </n-space>
    </n-card>

    <n-card :bordered="false">
      <template #header>
        <n-space vertical :size="2">
          <n-text strong>RPC 节点列表</n-text>
        </n-space>
      </template>
      <template #header-extra>
        <n-space>
          <n-tag :bordered="false" type="info">共 {{ filteredNodes.length }} 条</n-tag>
        </n-space>
      </template>
      <n-data-table :columns="nodeColumns" :data="filteredNodes" :loading="loading" :scroll-x="1500" :pagination="nodePagination" />
    </n-card>

    <n-modal v-model:show="networkManagerShow" preset="card" title="网络配置" :style="groupManagerModalStyle">
      <n-space vertical size="medium">
        <n-data-table :columns="networkColumns" :data="networkSettings" :loading="loading" :scroll-x="1520" :pagination="networkPagination" />
      </n-space>
    </n-modal>

    <n-modal v-model:show="groupManagerShow" preset="card" title="分组管理" :style="groupManagerModalStyle">
      <n-space vertical size="medium">
        <n-space justify="end">
          <n-button type="primary" @click="createGroup">
            <template #icon><n-icon><AddCircleOutline /></n-icon></template>
            添加分组
          </n-button>
        </n-space>
        <n-data-table :columns="groupColumns" :data="groups" :loading="loading" :pagination="groupPagination" />
      </n-space>
    </n-modal>

    <n-modal v-model:show="groupShow" preset="dialog" :title="groupForm.id ? '编辑分组' : '添加分组'">
      <n-form :model="groupForm" label-placement="top">
        <n-form-item label="网络">
          <n-select v-model:value="groupForm.network_code" :options="networkOptions" :disabled="!!groupForm.id" placeholder="请选择网络" :render-label="renderNetworkSelectLabel" :render-tag="renderNetworkSelectTag" />
        </n-form-item>
        <n-space v-if="groupForm.network_code" vertical size="small">
          <n-space align="center" size="small">
            <n-text type="warning">当前网络可添加 Infura、Dwellir、OnFinality 节点。登录入口：</n-text>
            <n-space size="small">
              <n-button v-for="item in providerLinks" :key="item.value" size="tiny" text tag="a" :href="item.console" target="_blank" type="warning">
                {{ item.label }}
              </n-button>
            </n-space>
          </n-space>
        </n-space>
        <n-form-item label="分组名称">
          <n-input v-model:value="groupForm.name" placeholder="请输入分组名称" />
        </n-form-item>
        <n-form-item label="轮询机制">
          <n-select v-model:value="groupForm.rotation_mode" :options="rotationOptions" />
        </n-form-item>
        <n-grid cols="1 m:2" responsive="screen" :x-gap="12">
          <n-gi>
            <n-form-item label="单节点请求次数">
              <n-input-number v-model:value="groupForm.single_attempts" :min="1" :max="10" />
            </n-form-item>
          </n-gi>
          <n-gi>
            <n-form-item label="最多尝试节点数">
              <n-input-number v-model:value="groupForm.max_nodes" :min="1" :max="50" />
            </n-form-item>
          </n-gi>
        </n-grid>
        <n-form-item label="设为当前网络使用分组">
          <n-switch v-model:value="groupForm.set_active" />
        </n-form-item>
      </n-form>
      <template #action>
        <n-space justify="end">
          <n-button @click="groupShow = false">取消</n-button>
          <n-button type="primary" :loading="saving" @click="saveGroup">
            <template #icon><n-icon><SaveOutline /></n-icon></template>
            保存
          </n-button>
        </n-space>
      </template>
    </n-modal>

    <n-modal v-model:show="networkSettingShow" preset="dialog" title="编辑网络配置">
      <n-form :model="networkForm" label-placement="top">
        <n-form-item label="网络">
          <n-select v-model:value="networkForm.network_code" :options="networkOptions" disabled :render-label="renderNetworkSelectLabel" :render-tag="renderNetworkSelectTag" />
        </n-form-item>
        <n-form-item label="启用自动监听">
          <n-switch v-model:value="networkForm.enabled" />
        </n-form-item>
        <n-form-item label="USDC 合约地址">
          <n-input v-model:value="networkForm.contract_address" placeholder="请输入当前网络 USDC 合约地址" />
        </n-form-item>
        <n-form-item label="USDT 合约地址">
          <n-input v-model:value="networkForm.usdt_contract_address" placeholder="请输入当前网络 USDT 合约地址" />
        </n-form-item>
        <n-grid cols="1 m:2" responsive="screen" :x-gap="12">
          <n-gi>
            <n-form-item label="监听间隔（秒）">
              <n-input-number v-model:value="networkForm.monitor_interval_seconds" :min="2" :max="3600" />
            </n-form-item>
          </n-gi>
          <n-gi>
            <n-form-item label="确认区块数">
              <n-input-number v-model:value="networkForm.confirm_blocks" :min="1" :max="10000" />
            </n-form-item>
          </n-gi>
          <n-gi>
            <n-form-item label="扫描步长">
              <n-input-number v-model:value="networkForm.scan_step_blocks" :min="1" :max="100000" />
            </n-form-item>
          </n-gi>
        </n-grid>
      </n-form>
      <template #action>
        <n-space justify="end">
          <n-button @click="networkSettingShow = false">取消</n-button>
          <n-button type="primary" :loading="networkSaving" @click="saveNetworkSetting">
            <template #icon><n-icon><SaveOutline /></n-icon></template>
            保存
          </n-button>
        </n-space>
      </template>
    </n-modal>

    <n-modal v-model:show="nodeShow" preset="dialog" title="RPC 节点">
      <n-form :model="nodeForm" label-placement="top">
        <n-grid cols="1 m:2" responsive="screen" :x-gap="12">
          <n-gi>
            <n-form-item label="网络">
              <n-select v-model:value="nodeForm.network_code" :options="networkOptions" :disabled="!!nodeForm.id" placeholder="请选择网络" @update:value="onNodeNetworkChange" :render-label="renderNetworkSelectLabel" :render-tag="renderNetworkSelectTag" />
            </n-form-item>
          </n-gi>
          <n-gi>
            <n-form-item label="分组（必选）">
              <n-select v-model:value="nodeForm.group_id" :options="groupOptionsForNetwork(nodeForm.network_code)" placeholder="请选择分组" />
            </n-form-item>
          </n-gi>
        </n-grid>
        <n-form-item label="节点名称"><n-input v-model:value="nodeForm.name" placeholder="例如：Infura Key 1" /></n-form-item>
        <n-form-item label="节点提供商">
          <n-select v-model:value="nodeForm.provider" :options="providerOptions" @update:value="onProviderChange" />
        </n-form-item>
        <n-space v-if="currentProviderLink" vertical size="small">
          <n-space align="center" size="small">
            <n-text type="warning">{{ currentProviderLink.label }} 官网：{{ currentProviderLink.home }}</n-text>
            <n-space size="small">
              <n-button size="tiny" text tag="a" :href="currentProviderLink.console" target="_blank" type="warning">打开控制台</n-button>
              <n-button size="tiny" text tag="a" :href="currentProviderLink.docs" target="_blank" type="warning">查看文档</n-button>
            </n-space>
          </n-space>
        </n-space>
        <n-form-item label="RPC URL"><n-input v-model:value="nodeForm.rpc_url" :placeholder="providerDefaultUrl(nodeForm.provider, nodeForm.network_code) || '请输入 RPC URL'" /></n-form-item>
        <n-form-item label="API Key"><n-input v-model:value="nodeForm.api_key" type="password" show-password-on="click" /></n-form-item>
        <n-form-item v-if="nodeForm.provider === 'infura'" label="使用 API Key Secret">
          <n-switch v-model:value="nodeForm.use_api_key_secret" />
        </n-form-item>
        <n-form-item v-if="nodeForm.provider === 'infura' && nodeForm.use_api_key_secret" label="API Key Secret">
          <n-input v-model:value="nodeForm.api_key_secret" type="password" show-password-on="click" />
        </n-form-item>
        <n-form-item label="RPC 代理">
          <n-select v-model:value="nodeForm.proxy_id" :options="proxyOptions" />
        </n-form-item>
        <n-grid cols="1 m:2" responsive="screen" :x-gap="12">
          <n-gi><n-form-item label="排序"><n-input-number v-model:value="nodeForm.sort_order" :min="0" /></n-form-item></n-gi>
          <n-gi><n-form-item label="启用节点"><n-switch v-model:value="nodeForm.enabled" /></n-form-item></n-gi>
        </n-grid>
      </n-form>
      <template #action>
        <n-space justify="end">
          <n-button @click="nodeShow = false">取消</n-button>
          <n-button type="primary" :loading="saving" @click="saveNode">
            <template #icon><n-icon><SaveOutline /></n-icon></template>
            保存
          </n-button>
        </n-space>
      </template>
    </n-modal>

    <n-modal v-model:show="testShow" preset="dialog" title="RPC 测试过程">
      <n-space vertical size="large">
        <n-alert v-if="testResult.ok === true" type="success" title="RPC 测试成功" :bordered="false">
          链编号：{{ testResult.chain_id }}，当前区块：{{ testResult.block_number }}
        </n-alert>
        <n-alert v-else-if="testResult.ok === false" type="error" title="RPC 测试失败" :bordered="false">
          {{ testResult.error || 'RPC 请求失败，请检查分组、节点、代理、API Key 或 API Key Secret。' }}
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
import { NButton, NIcon, NSpace, NSwitch, NTag, useDialog, useMessage } from 'naive-ui'
import { AddCircleOutline, CreateOutline, GitNetworkOutline, PlayOutline, RefreshOutline, SaveOutline, SearchOutline, TrashOutline } from '@vicons/ionicons5'
import { api } from '../api'
import { renderNetworkSelectLabel, renderNetworkSelectTag, renderNetworkTag } from '../utils/networks'

const message = useMessage()
const dialog = useDialog()
const loading = ref(false)
const saving = ref(false)
const networkSaving = ref(false)
const testing = ref(false)
const groupManagerShow = ref(false)
const networkManagerShow = ref(false)
const networkSettingShow = ref(false)
const groupShow = ref(false)
const nodeShow = ref(false)
const testShow = ref(false)
const networkSettings = ref<any[]>([])
const groups = ref<any[]>([])
const nodes = ref<any[]>([])
const proxies = ref<any[]>([])
const networkOptions = ref<any[]>([])
const providerOptions = ref<any[]>([
  { label: 'Infura', value: 'infura' },
  { label: 'Dwellir', value: 'dwellir' },
  { label: 'OnFinality', value: 'onfinality' }
])
const testSteps = ref<any[]>([])
const testResult = ref<any>({})
const filters = reactive<any>({ network_code: null, group_id: null, provider: null })
const appliedFilters = reactive<any>({ network_code: null, group_id: null, provider: null })
const networkForm = reactive<any>({})
const groupForm = reactive<any>({})
const nodeForm = reactive<any>({})

const rotationOptions = [
  { label: '随机轮询', value: 'random' },
  { label: '顺序轮询', value: 'sequence' }
]
const groupManagerModalStyle = {
  width: '1180px',
  maxWidth: '96vw'
}
const groupPagination = reactive({
  pageSize: 10
})
const nodePagination = reactive({
  pageSize: 10
})
const networkPagination = reactive({
  pageSize: 10
})
const groupPalette = [
  { color: '#e0f2fe', textColor: '#0369a1', borderColor: '#bae6fd' },
  { color: '#dcfce7', textColor: '#15803d', borderColor: '#bbf7d0' },
  { color: '#fef3c7', textColor: '#b45309', borderColor: '#fde68a' },
  { color: '#f3e8ff', textColor: '#7e22ce', borderColor: '#e9d5ff' },
  { color: '#ffe4e6', textColor: '#be123c', borderColor: '#fecdd3' },
  { color: '#ccfbf1', textColor: '#0f766e', borderColor: '#99f6e4' },
  { color: '#e0e7ff', textColor: '#4338ca', borderColor: '#c7d2fe' },
  { color: '#f1f5f9', textColor: '#334155', borderColor: '#cbd5e1' }
]
const providerLinks = [
  {
    label: 'Infura',
    value: 'infura',
    home: 'https://developer.metamask.io/',
    console: 'https://app.infura.io/',
    docs: 'https://docs.metamask.io/developer-tools/dashboard/how-to/secure-an-api/api-key-secret/'
  },
  {
    label: 'Dwellir',
    value: 'dwellir',
    home: 'https://www.dwellir.com/',
    console: 'https://dashboard.dwellir.com/',
    docs: 'https://www.dwellir.com/docs/getting-started'
  },
  {
    label: 'OnFinality',
    value: 'onfinality',
    home: 'https://onfinality.io/',
    console: 'https://app.onfinality.io/',
    docs: 'https://documentation.onfinality.io/support/the-enhanced-api-service'
  }
]
const providerRpcTemplates: Record<string, Record<string, string>> = {
  infura: {
    ethereum: 'https://mainnet.infura.io/v3/{api_key}',
    base: 'https://base-mainnet.infura.io/v3/{api_key}',
    polygon: 'https://polygon-mainnet.infura.io/v3/{api_key}',
    celo: 'https://celo-mainnet.infura.io/v3/{api_key}'
  },
  dwellir: {
    ethereum: 'https://api-ethereum-mainnet.n.dwellir.com/{api_key}',
    base: 'https://api-base-mainnet-archive.n.dwellir.com/{api_key}',
    polygon: 'https://api-polygon-mainnet-full.n.dwellir.com/{api_key}',
    celo: 'https://api-celo-mainnet-archive.n.dwellir.com/{api_key}'
  },
  onfinality: {
    ethereum: 'https://eth.api.onfinality.io/public?apikey={api_key}',
    base: 'https://base.api.onfinality.io/public?apikey={api_key}',
    polygon: 'https://polygon.api.onfinality.io/public?apikey={api_key}',
    celo: 'https://celo.api.onfinality.io/public?apikey={api_key}'
  }
}

const proxyOptions = computed(() => [
  { label: '直连', value: 0 },
  ...proxies.value.map(proxy => ({
    label: `${proxy.name}（${proxy.proxy_type.toUpperCase()} ${proxy.host}:${proxy.port}）`,
    value: Number(proxy.id)
  }))
])
const searchGroupOptions = computed(() => groups.value
  .filter(group => !filters.network_code || group.network_code === filters.network_code)
  .map(group => ({
  label: `${group.network_code} / ${group.name}`,
  value: Number(group.id)
})))
const filteredNodes = computed(() => nodes.value.filter(row => {
  if (appliedFilters.network_code && row.network_code !== appliedFilters.network_code) return false
  if (appliedFilters.group_id && Number(row.group_id || 0) !== Number(appliedFilters.group_id)) return false
  if (appliedFilters.provider && row.provider !== appliedFilters.provider) return false
  return true
}))
const currentProviderLink = computed(() => providerLinks.find(item => item.value === nodeForm.provider))

const networkColumns = [
  { title: '网络', key: 'network_code', width: 150, render: renderNetworkTag },
  { title: '自动监听', key: 'enabled', width: 150, render: renderNetworkMonitorSwitch },
  { title: '监听间隔', key: 'monitor_interval_seconds', width: 120, render: (row: any) => `${row.monitor_interval_seconds} 秒` },
  { title: '确认区块', key: 'confirm_blocks', width: 110 },
  { title: '扫描步长', key: 'scan_step_blocks', width: 110 },
  { title: 'USDC 合约', key: 'contract_address', width: 300, ellipsis: { tooltip: true } },
  { title: 'USDT 合约', key: 'usdt_contract_address', width: 300, ellipsis: { tooltip: true } },
  { title: '最后监听时间', key: 'last_monitor_at', width: 180, render: (row: any) => row.last_monitor_at || '-' },
  { title: '操作', key: 'actions', width: 110, fixed: 'right', render: renderNetworkActions }
]

const nodeColumns = [
  { title: '网络', key: 'network_code', width: 130, render: renderNetworkTag },
  { title: '分组', key: 'group_name', width: 160, render: renderNodeGroupTag },
  { title: '节点名称', key: 'name', width: 180, ellipsis: { tooltip: true }, render: (row: any) => row.name || '-' },
  { title: '提供商', key: 'provider', width: 100, render: (row: any) => h(NTag, { type: 'info', bordered: false }, { default: () => row.provider || 'infura' }) },
  { title: 'RPC URL', key: 'rpc_url', width: 320, ellipsis: { tooltip: true } },
  { title: 'Key', key: 'api_key_masked', width: 160, render: (row: any) => row.api_key_masked || '-' },
  { title: '代理', key: 'proxy_name', width: 180, ellipsis: { tooltip: true }, render: (row: any) => h(NTag, { type: row.proxy_id ? 'warning' : 'default', bordered: false }, { default: () => row.proxy_name || '直连' }) },
  { title: '状态', key: 'enabled', width: 100, render: (row: any) => h(NTag, { type: row.enabled ? 'success' : 'default', bordered: false }, { default: () => row.enabled ? '启用' : '禁用' }) },
  { title: '操作', key: 'actions', width: 340, fixed: 'right', render: renderNodeActions }
]
const groupColumns = [
  { title: '网络', key: 'network_code', width: 120, render: renderNetworkTag },
  { title: '分组名称', key: 'name', width: 180, render: renderGroupNameTag },
  { title: '轮询机制', key: 'rotation_mode', width: 130, render: (row: any) => h(NTag, { type: row.rotation_mode === 'sequence' ? 'info' : 'success', bordered: false }, { default: () => row.rotation_mode === 'sequence' ? '顺序轮询' : '随机轮询' }) },
  { title: '单节点请求', key: 'single_attempts', width: 120, render: (row: any) => `${row.single_attempts} 次` },
  { title: '最多节点', key: 'max_nodes', width: 110, render: (row: any) => `${row.max_nodes} 个` },
  { title: '节点数量', key: 'node_count', width: 100 },
  { title: '当前使用', key: 'active', width: 100, render: renderGroupActive },
  { title: '操作', key: 'actions', width: 180, render: renderGroupActions }
]

function renderNetworkMonitorSwitch(row: any) {
  return h(NSpace, { align: 'center', wrap: false }, {
    default: () => [
      h(NSwitch, {
        value: !!row.enabled,
        loading: networkSaving.value,
        'onUpdate:value': (value: boolean) => toggleNetworkMonitor(row, value)
      }),
      h(NTag, { type: row.enabled ? 'success' : 'default', bordered: false }, { default: () => row.enabled ? '已开启' : '已关闭' })
    ]
  })
}

function renderNetworkActions(row: any) {
  return h(NButton, { size: 'small', type: 'primary', secondary: true, onClick: () => openNetworkSetting(row) }, {
    icon: () => h(NIcon, null, { default: () => h(CreateOutline) }),
    default: () => '设置'
  })
}

function renderNodeGroupTag(row: any) {
  return h(NTag, { bordered: false, color: groupColor(Number(row.group_id || 0)) }, { default: () => row.group_name || '未分组' })
}

function renderGroupNameTag(row: any) {
  return h(NTag, { bordered: false, color: groupColor(Number(row.id || 0)) }, { default: () => row.name || '-' })
}

function renderNodeActions(row: any) {
  return h(NSpace, { wrap: false }, {
    default: () => [
      h(NButton, { size: 'small', type: 'primary', secondary: true, onClick: () => editNode(row) }, { icon: () => h(NIcon, null, { default: () => h(CreateOutline) }), default: () => '编辑' }),
      h(NButton, { size: 'small', type: 'info', secondary: true, onClick: () => testNode(row.id) }, { icon: () => h(NIcon, null, { default: () => h(PlayOutline) }), default: () => '测试' }),
      h(NButton, { size: 'small', type: row.enabled ? 'warning' : 'success', secondary: true, onClick: () => toggleNode(row) }, { default: () => row.enabled ? '禁用' : '启用' }),
      h(NButton, { size: 'small', type: 'error', secondary: true, onClick: () => confirmDeleteNode(row) }, { icon: () => h(NIcon, null, { default: () => h(TrashOutline) }), default: () => '删除' })
    ]
  })
}

function renderGroupActions(row: any) {
  return h(NSpace, null, {
    default: () => [
      h(NButton, { size: 'small', type: 'primary', secondary: true, onClick: () => editGroup(row) }, { icon: () => h(NIcon, null, { default: () => h(CreateOutline) }), default: () => '编辑' }),
      h(NButton, { size: 'small', type: 'info', secondary: true, onClick: () => testGroup(row.id) }, { icon: () => h(NIcon, null, { default: () => h(PlayOutline) }), default: () => '测试' })
    ]
  })
}

function renderGroupActive(row: any) {
  const setting = networkSettings.value.find(item => item.network_code === row.network_code)
  const active = Number(setting?.active_group_id || 0) === Number(row.id)
  return h(NTag, { type: active ? 'success' : 'default', bordered: false }, { default: () => active ? '当前' : '备用' })
}

function groupColor(groupId: number) {
  if (groupId <= 0) {
    return groupPalette[groupPalette.length - 1]
  }
  return groupPalette[groupId % groupPalette.length]
}

function groupOptionsForNetwork(networkCode: string) {
  return groups.value
    .filter(group => group.network_code === networkCode)
    .map(group => ({ label: group.name, value: Number(group.id) }))
}

function openGroupManager() {
  groupManagerShow.value = true
}

function openNetworkManager() {
  networkManagerShow.value = true
}

function openNetworkSetting(row: any) {
  Object.assign(networkForm, {
    network_code: row.network_code,
    contract_address: row.contract_address || '',
    usdt_contract_address: row.usdt_contract_address || '',
    monitor_interval_seconds: Number(row.monitor_interval_seconds || 10),
    confirm_blocks: Number(row.confirm_blocks || 12),
    scan_step_blocks: Number(row.scan_step_blocks || 500),
    enabled: !!row.enabled
  })
  networkSettingShow.value = true
}

function search() {
  Object.assign(appliedFilters, filters)
}

function resetSearch() {
  Object.assign(filters, { network_code: null, group_id: null, provider: null })
  Object.assign(appliedFilters, { network_code: null, group_id: null, provider: null })
}

function createGroup() {
  const networkCode = filters.network_code || networkOptions.value[0]?.value || ''
  Object.assign(groupForm, {
    id: null,
    network_code: networkCode,
    name: '',
    rotation_mode: 'random',
    single_attempts: 2,
    max_nodes: 3,
    set_active: true
  })
  groupShow.value = true
}

function editGroup(row: any) {
  Object.assign(groupForm, row, {
    single_attempts: Number(row.single_attempts || 2),
    max_nodes: Number(row.max_nodes || 3),
    set_active: Number(networkSettings.value.find(item => item.network_code === row.network_code)?.active_group_id || 0) === Number(row.id)
  })
  groupShow.value = true
}

function providerDefaultUrl(provider: string, networkCode: string) {
  return providerRpcTemplates[provider]?.[networkCode] || ''
}

function onNodeNetworkChange(networkCode: string) {
  nodeForm.network_code = networkCode
  const options = groupOptionsForNetwork(networkCode)
  if (!options.some(option => option.value === nodeForm.group_id)) {
    nodeForm.group_id = options[0]?.value || null
  }
  fillDefaultRpcUrl()
}

function onProviderChange(provider: string) {
  nodeForm.provider = provider
  if (provider !== 'infura') {
    nodeForm.use_api_key_secret = false
    nodeForm.api_key_secret = ''
  }
  fillDefaultRpcUrl()
}

function fillDefaultRpcUrl() {
  const url = providerDefaultUrl(nodeForm.provider, nodeForm.network_code)
  if (url) {
    nodeForm.rpc_url = url
  }
}

function createNode() {
  const networkCode = filters.network_code || networkOptions.value[0]?.value || ''
  const provider = 'infura'
  Object.assign(nodeForm, {
    id: null,
    network_code: networkCode,
    group_id: groupOptionsForNetwork(networkCode)[0]?.value || null,
    name: '',
    provider,
    rpc_url: providerDefaultUrl(provider, networkCode),
    api_key: '',
    use_api_key_secret: false,
    api_key_secret: '',
    proxy_id: 0,
    enabled: true,
    sort_order: 0
  })
  nodeShow.value = true
}

function editNode(row: any) {
  const provider = row.provider || 'infura'
  Object.assign(nodeForm, row, {
    group_id: Number(row.group_id || 0) || null,
    api_key: '',
    api_key_secret: '',
    enabled: !!row.enabled,
    provider,
    use_api_key_secret: provider === 'infura' && !!row.use_api_key_secret,
    proxy_id: Number(row.proxy_id || 0),
    sort_order: Number(row.sort_order || 0)
  })
  nodeShow.value = true
}

async function loadAll() {
  loading.value = true
  try {
    const [rpcData, proxyRows] = await Promise.all([
      api.get<any>('/admin/rpc-config/list'),
      api.get<any[]>('/admin/proxy/enabled')
    ])
    networkSettings.value = rpcData.network_settings || []
    groups.value = rpcData.groups || []
    nodes.value = rpcData.nodes || []
    networkOptions.value = rpcData.networks || []
    providerOptions.value = rpcData.providers || providerLinks.map(item => ({ label: item.label, value: item.value }))
    proxies.value = proxyRows
  } catch (e: any) {
    message.error(e.message)
  } finally {
    loading.value = false
  }
}

function networkPayload(row: any, enabled = !!row.enabled) {
  return {
    network_code: row.network_code,
    contract_address: row.contract_address || '',
    usdt_contract_address: row.usdt_contract_address || '',
    monitor_interval_seconds: Number(row.monitor_interval_seconds || 10),
    confirm_blocks: Number(row.confirm_blocks || 12),
    scan_step_blocks: Number(row.scan_step_blocks || 500),
    enabled
  }
}

async function toggleNetworkMonitor(row: any, enabled: boolean) {
  networkSaving.value = true
  try {
    await api.post('/admin/rpc-config/network/save', networkPayload(row, enabled))
    message.success(enabled ? '自动监听已开启' : '自动监听已关闭')
    await loadAll()
  } catch (e: any) {
    message.error(e.message)
  } finally {
    networkSaving.value = false
  }
}

async function saveNetworkSetting() {
  networkSaving.value = true
  try {
    await api.post('/admin/rpc-config/network/save', networkPayload(networkForm, !!networkForm.enabled))
    message.success('网络监听设置已保存')
    networkSettingShow.value = false
    await loadAll()
  } catch (e: any) {
    message.error(e.message)
  } finally {
    networkSaving.value = false
  }
}

async function saveGroup() {
  saving.value = true
  try {
    await api.post('/admin/rpc-config/group/save', groupForm)
    message.success('RPC 分组已保存')
    groupShow.value = false
    await loadAll()
  } catch (e: any) {
    message.error(e.message)
  } finally {
    saving.value = false
  }
}

async function saveNode() {
  if (!nodeForm.group_id) {
    message.error('请选择 RPC 分组')
    return
  }
  if (nodeForm.provider !== 'infura') {
    nodeForm.use_api_key_secret = false
    nodeForm.api_key_secret = ''
  }
  saving.value = true
  try {
    await api.post('/admin/rpc-config/node/save', nodeForm)
    message.success('RPC 节点已保存')
    nodeShow.value = false
    await loadAll()
  } catch (e: any) {
    message.error(e.message)
  } finally {
    saving.value = false
  }
}

async function toggleNode(row: any) {
  try {
    await api.post('/admin/rpc-config/node/toggle', { id: row.id, enabled: !row.enabled })
    message.success(row.enabled ? 'RPC 节点已禁用' : 'RPC 节点已启用')
    await loadAll()
  } catch (e: any) {
    message.error(e.message)
  }
}

function confirmDeleteNode(row: any) {
  const name = row.name || row.rpc_url || `#${row.id}`
  dialog.warning({
    title: '删除 RPC 节点',
    content: `确定要删除 RPC 节点「${name}」吗？删除后该节点不会再参与请求调度。`,
    positiveText: '确认删除',
    negativeText: '取消',
    onPositiveClick: () => deleteNode(row.id)
  })
}

async function deleteNode(id: number) {
  try {
    await api.post('/admin/rpc-config/node/delete', { id })
    message.success('RPC 节点已删除')
    await loadAll()
  } catch (e: any) {
    message.error(e.message)
  }
}

async function testGroup(id: number) {
  await runTest('/admin/rpc-config/test-group', { id }, '读取 RPC 分组配置')
}

async function testNode(id: number) {
  await runTest('/admin/rpc-config/test-node', { id }, '读取 RPC 节点配置')
}

async function runTest(url: string, payload: any, title: string) {
  testShow.value = true
  testing.value = true
  testResult.value = {}
  testSteps.value = [
    { title, status: 'process', message: '准备测试' },
    { title: '请求 RPC 节点', status: 'wait', message: '等待执行' }
  ]
  try {
    const data: any = await api.post(url, payload)
    testResult.value = data
    testSteps.value = data.steps || []
    if (data.ok) {
      message.success(`RPC 测试成功，当前区块=${data.block_number}`)
    } else {
      message.error('RPC 测试失败')
    }
  } catch (e: any) {
    testResult.value = { ok: false, error: e.message }
    testSteps.value = [{ title: 'RPC 测试失败', status: 'error', message: e.message }]
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

watch(() => nodeForm.network_code, (networkCode) => {
  if (!nodeShow.value) return
  const options = groupOptionsForNetwork(networkCode)
  if (!options.some(option => option.value === nodeForm.group_id)) {
    nodeForm.group_id = options[0]?.value || null
  }
})

watch(() => filters.network_code, (networkCode) => {
  if (!networkCode) return
  if (filters.group_id && !groups.value.some(group => group.network_code === networkCode && Number(group.id) === Number(filters.group_id))) {
    filters.group_id = null
  }
})

onMounted(loadAll)
</script>
