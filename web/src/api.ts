export type ApiResult<T> = {
  code: number
  msg: string
  data: T
}

async function request<T>(url: string, options: RequestInit = {}): Promise<T> {
  let response: Response
  try {
    response = await fetch(url, {
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        ...(options.headers || {})
      },
      ...options
    })
  } catch {
    throw new Error('网络请求失败，请检查网络连接')
  }
  if (!response.ok) {
    throw new Error(`接口请求失败，状态码 ${response.status}`)
  }

  let body: ApiResult<T>
  try {
    body = (await response.json()) as ApiResult<T>
  } catch {
    throw new Error('接口响应格式错误')
  }
  if (body.code === 401) {
    if (location.pathname !== '/hdupay/login') {
      location.href = '/hdupay/login'
    }
    throw new Error(body.msg || '请先登录后台')
  }
  if (body.code !== 0) {
    throw new Error(body.msg || '请求失败')
  }
  return body.data
}

export const api = {
  get: <T>(url: string) => request<T>(url),
  post: <T>(url: string, data: unknown = {}) => request<T>(url, { method: 'POST', body: JSON.stringify(data) })
}
