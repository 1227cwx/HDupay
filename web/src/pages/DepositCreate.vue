<template>
  <n-space justify="center" :style="{ width: '100%' }">
    <n-space vertical size="large" :style="{ width: '760px', maxWidth: '100%' }">
      <n-card :bordered="false">
        <n-thing>
          <template #avatar>
            <n-avatar round color="#2563eb" :size="46">
              <n-icon size="24"><AddCircleOutline /></n-icon>
            </n-avatar>
          </template>
          <template #header>创建收款订单</template>
        </n-thing>
      </n-card>

      <n-card title="订单信息" :bordered="false">
        <n-form :model="form" label-placement="top">
          <n-grid cols="1 m:2" responsive="screen" :x-gap="14" :y-gap="8">
            <n-gi>
              <n-form-item label="网络">
                <n-select v-model:value="form.network" :options="networkOptions" placeholder="请选择收款网络" :render-label="renderNetworkSelectLabel" :render-tag="renderNetworkSelectTag" />
              </n-form-item>
            </n-gi>
            <n-gi>
              <n-form-item label="货币">
                <n-select v-model:value="form.token" :options="tokenOptions" placeholder="请选择加密货币" :render-label="renderTokenSelectLabel" :render-tag="renderTokenSelectTag" />
              </n-form-item>
            </n-gi>
            <n-gi>
              <n-form-item label="法币币种">
                <n-select v-model:value="form.fiat_currency" :options="fiatOptions" placeholder="请选择法币币种" />
              </n-form-item>
            </n-gi>
            <n-gi>
              <n-form-item label="法币金额">
                <n-input v-model:value="form.fiat_amount" placeholder="请输入法币金额" @keydown.enter="create">
                  <template #suffix>{{ fiatSymbolText }}</template>
                </n-input>
              </n-form-item>
            </n-gi>
          </n-grid>
          <n-form-item>
            <n-button type="primary" size="large" block :loading="creating" :disabled="networkOptions.length === 0" @click="create">
              <template #icon><n-icon><ReceiptOutline /></n-icon></template>
              创建订单
            </n-button>
          </n-form-item>
        </n-form>
      </n-card>

      <n-card v-if="result.order_no" title="收款信息" :bordered="false">
        <n-space vertical size="large">
          <n-descriptions label-placement="left" bordered :column="1">
            <n-descriptions-item label="订单号">{{ result.order_no }}</n-descriptions-item>
            <n-descriptions-item label="网络">
              <NetworkTag :code="result.network" :label="networkLabel(result.network, result.network)" />
            </n-descriptions-item>
            <n-descriptions-item label="应付数量"><TokenAmount :amount="result.token_amount" :code="result.token" /></n-descriptions-item>
            <n-descriptions-item label="收款地址"><n-text code>{{ result.address }}</n-text></n-descriptions-item>
            <n-descriptions-item label="到期时间">{{ result.expire_at }}</n-descriptions-item>
          </n-descriptions>
          <n-space justify="center">
            <n-qr-code :value="result.address" :size="220" />
          </n-space>
        </n-space>
      </n-card>
    </n-space>
  </n-space>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useMessage } from 'naive-ui'
import { AddCircleOutline, ReceiptOutline } from '@vicons/ionicons5'
import { api } from '../api'
import { NetworkTag, networkLabel, renderNetworkSelectLabel, renderNetworkSelectTag } from '../utils/networks'
import { fallbackFiatOptions, fallbackTokenOptions, fiatSymbol, renderTokenSelectLabel, renderTokenSelectTag, TokenAmount } from '../utils/money'

const message = useMessage()
const creating = ref(false)
const form = reactive({ network: null as string | null, token: 'USDC', fiat_currency: 'CNY', fiat_amount: '' })
const result = ref<any>({})
const networkOptions = ref<any[]>([])
const fiatOptions = ref<any[]>(fallbackFiatOptions)
const tokenOptions = ref<any[]>(fallbackTokenOptions)
const fiatSymbolText = computed(() => fiatSymbol(form.fiat_currency, fiatOptions.value))

async function loadInit() {
  try {
    const [networks, options]: any[] = await Promise.all([
      api.get('/api/deposit/networks'),
      api.get('/api/deposit/options')
    ])
    networkOptions.value = networks
    fiatOptions.value = options.fiat_currencies || fallbackFiatOptions
    tokenOptions.value = options.tokens || fallbackTokenOptions
    if (!form.network && networkOptions.value.length > 0) form.network = networkOptions.value[0].value
    form.token = options.default_token || 'USDC'
    form.fiat_currency = options.default_fiat_currency || 'CNY'
  } catch (e: any) {
    message.error(e.message)
  }
}

async function create() {
  creating.value = true
  try {
    result.value = await api.post('/admin/deposit/create', form)
    message.success('订单已创建')
  } catch (e: any) {
    message.error(e.message)
  } finally {
    creating.value = false
  }
}

onMounted(loadInit)
</script>
