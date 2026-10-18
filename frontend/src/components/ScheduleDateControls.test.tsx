import { useState } from 'react'
import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { calendarDate, calendarRange, ScheduleDateControls, schoolCalendarToday, shiftCalendarDate, type CalendarMode } from './ScheduleDateControls'

function Calendar({ mode }: { mode: CalendarMode }) {
  const [anchor, setAnchor] = useState(new Date(2026, 9, 15))
  return <ScheduleDateControls anchor={anchor} mode={mode} onChange={setAnchor}/>
}

describe('timetable navigation', () => {
  it('uses the Vietnamese date at the Sunday-to-Monday UTC boundary', () => {
    const schoolDay = schoolCalendarToday(new Date('2026-10-18T18:30:00Z'))
    expect(calendarDate(schoolDay)).toBe('2026-10-19')
    expect(calendarRange(schoolDay, 'week')).toEqual({ from: '2026-10-19', to: '2026-10-25' })
  })
  it.each([
    ['day', '2030-02-12 — 2030-02-12'],
    ['week', '2030-02-11 — 2030-02-17'],
    ['month', '2030-02-01 — 2030-02-28'],
  ] as const)('jumps directly to a distant date in %s view', (mode, expected) => {
    render(<Calendar mode={mode}/>)
    fireEvent.change(screen.getByLabelText('Chọn ngày'), { target: { value: '2030-02-12' } })
    expect(screen.getByRole('status')).toHaveTextContent(expected)
    fireEvent.change(screen.getByLabelText('Chọn ngày'), { target: { value: '' } })
    expect(screen.getByRole('status')).toHaveTextContent(expected)
  })

  it('moves into February instead of skipping it from January 31', () => {
    expect(shiftCalendarDate(new Date(2027, 0, 31), 'month', 1)).toEqual(new Date(2027, 1, 28))
    expect(shiftCalendarDate(new Date(2028, 2, 31), 'month', -1)).toEqual(new Date(2028, 1, 29))
  })

  it('keeps Sunday in the preceding Monday-based week across the year boundary', () => {
    expect(calendarRange(new Date(2027, 0, 3), 'week')).toEqual({ from: '2026-12-28', to: '2027-01-03' })
  })

  it('keeps arrows and Today available with accessible names', () => {
    render(<Calendar mode="day"/>)
    fireEvent.click(screen.getByRole('button', { name: 'Khoảng tiếp theo' }))
    expect(screen.getByLabelText('Chọn ngày')).toHaveValue('2026-10-16')
    fireEvent.click(screen.getByRole('button', { name: 'Khoảng trước' }))
    expect(screen.getByLabelText('Chọn ngày')).toHaveValue('2026-10-15')
    fireEvent.click(screen.getByRole('button', { name: 'Hôm nay' }))
    expect(screen.getByRole('status')).toHaveTextContent(calendarRange(new Date(), 'day').from)
  })
})
