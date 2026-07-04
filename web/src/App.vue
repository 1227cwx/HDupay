<template>
  <n-config-provider :locale="zhCN" :date-locale="dateZhCN">
    <n-message-provider>
      <n-dialog-provider>
        <router-view v-if="isPublicPage" />
        <n-layout v-else has-sider embedded :style="rootLayoutStyle" :content-style="rootContentStyle">
        <n-layout-sider
          v-if="!isCompact"
          bordered
          :width="navigationWidth"
          collapse-mode="width"
          :style="siderStyle"
          :content-style="siderContentStyle"
        >
          <n-space vertical :size="0">
            <n-space justify="center" align="center" :style="siderTitleStyle">
              <n-gradient-text type="info" :size="24">U-PAY</n-gradient-text>
            </n-space>

            <n-space vertical :size="0" :style="siderMenuStyle">
              <n-menu
                :options="menuOptions"
                :value="activeKey"
                :indent="18"
                :expanded-keys="expandedKeys"
                accordion
                @update:value="go"
                @update:expanded-keys="handleExpandedKeys"
              />
            </n-space>
          </n-space>
        </n-layout-sider>

        <n-drawer v-model:show="drawerShow" placement="left" :width="navigationWidth">
          <n-drawer-content closable body-content-style="padding: 12px 8px;">
            <template #header>
              <n-space justify="center" :style="{ width: '100%' }">
                <n-gradient-text type="info" :size="22">U-PAY</n-gradient-text>
              </n-space>
            </template>
            <n-menu
              :options="menuOptions"
              :value="activeKey"
              :indent="18"
              :expanded-keys="expandedKeys"
              accordion
              @update:value="go"
              @update:expanded-keys="handleExpandedKeys"
            />
          </n-drawer-content>
        </n-drawer>

        <n-layout embedded :style="mainLayoutStyle" :content-style="mainLayoutContentStyle">
          <n-layout-header bordered :style="headerStyle">
            <n-space justify="space-between" align="center" :wrap="false" :style="{ width: '100%' }">
              <n-space align="center" :wrap="false" size="medium">
                <n-button v-if="isCompact" circle quaternary size="large" aria-label="打开导航" @click="drawerShow = true">
                  <template #icon>
                    <n-icon size="24"><MenuOutline /></n-icon>
                  </template>
                </n-button>
                <n-text strong :style="{ fontSize: isCompact ? '18px' : '20px' }">{{ pageTitle }}</n-text>
              </n-space>

              <n-dropdown :options="userOptions" @select="handleUserAction">
                <n-button quaternary size="small">
                  <template #icon>
                    <n-icon><PersonCircleOutline /></n-icon>
                  </template>
                  {{ adminLabel }}
                </n-button>
              </n-dropdown>
            </n-space>
          </n-layout-header>

          <n-layout-content :style="contentLayoutStyle" :content-style="contentStyle">
            <router-view />
          </n-layout-content>
        </n-layout>
        </n-layout>
      </n-dialog-provider>
    </n-message-provider>
  </n-config-provider>
</template>

<script setup lang="ts">
import { computed, h, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { NIcon, dateZhCN, zhCN } from 'naive-ui'
import {
  AddCircleOutline,
  AlbumsOutline,
  GitNetworkOutline,
  KeyOutline,
  LogOutOutline,
  MenuOutline,
  PersonCircleOutline,
  ReceiptOutline,
  RepeatOutline,
  ServerOutline,
  SettingsOutline,
  PaperPlaneOutline,
  SpeedometerOutline,
  WalletOutline
} from '@vicons/ionicons5'
import { api } from './api'

const route = useRoute()
const router = useRouter()
const drawerShow = ref(false)
const expandedKeys = ref<string[]>([])
const windowWidth = ref(typeof window === 'undefined' ? 1200 : window.innerWidth)
const currentAdmin = ref<any>({})
const adminPrefix = '/cwxdqz'
const adminPath = (path: string) => `${adminPrefix}${path}`

function renderIcon(icon: any, color: string) {
  return () => h(NIcon, { size: 18, color }, { default: () => h(icon) })
}

const menuItems = [
  { label: '概览', key: adminPath('/overview'), icon: renderIcon(SpeedometerOutline, '#2563eb') },
  { label: '创建收款', key: adminPath('/deposit-create'), icon: renderIcon(AddCircleOutline, '#0891b2') },
  { label: '交易订单', key: adminPath('/orders'), icon: renderIcon(ReceiptOutline, '#2563eb') },
  {
    label: '公链/网络',
    key: 'chain-network',
    icon: renderIcon(GitNetworkOutline, '#0f766e'),
    children: [
      { label: 'RPC 节点', key: adminPath('/rpc'), icon: renderIcon(ServerOutline, '#7c3aed') },
      { label: '网络配置', key: adminPath('/network-settings'), icon: renderIcon(GitNetworkOutline, '#0f766e') },
      { label: '地址池', key: adminPath('/addresses'), icon: renderIcon(AlbumsOutline, '#f59e0b') },
      { label: '代理池', key: adminPath('/proxies'), icon: renderIcon(GitNetworkOutline, '#0f766e') }
    ]
  },
  {
    label: '钱包管理',
    key: 'wallet-group',
    icon: renderIcon(WalletOutline, '#059669'),
    children: [
      { label: '钱包设置', key: adminPath('/wallet-settings'), icon: renderIcon(WalletOutline, '#059669') },
      { label: '归集钱包', key: adminPath('/collection-wallets'), icon: renderIcon(RepeatOutline, '#16a34a') },
      { label: 'Gas 钱包', key: adminPath('/gas-wallets'), icon: renderIcon(ServerOutline, '#f59e0b') },
      { label: '归集记录', key: adminPath('/collections'), icon: renderIcon(RepeatOutline, '#16a34a') }
    ]
  },
  {
    label: '转出配置',
    key: 'withdraw-group',
    icon: renderIcon(PaperPlaneOutline, '#ea580c'),
    children: [
      { label: '转出设置', key: adminPath('/withdraw-settings'), icon: renderIcon(SettingsOutline, '#64748b') },
      { label: '转出记录', key: adminPath('/withdrawals'), icon: renderIcon(PaperPlaneOutline, '#ea580c') }
    ]
  },
  {
    label: '系统设置',
    key: 'system-group',
    icon: renderIcon(SettingsOutline, '#64748b'),
    children: [
      { label: '系统设置', key: adminPath('/admin-profile'), icon: renderIcon(PersonCircleOutline, '#2563eb') },
      { label: 'API 设置', key: adminPath('/open-api'), icon: renderIcon(KeyOutline, '#9333ea') },
      { label: '汇率设置', key: adminPath('/fiat-rates'), icon: renderIcon(SettingsOutline, '#64748b') }
    ]
  }
]
const menuOptions = menuItems

const userOptions = [
  { label: '系统设置', key: 'settings', icon: renderIcon(SettingsOutline, '#64748b') },
  { label: '退出登录', key: 'logout', icon: renderIcon(LogOutOutline, '#dc2626') }
]

const activeKey = computed(() => route.path)
const isPublicPage = computed(() => route.meta.public === true)
const isCompact = computed(() => windowWidth.value <= 900)
const flatMenuItems = computed(() => flattenMenu(menuItems))
const navigationWidth = computed(() => Math.ceil(Math.min(260, Math.max(190, Math.max(...flatMenuItems.value.map(item => labelWidth(item.label))) * 16 + 112))))
const headerHeight = computed(() => isCompact.value ? 48 : 50)
const rootLayoutStyle = computed(() => ({
  position: 'fixed',
  inset: '0',
  width: '100vw',
  height: '100vh',
  overflow: 'hidden'
}))
const rootContentStyle = computed(() => ({
  height: '100vh',
  minHeight: '100vh',
  background: '#f5f7fb',
  overflow: 'hidden'
}))
const siderStyle = computed(() => ({
  height: '100vh',
  flexShrink: 0
}))
const siderContentStyle = computed(() => ({
  height: '100vh',
  padding: '0',
  background: '#ffffff',
  overflow: 'hidden'
}))
const siderTitleStyle = computed(() => ({
  width: '100%',
  height: `${headerHeight.value}px`,
  boxSizing: 'border-box',
  borderBottom: '1px solid #efeff5'
}))
const siderMenuStyle = computed(() => ({
  padding: '10px'
}))
const contentPadding = computed(() => {
  if (isCompact.value) {
    return 12
  }
  return route.path.endsWith('/overview') || route.path.endsWith('/deposit-create') ? 22 : 18
})
const headerStyle = computed(() => ({
  flexShrink: 0,
  minHeight: `${headerHeight.value}px`,
  padding: isCompact.value ? '4px 12px' : '4px 18px',
  display: 'flex',
  alignItems: 'center',
  background: 'rgba(255, 255, 255, 0.92)',
  backdropFilter: 'blur(12px)'
}))
const mainLayoutStyle = computed(() => ({
  height: '100vh',
  overflow: 'hidden'
}))
const mainLayoutContentStyle = computed(() => ({
  height: '100vh',
  background: '#f5f7fb',
  display: 'flex',
  flexDirection: 'column',
  overflow: 'hidden'
}))
const contentLayoutStyle = computed(() => ({
  height: `calc(100vh - ${headerHeight.value}px)`,
  overflow: 'hidden'
}))
const contentStyle = computed(() => [
  `height: calc(100vh - ${headerHeight.value}px)`,
  `padding: ${contentPadding.value}px`,
  'box-sizing: border-box',
  'overflow-y: auto',
  'overflow-x: auto'
].join('; '))
const adminLabel = computed(() => currentAdmin.value.nickname || currentAdmin.value.username || '管理员')
const titles: Record<string, string> = {
  [adminPath('/overview')]: '概览',
  [adminPath('/dashboard')]: '概览',
  [adminPath('/rpc')]: 'RPC 节点',
  [adminPath('/network-settings')]: '网络配置',
  [adminPath('/proxies')]: '代理池',
  [adminPath('/wallet-settings')]: '钱包设置',
  [adminPath('/wallet')]: '钱包设置',
  [adminPath('/collection-wallets')]: '归集钱包',
  [adminPath('/gas-wallets')]: 'Gas 钱包',
  [adminPath('/deposit-create')]: '创建收款',
  [adminPath('/open-api')]: 'API 设置',
  [adminPath('/addresses')]: '地址池',
  [adminPath('/orders')]: '交易订单',
  [adminPath('/collections')]: '归集记录',
  [adminPath('/withdraw-settings')]: '转出设置',
  [adminPath('/withdrawals')]: '转出记录',
  [adminPath('/admin-profile')]: '系统设置',
  [adminPath('/fiat-rates')]: '汇率设置',
  [adminPath('/admin-settings')]: '系统设置'
}
const pageTitle = computed(() => titles[route.path] || '页面')

function labelWidth(label: string): number {
  return Array.from(label).reduce((total, char) => total + (char.charCodeAt(0) < 128 ? 0.6 : 1), 0)
}

function flattenMenu(items: any[]): any[] {
  return items.flatMap(item => [item, ...flattenMenu(item.children || [])])
}

function parentKeyForPath(path: string, items: any[], parentKey = ''): string {
  for (const item of items) {
    if (item.key === path) {
      return parentKey
    }
    if (item.children?.length) {
      const matched = parentKeyForPath(path, item.children, item.key)
      if (matched) {
        return matched
      }
    }
  }
  return ''
}

function syncExpandedMenuByPath(path: string) {
  const parentKey = parentKeyForPath(path, menuItems)
  expandedKeys.value = parentKey ? [parentKey] : []
}

function handleExpandedKeys(keys: Array<string | number>) {
  const normalized = keys.map(key => String(key))
  expandedKeys.value = normalized.length ? [normalized[normalized.length - 1]] : []
}

function refreshWidth() {
  windowWidth.value = window.innerWidth
}

function handleAdminProfileUpdated() {
  loadCurrentAdmin()
}

async function loadCurrentAdmin() {
  if (isPublicPage.value) {
    return
  }
  try {
    currentAdmin.value = await api.get('/admin/auth/me')
  } catch {
    currentAdmin.value = {}
  }
}

function go(key: string) {
  drawerShow.value = false
  router.push(key)
}

async function handleUserAction(key: string) {
  if (key === 'settings') {
    await router.push(adminPath('/admin-profile'))
    return
  }
  if (key === 'logout') {
    await logout()
  }
}

async function logout() {
  try {
    await api.post('/admin/auth/logout')
  } finally {
    currentAdmin.value = {}
    drawerShow.value = false
    await router.push(adminPath('/login'))
  }
}

onMounted(() => {
  window.addEventListener('resize', refreshWidth)
  window.addEventListener('admin-profile-updated', handleAdminProfileUpdated)
})
onBeforeUnmount(() => {
  window.removeEventListener('resize', refreshWidth)
  window.removeEventListener('admin-profile-updated', handleAdminProfileUpdated)
})
watch(() => route.path, (path) => {
  syncExpandedMenuByPath(path)
  loadCurrentAdmin()
}, { immediate: true })
</script>
