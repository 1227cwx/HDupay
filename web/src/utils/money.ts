import { defineComponent, h } from 'vue'
import { NFlex, NImage, NText } from 'naive-ui'
import usdcLogo from '../assets/tokens/usdc.svg'
import usdtLogo from '../assets/tokens/usdt.svg'
import { nonDraggableImageProps, nonDraggableImageWrapperStyle, preventImageDrag } from './iconImage'

export const fallbackFiatOptions = [
  { label: '人民币 CNY', value: 'CNY', symbol: '¥' },
  { label: '美元 USD', value: 'USD', symbol: '$' },
  { label: '欧元 EUR', value: 'EUR', symbol: '€' },
  { label: '加元 CAD', value: 'CAD', symbol: 'C$' },
  { label: '澳元 AUD', value: 'AUD', symbol: 'A$' },
  { label: '日元 JPY', value: 'JPY', symbol: '¥' },
  { label: '港币 HKD', value: 'HKD', symbol: 'HK$' },
  { label: '英镑 GBP', value: 'GBP', symbol: '£' },
  { label: '新加坡元 SGD', value: 'SGD', symbol: 'S$' }
]

export const fallbackTokenOptions = [
  { label: 'USDC', value: 'USDC' },
  { label: 'USDT', value: 'USDT' }
]

const tokenLogoMap: Record<string, string> = {
  USDC: usdcLogo,
  USDT: usdtLogo
}

export function normalizeTokenCode(tokenCode: string) {
  return String(tokenCode || '').toUpperCase()
}

export function tokenLogo(tokenCode: string) {
  return tokenLogoMap[normalizeTokenCode(tokenCode)] || ''
}

export function tokenTagType(tokenCode: string) {
  return normalizeTokenCode(tokenCode) === 'USDT' ? 'success' : 'info'
}

export function tokenColor(tokenCode: string) {
  return normalizeTokenCode(tokenCode) === 'USDT' ? '#19986F' : '#1296DB'
}

export function tokenLabel(tokenCode: string, fallback?: string) {
  const code = normalizeTokenCode(tokenCode)
  return fallbackTokenOptions.find(item => item.value === code)?.label || fallback || tokenCode || '-'
}

function tokenDisplayLabel(tokenCode: string, fallback?: string) {
  const code = normalizeTokenCode(tokenCode || fallback || '')
  return fallback || fallbackTokenOptions.find(item => item.value === code)?.label || tokenCode || '-'
}

export function renderTokenInline(tokenCode: string, label?: string, logoSize = 18) {
  const code = normalizeTokenCode(tokenCode)
  const logo = tokenLogo(code)
  const displayLabel = tokenDisplayLabel(code, label)
  return h(NFlex, {
    align: 'center',
    wrap: false,
    size: 8,
    inline: true,
    style: {
      display: 'inline-flex',
      alignItems: 'center',
      verticalAlign: 'middle',
      lineHeight: '1'
    }
  }, {
    default: () => [
      logo ? h(NImage, {
        src: logo,
        width: logoSize,
        height: logoSize,
        objectFit: 'contain',
        previewDisabled: true,
        draggable: false,
        onDragstart: preventImageDrag,
        imgProps: nonDraggableImageProps,
        style: nonDraggableImageWrapperStyle(logoSize)
      }) : null,
      h(NText, {
        depth: 1,
        style: {
          display: 'inline-flex',
          alignItems: 'center',
          lineHeight: `${logoSize}px`,
          color: tokenColor(code),
          fontWeight: 600
        }
      }, { default: () => displayLabel })
    ].filter(Boolean)
  })
}

export function renderTokenSelectLabel(option: any) {
  const code = normalizeTokenCode(option?.value || option?.token_code || option?.label || '')
  return renderTokenInline(code, tokenDisplayLabel(code, option?.label), 18)
}

export function renderTokenSelectTag(props: any) {
  return renderTokenSelectLabel(props?.option || props)
}

export function renderTokenTag(rowOrCode: any) {
  const code = typeof rowOrCode === 'string'
    ? normalizeTokenCode(rowOrCode)
    : normalizeTokenCode(rowOrCode?.token_code || rowOrCode?.value || rowOrCode?.label || '')
  return renderTokenInline(code, tokenLabel(code), 15)
}

export function renderTokenAmount(amount: unknown, tokenCode = 'USDC') {
  const amountText = String(amount ?? '').trim()
  if (!amountText) return '-'
  return h(NFlex, {
    align: 'center',
    wrap: false,
    size: 8,
    inline: true,
    style: {
      display: 'inline-flex',
      alignItems: 'center',
      verticalAlign: 'middle',
      lineHeight: '1'
    }
  }, {
    default: () => [
      h(NText, { depth: 1 }, { default: () => amountText }),
      renderTokenInline(tokenCode, tokenLabel(tokenCode), 15)
    ]
  })
}

export const TokenInline = defineComponent({
  name: 'TokenInline',
  props: {
    code: { type: String, default: '' },
    label: { type: String, default: '' },
    size: { type: Number, default: 18 }
  },
  setup(props) {
    return () => renderTokenInline(props.code, props.label || undefined, props.size)
  }
})

export const TokenAmount = defineComponent({
  name: 'TokenAmount',
  props: {
    amount: { type: [String, Number], default: '' },
    code: { type: String, default: 'USDC' }
  },
  setup(props) {
    return () => renderTokenAmount(props.amount, props.code)
  }
})

export function fiatSymbol(code: string, options: any[] = fallbackFiatOptions) {
  return options.find(item => item.value === code)?.symbol || code || ''
}

export function formatFiat(amount: unknown, currency: string, options: any[] = fallbackFiatOptions) {
  if (!amount) return '-'
  return `${fiatSymbol(currency, options)}${amount} ${currency}`
}

export function formatToken(amount: unknown, token = 'USDC') {
  if (!amount) return '-'
  return `${amount} ${token || 'USDC'}`
}
