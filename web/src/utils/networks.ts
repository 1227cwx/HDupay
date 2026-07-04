import { defineComponent, h } from 'vue'
import { NFlex, NImage, NTag, NText } from 'naive-ui'
import ethereumLogo from '../assets/networks/ethereum.ico'
import baseLogo from '../assets/networks/base.ico'
import celoLogo from '../assets/networks/celo.ico'
import polygonLogo from '../assets/networks/polygon.ico'
import { nonDraggableImageProps, nonDraggableImageWrapperStyle, preventImageDrag } from './iconImage'

export const networkOptions = [
  { label: 'Ethereum', value: 'ethereum' },
  { label: 'Base', value: 'base' },
  { label: 'Celo', value: 'celo' },
  { label: 'Polygon', value: 'polygon' }
]

export const shortNetworkOptions = [
  { label: 'Ethereum', value: 'ethereum' },
  { label: 'Base', value: 'base' },
  { label: 'Celo', value: 'celo' },
  { label: 'Polygon', value: 'polygon' }
]

const logoMap: Record<string, string> = {
  ethereum: ethereumLogo,
  base: baseLogo,
  celo: celoLogo,
  polygon: polygonLogo
}

export function normalizeNetworkCode(networkCode: string) {
  const raw = String(networkCode || '').toLowerCase()
  if (raw.includes('ethereum')) return 'ethereum'
  if (raw.includes('polygon')) return 'polygon'
  if (raw.includes('base')) return 'base'
  if (raw.includes('celo')) return 'celo'
  return raw
}

export function networkTagType(networkCode: string) {
  networkCode = normalizeNetworkCode(networkCode)
  if (networkCode === 'base') return 'info'
  if (networkCode === 'celo') return 'warning'
  if (networkCode === 'polygon') return 'error'
  if (networkCode === 'ethereum') return 'success'
  return 'default'
}

export function networkColor(networkCode: string) {
  networkCode = normalizeNetworkCode(networkCode)
  if (networkCode === 'base') return '#2563eb'
  if (networkCode === 'celo') return '#f59e0b'
  if (networkCode === 'polygon') return '#7c3aed'
  if (networkCode === 'ethereum') return '#16a34a'
  return '#64748b'
}

export function networkLogo(networkCode: string) {
  return logoMap[normalizeNetworkCode(networkCode)] || ''
}

export function networkLabel(networkCode: string, fallback?: string) {
  const code = normalizeNetworkCode(networkCode)
  return shortNetworkOptions.find(item => item.value === code)?.label || fallback || networkCode || '-'
}

function networkDisplayLabel(networkCode: string, fallback?: string) {
  const code = normalizeNetworkCode(networkCode || fallback || '')
  return shortNetworkOptions.find(item => item.value === code)?.label || fallback || networkCode || '-'
}

export function renderNetworkInline(networkCode: string, label?: string, logoSize = 18) {
  const code = normalizeNetworkCode(networkCode)
  const logo = networkLogo(code)
  const displayLabel = networkDisplayLabel(code, label)
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
          lineHeight: `${logoSize}px`
        }
      }, { default: () => displayLabel })
    ].filter(Boolean)
  })
}

export function renderNetworkSelectLabel(option: any) {
  const code = normalizeNetworkCode(option?.value || option?.network_code || option?.label || '')
  return renderNetworkInline(code, networkDisplayLabel(code, option?.label), 18)
}

export function renderNetworkSelectTag(props: any) {
  return renderNetworkSelectLabel(props?.option || props)
}

export function renderNetworkTag(rowOrCode: any) {
  const code = typeof rowOrCode === 'string' ? normalizeNetworkCode(rowOrCode) : normalizeNetworkCode(rowOrCode?.network_code || rowOrCode?.value || rowOrCode?.label || '')
  const label = typeof rowOrCode === 'string'
    ? networkLabel(code)
    : networkDisplayLabel(code, rowOrCode?.network_name || rowOrCode?.label)

  return h(NTag, { type: networkTagType(code), bordered: true, round: true }, {
    default: () => renderNetworkInline(code, label, 15)
  })
}

export const NetworkTag = defineComponent({
  name: 'NetworkTag',
  props: {
    code: { type: String, default: '' },
    label: { type: String, default: '' }
  },
  setup(props) {
    return () => renderNetworkTag({ network_code: props.code, label: props.label || undefined })
  }
})
