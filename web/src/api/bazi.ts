import { get, post } from './client'

export interface BaziPillars {
  year: string
  month: string
  day: string
  hour: string
}

export interface DayunItem {
  age: number
  ganzhi: string
}

export interface BaziAnalysis {
  overall: string
  wuxing_advice: string
  dayun_now: string
  career: string
  love: string
  advice: string
}

export interface BaziResult {
  pillars: BaziPillars
  wuxing: Record<string, number>
  dayun: DayunItem[]
  start_age: number
  forward: boolean
  analysis?: BaziAnalysis
  share_token?: string
  birthday?: string
  gender?: string
}

export const baziApi = {
  calculate: (birthday: string, gender: 'male' | 'female') =>
    post<BaziResult>('/bazi/calculate', { birthday, gender }),

  getChart: (token: string) =>
    get<BaziResult>(`/bazi/chart/${token}`),
}
