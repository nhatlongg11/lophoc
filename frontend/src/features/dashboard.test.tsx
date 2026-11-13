import { fireEvent, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { LearningDashboard } from './dashboard'

afterEach(() => {
  vi.restoreAllMocks()
  window.history.replaceState({}, '', '/')
})

const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })

describe('student classes', () => {
  it('prefills a join code from the link and clears it after joining', async () => {
    window.history.replaceState({}, '', '/app/?join=abc12345')
    let joined = false
    vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, options) => {
      const path = String(input)
      if (path.endsWith('/csrf')) return json({ token: 'test-csrf' })
      if (path.endsWith('/class-memberships') && options?.method === 'POST') {
        joined = true
        return json({ message: 'Đã tham gia lớp của Cô A.', joined: true, teacher: { id: 1, name: 'Cô A' } }, 201)
      }
      if (path.endsWith('/class-memberships')) return json(joined ? [{ id: 1, name: 'Cô A' }] : [])
      if (path.endsWith('/dashboard')) return json({ students: 0, pending_assignments: 0, assignments: 0, submissions: 0, notifications: 0, upcoming_lessons: [], due_assignments: [] })
      if (path.endsWith('/reports')) return json(null)
      return json({})
    })
    render(<LearningDashboard user={{ id: 2, name: 'Học sinh', email: 'student@example.test', role: 'student' }} open={vi.fn()}/>)
    expect(await screen.findByLabelText('Mã lớp')).toHaveValue('ABC12345')
    fireEvent.click(screen.getByRole('button', { name: 'Tham gia lớp' }))
    expect(await screen.findByText('Đã tham gia lớp của Cô A.')).toBeVisible()
    expect(window.location.search).toBe('')
  })
})
