<template>
  <n-layout embedded>
    <n-layout-content :content-style="pageContentStyle">
      <n-space justify="center">
        <n-space vertical size="large" :style="pageWrapStyle">
          <n-card v-if="pageInitializing" :bordered="false">
            <n-space vertical align="center" justify="center" :style="loadingPanelStyle">
              <n-spin size="large" />
              <n-text depth="3">{{ text.orderLoading }}</n-text>
            </n-space>
          </n-card>

          <n-card v-else-if="showForm" :title="text.stablePay" :bordered="false">
            <n-form :model="form" label-placement="top">
              <n-alert v-if="isEpayCheckout" type="info" :bordered="false" :style="{ marginBottom: '16px' }">
                <n-text strong>{{ epayInfo.name || '-' }}</n-text>
              </n-alert>
              <n-grid cols="1 m:2" responsive="screen" :x-gap="14">
                <n-gi>
                  <n-form-item :label="text.network">
                    <n-select v-model:value="form.network" :options="networkOptions" :placeholder="text.selectNetwork" :render-label="renderNetworkSelectLabel" :render-tag="renderNetworkSelectTag" />
                  </n-form-item>
                </n-gi>
                <n-gi>
                  <n-form-item :label="text.token">
                    <n-select v-model:value="form.token" :options="tokenOptions" :placeholder="text.selectToken" :render-label="renderTokenSelectLabel" :render-tag="renderTokenSelectTag" />
                  </n-form-item>
                </n-gi>
              </n-grid>
              <n-grid v-if="!isEpayCheckout" cols="1 m:2" responsive="screen" :x-gap="14">
                <n-gi>
                  <n-form-item :label="text.fiatCurrency">
                    <n-select v-model:value="form.fiat_currency" :options="fiatOptions" :placeholder="text.selectFiat" />
                  </n-form-item>
                </n-gi>
                <n-gi>
                  <n-form-item :label="text.fiatAmount">
                    <n-input v-model:value="form.fiat_amount" :placeholder="text.inputFiatAmount" @keydown.enter="create">
                      <template #suffix>{{ fiatSymbolText }}</template>
                    </n-input>
                  </n-form-item>
                </n-gi>
              </n-grid>
              <n-form-item>
                <n-button type="primary" size="large" block :loading="creating" :disabled="networkOptions.length === 0" @click="create">
                  <template #icon><n-icon><ReceiptOutline /></n-icon></template>
                  {{ text.pay }}
                </n-button>
              </n-form-item>
            </n-form>
          </n-card>

          <n-card v-else-if="loadingOrder" :bordered="false">
            <n-space vertical align="center" justify="center" :style="loadingPanelStyle">
              <n-spin size="large" />
              <n-text depth="3">{{ text.orderLoading }}</n-text>
            </n-space>
          </n-card>

          <n-card v-else-if="accessError" :bordered="false">
            <n-result status="warning" :title="accessError" :description="text.accessDeniedDesc">
              <template #footer>
                <n-button type="primary" @click="backToPay">{{ text.backToPay }}</n-button>
              </template>
            </n-result>
          </n-card>

          <n-card v-else-if="isSuccess" :bordered="false">
            <n-space vertical align="center" size="large" :style="successPanelStyle">
              <n-icon :size="92" color="#16a34a" :style="successIconStyle">
                <CheckmarkCircleOutline />
              </n-icon>
              <n-space vertical align="center" size="small">
                <n-text strong :style="successTitleStyle">{{ text.tradeSuccess }}</n-text>
                <n-text depth="3">{{ successRedirectText }}</n-text>
              </n-space>
              <n-button secondary type="primary" @click="redirectAfterSuccess">{{ redirectButtonText }}</n-button>
            </n-space>
          </n-card>

          <n-card v-else :title="text.paymentInfo" :bordered="false" :content-style="paymentCardContentStyle">
            <template #header-extra>
              <n-tag :type="countdownType" round size="large" :style="countdownTagStyle">{{ countdownText }}</n-tag>
            </template>

            <n-space vertical size="large" :style="paymentContentStyle">
              <n-grid cols="1 m:2" responsive="screen" :x-gap="18" :y-gap="12">
                <n-gi>
                  <n-flex align="center" :wrap="false" :size="8" :style="compactLineStyle">
                    <n-text depth="3">{{ text.orderNo }}</n-text>
                    <n-text strong>{{ payment.order_no }}</n-text>
                  </n-flex>
                </n-gi>
                <n-gi>
                  <n-flex align="center" :wrap="false" :size="8" :style="compactLineStyle">
                    <n-text depth="3">{{ text.productName }}</n-text>
                    <n-text strong>{{ paymentProductName }}</n-text>
                  </n-flex>
                </n-gi>
                <n-gi>
                  <n-flex align="center" :wrap="false" :size="8" :style="compactLineStyle">
                    <n-text depth="3">{{ text.network }}</n-text>
                    <NetworkTag :code="payment.network_id || payment.network" :label="transferNetworkName" />
                  </n-flex>
                </n-gi>
                <n-gi>
                  <n-flex align="center" :wrap="false" :size="8" :style="compactLineStyle">
                    <n-text depth="3">{{ text.payAmount }}</n-text>
                    <n-text strong :style="amountStyle"><TokenAmount :amount="payment.token_amount" :code="payment.token" /></n-text>
                  </n-flex>
                </n-gi>
              </n-grid>

              <n-space vertical size="small" :style="addressLineStyle">
                <n-space justify="space-between" align="center">
                  <n-text depth="3">{{ text.receiveAddress }}</n-text>
                  <n-button text type="primary" @click="copyAddress">{{ text.copy }}</n-button>
                </n-space>
                <n-text code :style="addressTextStyle">{{ payment.address }}</n-text>
              </n-space>

              <n-space justify="center">
                <n-qr-code :value="payment.address" :size="240" />
              </n-space>

              <n-alert type="warning" :bordered="false" :style="transferNoticeStyle">
                <n-space vertical size="small">
                  <n-text strong>{{ text.transferNoticeTitle }}</n-text>
                  <n-text>{{ text.transferNoticeNetworkPrefix }} {{ transferNetworkName }} {{ text.transferNoticeNetworkSuffix }}</n-text>
                  <n-text>{{ text.transferNoticeAuto }}</n-text>
                  <n-text>{{ text.transferNoticeAmount }}</n-text>
                  <n-text>{{ text.transferNoticeHelp }}</n-text>
                </n-space>
              </n-alert>
            </n-space>

            <n-space v-if="isConfirming" vertical align="center" justify="center" :style="confirmOverlayStyle">
              <n-space vertical align="center" size="medium" :style="confirmPanelStyle">
                <n-text strong :style="confirmTitleStyle">{{ text.confirming }}</n-text>
                <n-text depth="3">{{ text.pleaseWait }}</n-text>
                <n-progress
                  type="circle"
                  :percentage="visibleProgress"
                  :color="progressCircleColor"
                  :rail-color="progressRailColor"
                  :stroke-width="10"
                  :show-indicator="true"
                  :style="progressCircleStyle"
                />
              </n-space>
            </n-space>
          </n-card>
        </n-space>
      </n-space>
    </n-layout-content>
  </n-layout>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useMessage } from 'naive-ui'
import { CheckmarkCircleOutline, ReceiptOutline } from '@vicons/ionicons5'
import { api } from '../api'
import { NetworkTag, networkLabel, renderNetworkSelectLabel, renderNetworkSelectTag } from '../utils/networks'
import { fallbackFiatOptions, fallbackTokenOptions, fiatSymbol, renderTokenSelectLabel, renderTokenSelectTag, TokenAmount } from '../utils/money'

const text = {
  stablePay: 'U-PAY',
  network: '\u7f51\u7edc',
  token: '\u8d27\u5e01',
  fiatCurrency: '\u5e01\u79cd',
  fiatAmount: '\u91d1\u989d',
  selectNetwork: '\u8bf7\u9009\u62e9\u7f51\u7edc',
  selectToken: '\u8bf7\u9009\u62e9\u52a0\u5bc6\u8d27\u5e01',
  selectFiat: '\u8bf7\u9009\u62e9\u5e01\u79cd',
  inputFiatAmount: '\u8bf7\u8f93\u5165\u91d1\u989d',
  pay: '\u652f\u4ed8',
  paymentInfo: '\u652f\u4ed8\u4fe1\u606f',
  orderNo: '\u8ba2\u5355\u53f7',
  payAmount: '\u652f\u4ed8\u6570\u91cf',
  productName: '\u5546\u54c1\u540d\u79f0',
  receiveAddress: '\u4ed8\u6b3e\u5730\u5740',
  remaining: '\u5230\u671f\u5012\u8ba1\u65f6',
  copy: '\u590d\u5236',
  copied: '\u6536\u6b3e\u5730\u5740\u5df2\u590d\u5236',
  created: '\u8ba2\u5355\u5df2\u521b\u5efa',
  confirming: '\u6b63\u5728\u786e\u8ba4\u4e2d',
  pleaseWait: '\u8bf7\u7a0d\u540e',
  tradeSuccess: '\u4ea4\u6613\u6210\u529f',
  redirectNow: '\u7acb\u5373\u8df3\u8f6c',
  closePage: '\u5173\u95ed\u9875\u9762',
  secondsRedirect: '\u79d2\u540e\u8df3\u8f6c',
  secondsRefresh: '\u79d2\u540e\u5237\u65b0\u9875\u9762',
  secondsClose: '\u79d2\u540e\u5173\u95ed\u9875\u9762',
  closePageTip: '\u652f\u4ed8\u6210\u529f\uff0c\u8bf7\u624b\u52a8\u5173\u95ed\u5f53\u524d\u9875\u9762',
  backToPay: '\u8fd4\u56de\u652f\u4ed8\u9875',
  accessDeniedDesc: '\u8bf7\u91cd\u65b0\u521b\u5efa\u8ba2\u5355\u6216\u8054\u7cfb\u5546\u6237\u786e\u8ba4\u8ba2\u5355\u72b6\u6001\u3002',
  expired: '\u5df2\u8fc7\u671f',
  loadFailed: '\u8ba2\u5355\u52a0\u8f7d\u5931\u8d25',
  orderLoading: '\u6b63\u5728\u52a0\u8f7d\u8ba2\u5355',
  transferNoticeTitle: '\ud83d\udccb \u8f6c\u8d26\u8bf4\u660e',
  transferNoticeNetworkPrefix: '\u5fc5\u987b\u4f7f\u7528',
  transferNoticeNetworkSuffix: '\u7f51\u7edc\u8fdb\u884c\u8f6c\u8d26\uff0c\u8bf7\u52ff\u8f6c\u9519\uff01',
  transferNoticeAuto: '\u8f6c\u8d26\u5b8c\u6210\u540e\u7cfb\u7edf\u4f1a\u81ea\u52a8\u786e\u8ba4\u5230\u8d26',
  transferNoticeAmount: '\u8f6c\u8d26\u91d1\u989d\u5fc5\u987b\u4e0e\u663e\u793a\u91d1\u989d\u5b8c\u5168\u4e00\u81f4',
  transferNoticeHelp: '\u5982\u679c\u6709\u5176\u5b83\u7591\u95ee\uff0c\u8bf7\u8054\u7cfb\u5ba2\u670d\u5904\u7406'
}

const message = useMessage()
const route = useRoute()
const router = useRouter()
const creating = ref(false)
const loadingOrder = ref(false)
const pageInitializing = ref(true)
const accessError = ref('')
const progress = ref(0)
const now = ref(Date.now())
const successRedirectSeconds = ref(0)
const successHandled = ref(false)
const closePageAttempted = ref(false)
const fallbackReturnUrl = ref('')
const form = reactive({ network: null as string | null, token: 'USDC', fiat_currency: 'CNY', fiat_amount: '' })
const payment = ref<any>({})
const epayInfo = ref<any>({})
const epayOrderNo = ref('')
const networkOptions = ref<any[]>([])
const fiatOptions = ref<any[]>(fallbackFiatOptions)
const tokenOptions = ref<any[]>(fallbackTokenOptions)
const refreshTimer = ref<number | null>(null)
const tickTimer = ref<number | null>(null)

const fiatSymbolText = computed(() => fiatSymbol(form.fiat_currency, fiatOptions.value))
const showForm = computed(() => !payment.value.order_no && !accessError.value && !loadingOrder.value)
const isEpayCheckout = computed(() => Boolean(epayInfo.value.epay_order_no))
const paymentProductName = computed(() => String(payment.value.product_name || epayInfo.value.name || '-'))
const transferNetworkName = computed(() => networkLabel(payment.value.network_id || payment.value.network, payment.value.network_id || payment.value.network || '-'))
const isConfirming = computed(() => payment.value.status === 'confirming')
const isSuccess = computed(() => payment.value.status === 'success')
const visibleProgress = computed(() => Math.min(99, Math.max(1, Number(progress.value || 0))))
const progressCircleColor = computed(() => {
  const percent = Math.min(1, Math.max(0, visibleProgress.value / 100))
  const hue = Math.round(48 + percent * 84)
  return `hsl(${hue}, 86%, 46%)`
})
const progressRailColor = 'rgba(148, 163, 184, 0.24)'
const expireTimestamp = computed(() => parseDateTime(payment.value.expire_at))
const remainingSeconds = computed(() => Math.max(0, Math.floor((expireTimestamp.value - now.value) / 1000)))
const countdownText = computed(() => payment.value.expire_at ? formatDuration(remainingSeconds.value) : '-')
const countdownType = computed(() => {
  if (remainingSeconds.value <= 0) return 'error'
  if (remainingSeconds.value <= 60) return 'warning'
  return 'success'
})
const shouldCloseAfterSuccess = computed(() => Boolean(payment.value.return_url_close_on_empty && !payment.value.return_url))
const redirectButtonText = computed(() => shouldCloseAfterSuccess.value ? text.closePage : text.redirectNow)
const successRedirectText = computed(() => {
  if (shouldCloseAfterSuccess.value && closePageAttempted.value) return text.closePageTip
  const suffix = payment.value.return_url
    ? text.secondsRedirect
    : (shouldCloseAfterSuccess.value ? text.secondsClose : text.secondsRefresh)
  return `${successRedirectSeconds.value || 5} ${suffix}`
})

const pageContentStyle = 'min-height: 100vh; padding: 28px 14px; background: #f5f7fb;'
const pageWrapStyle = { width: '860px', maxWidth: '100%' }
const paymentCardContentStyle = { position: 'relative', overflow: 'hidden', minHeight: '520px' }
const paymentContentStyle = computed(() => ({
  filter: isConfirming.value ? 'blur(5px)' : 'none',
  transition: 'filter 240ms ease'
}))
const compactLineStyle = { minHeight: '32px', minWidth: 0 }
const addressLineStyle = { paddingTop: '4px' }
const transferNoticeStyle = { background: '#fff7ed', color: '#92400e', borderRadius: '12px' }
const addressTextStyle = { display: 'block', whiteSpace: 'normal', wordBreak: 'break-all', fontSize: '14px' }
const amountStyle = { color: '#16a34a', fontSize: '20px' }
const countdownTagStyle = { fontSize: '18px', padding: '8px 14px', fontVariantNumeric: 'tabular-nums' }
const confirmOverlayStyle = {
  position: 'absolute',
  inset: '0',
  zIndex: 30,
  display: 'flex',
  alignItems: 'center',
  justifyContent: 'center',
  padding: '32px',
  background: 'rgba(255, 255, 255, 0.70)',
  backdropFilter: 'blur(6px)',
  pointerEvents: 'auto'
}
const confirmPanelStyle = {
  padding: '28px 30px',
  borderRadius: '24px',
  background: 'rgba(255, 255, 255, 0.88)',
  boxShadow: '0 22px 70px rgba(15, 23, 42, 0.18)',
  minWidth: '220px'
}
const confirmTitleStyle = { fontSize: '24px', color: '#2563eb' }
const progressCircleStyle = { width: '156px', height: '156px', transition: 'color 360ms ease' }
const loadingPanelStyle = { minHeight: '300px' }
const successPanelStyle = { minHeight: '460px', justifyContent: 'center' }
const successIconStyle = { transform: 'scale(1)', transition: 'transform 300ms ease' }
const successTitleStyle = { fontSize: '28px', color: '#16a34a' }

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
    const payload = {
      ...form,
      epay_order_no: epayOrderNo.value || undefined,
      fallback_return_url: epayOrderNo.value ? (fallbackReturnUrl.value || undefined) : undefined
    }
    const data: any = await api.post('/api/deposit/create', payload)
    setPayment(data)
    epayInfo.value = {}
    epayOrderNo.value = ''
    progress.value = 0
    closePageAttempted.value = false
    accessError.value = ''
    message.success(text.created)
    await router.replace({ path: '/pay', query: { order_no: data.order_no, order_token: data.order_token } })
    startPolling()
  } catch (e: any) {
    message.error(e.message)
  } finally {
    creating.value = false
  }
}

async function loadEasyPayOrder(orderNo: string) {
  captureFallbackReturnUrl()
  loadingOrder.value = true
  try {
    const data: any = await api.post('/api/easypay/detail', { epay_order_no: orderNo })
    epayInfo.value = data || {}
    epayOrderNo.value = data.epay_order_no || orderNo
    form.fiat_currency = data.fiat_currency || 'CNY'
    form.fiat_amount = data.money || ''
    accessError.value = ''
    if (data.deposit_order_no) {
      loadingOrder.value = false
      await loadPaymentOrder(String(data.deposit_order_no), false, String(data.deposit_order_token || ''))
    }
  } catch (e: any) {
    epayInfo.value = {}
    epayOrderNo.value = ''
    payment.value = {}
    accessError.value = e.message || text.loadFailed
    stopPolling()
  } finally {
    loadingOrder.value = false
  }
}

async function loadPaymentOrder(orderNo: string, allowTerminal = false, orderToken = '') {
  loadingOrder.value = true
  try {
    const data: any = await api.post('/api/deposit/status', { order_no: orderNo, order_token: orderToken || currentOrderToken(), allow_terminal: allowTerminal })
    setPayment(data)
    accessError.value = ''
    if (data.status === 'success') {
      handleSuccess()
    } else if (['waiting', 'confirming'].includes(data.status)) {
      startPolling()
    }
  } catch (e: any) {
    payment.value = {}
    accessError.value = e.message || text.loadFailed
    stopPolling()
  } finally {
    loadingOrder.value = false
  }
}

function startPolling() {
  stopPolling()
  refreshTimer.value = window.setInterval(loadStatus, 1000)
}

function stopPolling() {
  if (refreshTimer.value !== null) {
    window.clearInterval(refreshTimer.value)
    refreshTimer.value = null
  }
}

async function loadStatus() {
  if (!payment.value.order_no || successHandled.value) return
  try {
    const data: any = await api.post('/api/deposit/status', { order_no: payment.value.order_no, order_token: currentOrderToken(), allow_terminal: true })
    setPayment(data)
    if (data.status === 'success') {
      handleSuccess()
    }
    if (['expired', 'failed'].includes(data.status)) {
      stopPolling()
      accessError.value = data.status === 'expired' ? text.expired : text.loadFailed
      payment.value = {}
    }
  } catch {
    // Keep the current payment page while transient polling errors happen.
  }
}

function setPayment(data: any) {
  payment.value = data || {}
  progress.value = Number(data?.progress || 0)
}

function currentOrderToken() {
  return String(payment.value.order_token || queryValue(route.query.order_token) || '')
}

function queryValue(value: unknown) {
  return String(Array.isArray(value) ? (value[0] || '') : (value || ''))
}

async function handleSuccess() {
  if (successHandled.value) return
  successHandled.value = true
  closePageAttempted.value = false
  stopPolling()
  progress.value = 100
  successRedirectSeconds.value = 5
  if (route.path === '/pay' && route.query.order_no) {
    await router.replace('/pay')
  }
}

function redirectAfterSuccess() {
  if (payment.value.return_url) {
    if (payment.value.return_url_signed) {
      window.location.href = payment.value.return_url
      return
    }
    window.location.href = payment.value.return_url_append_status
      ? withReturnQuery(payment.value.return_url, payment.value.order_no)
      : payment.value.return_url
    return
  }
  if (shouldCloseAfterSuccess.value) {
    closePageAttempted.value = true
    window.close()
    return
  }
  window.location.href = '/pay'
}

function withReturnQuery(url: string, orderNo: string) {
  try {
    const target = new URL(url)
    target.searchParams.set('order_no', orderNo)
    target.searchParams.set('status', 'success')
    return target.toString()
  } catch {
    return url
  }
}

function captureFallbackReturnUrl() {
  if (fallbackReturnUrl.value) return
  const referrer = validExternalReferrer()
  if (referrer) fallbackReturnUrl.value = referrer
}

function validExternalReferrer() {
  const referrer = String(document.referrer || '').trim()
  if (!referrer) return ''
  try {
    const target = new URL(referrer)
    if (!['http:', 'https:'].includes(target.protocol)) return ''
    if (target.origin === window.location.origin) return ''
    return target.toString()
  } catch {
    return ''
  }
}

function copyAddress() {
  const address = String(payment.value.address || '')
  if (!address) return
  navigator.clipboard?.writeText(address)
  message.success(text.copied)
}

function backToPay() {
  payment.value = {}
  accessError.value = ''
  closePageAttempted.value = false
  router.replace('/pay')
}

function parseDateTime(value: string) {
  if (!value) return 0
  const time = new Date(String(value).replace(/-/g, '/')).getTime()
  return Number.isFinite(time) ? time : 0
}

function formatDuration(seconds: number) {
  if (seconds <= 0) return text.expired
  const h = Math.floor(seconds / 3600)
  const m = Math.floor((seconds % 3600) / 60)
  const s = seconds % 60
  const mm = String(m).padStart(2, '0')
  const ss = String(s).padStart(2, '0')
  return h > 0 ? `${String(h).padStart(2, '0')}:${mm}:${ss}` : `${mm}:${ss}`
}

onMounted(async () => {
  try {
    await loadInit()
    const orderNo = queryValue(route.query.order_no)
    const orderToken = queryValue(route.query.order_token)
    const easyPayOrderNo = queryValue(route.query.epay_order)
    if (orderNo) {
      await loadPaymentOrder(orderNo, false, orderToken)
    } else if (easyPayOrderNo) {
      await loadEasyPayOrder(easyPayOrderNo)
    }
  } finally {
    pageInitializing.value = false
  }
  tickTimer.value = window.setInterval(() => {
    now.value = Date.now()
    if (successHandled.value && successRedirectSeconds.value > 0) {
      successRedirectSeconds.value -= 1
      if (successRedirectSeconds.value <= 0) {
        redirectAfterSuccess()
      }
    }
  }, 1000)
})

onBeforeUnmount(() => {
  stopPolling()
  if (tickTimer.value !== null) {
    window.clearInterval(tickTimer.value)
    tickTimer.value = null
  }
})

watch(() => [route.query.order_no, route.query.order_token], ([orderValue, tokenValue]) => {
  if (successHandled.value) return
  const orderNo = queryValue(orderValue)
  const orderToken = queryValue(tokenValue)
  if (orderNo && (orderNo !== payment.value.order_no || orderToken !== currentOrderToken())) {
    loadPaymentOrder(orderNo, false, orderToken)
  }
})

watch(() => route.query.epay_order, (value) => {
  if (successHandled.value || route.query.order_no) return
  const orderNo = queryValue(value)
  if (orderNo && orderNo !== epayOrderNo.value) {
    loadEasyPayOrder(orderNo)
  }
})
</script>
