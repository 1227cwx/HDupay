import { h } from 'vue'
import { NText, NTooltip } from 'naive-ui'

const nowrapTextStyle = {
  whiteSpace: 'nowrap',
  wordBreak: 'keep-all',
  overflowWrap: 'normal',
  display: 'inline-block'
}

export function shortPrefixText(value: unknown, keep = 8) {
  const text = String(value ?? '')
  if (!text) {
    return '-'
  }
  return text.length > keep ? `${text.slice(0, keep)}...` : text
}

export function renderShortText(value: unknown, keep = 8) {
  const text = String(value ?? '')
  if (!text) {
    return '-'
  }
  return h(NTooltip, {
    trigger: 'hover',
    maxWidth: 520,
    contentStyle: {
      whiteSpace: 'normal',
      wordBreak: 'break-all',
      lineHeight: '1.6'
    }
  }, {
    trigger: () => h(NText, { style: nowrapTextStyle }, { default: () => shortPrefixText(text, keep) }),
    default: () => text
  })
}
