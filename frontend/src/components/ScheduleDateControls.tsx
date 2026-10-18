import { Icon } from './Icon'

export type CalendarMode = 'day' | 'week' | 'month'

export function schoolCalendarToday(instant = new Date()) {
  const date = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Ho_Chi_Minh', year: 'numeric', month: '2-digit', day: '2-digit' }).format(instant)
  const [year, month, day] = date.split('-').map(Number)
  return new Date(year, month - 1, day, 12)
}

export function calendarDate(date: Date) {
  return [String(date.getFullYear()).padStart(4, '0'), String(date.getMonth() + 1).padStart(2, '0'), String(date.getDate()).padStart(2, '0')].join('-')
}

export function calendarRange(anchor: Date, mode: CalendarMode) {
  const start = new Date(anchor), end = new Date(anchor)
  if (mode === 'week') {
    start.setDate(start.getDate() - (start.getDay() + 6) % 7)
    end.setFullYear(start.getFullYear(), start.getMonth(), start.getDate() + 6)
  } else if (mode === 'month') {
    start.setDate(1)
    end.setMonth(start.getMonth() + 1, 0)
  }
  return { from: calendarDate(start), to: calendarDate(end) }
}

export function shiftCalendarDate(anchor: Date, mode: CalendarMode, direction: -1 | 1) {
  const next = new Date(anchor)
  if (mode === 'month') {
    const day = next.getDate()
    next.setDate(1)
    next.setMonth(next.getMonth() + direction)
    const lastDay = new Date(next.getFullYear(), next.getMonth() + 1, 0).getDate()
    next.setDate(Math.min(day, lastDay))
  } else next.setDate(next.getDate() + direction * (mode === 'week' ? 7 : 1))
  return next
}

export function ScheduleDateControls({ anchor, mode, onChange }: { anchor: Date; mode: CalendarMode; onChange: (date: Date) => void }) {
  const range = calendarRange(anchor, mode)
  return <div className="date-controls">
    <label className="date-jump"><span><Icon name="schedule"/>Chọn ngày</span><input type="date" aria-label="Chọn ngày" min="0001-01-01" max="9999-12-31" value={calendarDate(anchor)} onChange={event => {
      if (!event.target.validity.valid || !event.target.value) return
      const [year, month, day] = event.target.value.split('-').map(Number)
      const selected = new Date(anchor)
      // Parse local calendar parts, avoiding UTC conversion shifting the selected day.
      selected.setFullYear(year, month - 1, day)
      selected.setHours(12, 0, 0, 0)
      onChange(selected)
    }}/></label>
    <div className="date-range-navigation">
      <button type="button" className="date-arrow" aria-label="Khoảng trước" onClick={() => onChange(shiftCalendarDate(anchor, mode, -1))}><Icon name="previous"/></button>
      <output className="date-range" aria-live="polite">{range.from} — {range.to}</output>
      <button type="button" className="date-arrow" aria-label="Khoảng tiếp theo" onClick={() => onChange(shiftCalendarDate(anchor, mode, 1))}><Icon name="next"/></button>
    </div>
    <button type="button" className="btn secondary" onClick={() => onChange(schoolCalendarToday())}>Hôm nay</button>
  </div>
}
