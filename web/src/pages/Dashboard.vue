<template>
  <n-space vertical size="large">
    <n-grid cols="1 m:3" responsive="screen" :x-gap="18" :y-gap="18">
      <n-gi>
        <n-card :bordered="false" embedded>
          <n-thing>
            <template #avatar>
              <n-avatar round color="#2563eb" :size="48"><n-icon size="26"><CloudDoneOutline /></n-icon></n-avatar>
            </template>
            <template #header>监听状态</template>
            <n-space size="small">
              <n-tag type="success" :bordered="false">Ethereum</n-tag>
              <n-tag type="info" :bordered="false">Base</n-tag>
              <n-tag type="warning" :bordered="false">Celo</n-tag>
              <n-tag type="error" :bordered="false">Polygon</n-tag>
            </n-space>
          </n-thing>
        </n-card>
      </n-gi>
      <n-gi>
        <n-card :bordered="false" embedded>
          <n-thing>
            <template #avatar>
              <n-avatar round color="#16a34a" :size="48"><n-icon size="26"><ShieldCheckmarkOutline /></n-icon></n-avatar>
            </template>
            <template #header>钱包模式</template>
            <n-tag type="success" :bordered="false">HD 托管</n-tag>
          </n-thing>
        </n-card>
      </n-gi>
      <n-gi>
        <n-card :bordered="false" embedded>
          <n-thing>
            <template #avatar>
              <n-avatar round color="#f59e0b" :size="48"><n-icon size="26"><FlashOutline /></n-icon></n-avatar>
            </template>
            <template #header>自动归集</template>
            <n-tag type="warning" :bordered="false">需保证 Gas 钱包余额</n-tag>
          </n-thing>
        </n-card>
      </n-gi>
    </n-grid>

    <n-grid cols="1 s:2 m:3" :x-gap="16" :y-gap="16" responsive="screen">
      <n-gi v-for="item in metrics" :key="item.label">
        <n-card :bordered="false" content-style="padding: 18px;">
          <n-thing>
            <template #avatar>
              <n-avatar round :color="item.color" :size="44">
                <n-icon size="24"><component :is="item.icon" /></n-icon>
              </n-avatar>
            </template>
            <template #header>
              <n-text depth="3">{{ item.label }}</n-text>
            </template>
            <n-statistic :value="item.value" />
          </n-thing>
        </n-card>
      </n-gi>
    </n-grid>

    <n-space justify="end">
      <n-button type="primary" :loading="loading" @click="load">
        <template #icon><n-icon><RefreshOutline /></n-icon></template>
        刷新数据
      </n-button>
    </n-space>
  </n-space>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useMessage } from 'naive-ui'
import {
  AlbumsOutline,
  CloudDoneOutline,
  FlashOutline,
  ReceiptOutline,
  RefreshOutline,
  ShieldCheckmarkOutline,
  WalletOutline
} from '@vicons/ionicons5'
import { api } from '../api'

const message = useMessage()
const loading = ref(false)
const summary = ref<Record<string, number>>({})
const metrics = computed(() => [
  { label: '启用 RPC', value: summary.value.rpc_enabled || 0, color: '#2563eb', icon: CloudDoneOutline },
  { label: '交易订单', value: summary.value.orders || 0, color: '#7c3aed', icon: ReceiptOutline },
  { label: '已确认订单', value: summary.value.confirmed_orders || 0, color: '#16a34a', icon: ShieldCheckmarkOutline },
  { label: '地址池数量', value: summary.value.addresses || 0, color: '#0891b2', icon: AlbumsOutline },
  { label: '归集任务', value: summary.value.collection_tasks || 0, color: '#f59e0b', icon: WalletOutline }
])

async function load() {
  loading.value = true
  try {
    summary.value = await api.get('/admin/dashboard/summary')
  } catch (e: any) {
    message.error(e.message)
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>
