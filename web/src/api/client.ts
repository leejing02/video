import axios from 'axios'

const TOKEN_KEY = 'auth_token'
let _token: string | null = null

const client = axios.create({
  baseURL: '/api',
  timeout: 15000,
  headers: { Accept: 'application/json' }
})

export function setToken(token: string | null) {
  _token = token
  if (token) {
    localStorage.setItem(TOKEN_KEY, token)
  } else {
    localStorage.removeItem(TOKEN_KEY)
  }
}

client.interceptors.request.use(config => {
  if (_token) config.headers.set('Authorization', `Bearer ${_token}`)
  return config
})

client.interceptors.response.use(
  res => res,
  async err => {
    if (err.response?.status === 401) {
      setToken(null)
      window.dispatchEvent(new CustomEvent('auth:unauthorized'))
    }
    return Promise.reject(err)
  }
)

export async function get<T>(url: string, params?: Record<string, any>): Promise<T> {
  return (await client.get<T>(url, { params })).data
}

export async function post<T>(url: string, data?: any): Promise<T> {
  return (await client.post<T>(url, data)).data
}

export async function patch<T>(url: string, data?: any): Promise<T> {
  return (await client.patch<T>(url, data)).data
}

export async function del<T>(url: string): Promise<T> {
  return (await client.delete<T>(url)).data
}

export default client
