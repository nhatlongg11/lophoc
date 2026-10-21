import { act, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { AuthScreen } from './accounts'
import { RegistrationReview } from './registration-review'

afterEach(() => {
  vi.restoreAllMocks()
  window.history.replaceState({}, '', '/')
})

const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })

describe('registration is closed until explicitly enabled', () => {
  it('hides registration and ignores direct registration links when closed', async () => {
    window.history.replaceState({}, '', '/app/?register=1')
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(json({ enabled: false, approval_required: true }))
    await act(async () => { render(<AuthScreen onLogin={vi.fn()}/>) })
    expect(screen.queryByRole('button', { name: /Chưa có tài khoản/ })).not.toBeInTheDocument()
    expect(screen.queryByLabelText('Họ và tên')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Đăng nhập/ })).toBeVisible()
  })

  it('keeps registration hidden if its status cannot be loaded and preserves login', async () => {
    const user = { id: 1, name: 'Teacher', role: 'teacher', email: 'teacher@example.test' }
    const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementation(async input => {
      if (String(input).endsWith('/registration/status')) return json({ message: 'Unavailable' }, 503)
      if (String(input).endsWith('/csrf')) return json({ token: 'test-csrf' })
      return json({ user })
    })
    const onLogin = vi.fn()
    await act(async () => { render(<AuthScreen onLogin={onLogin}/>) })
    expect(screen.queryByRole('button', { name: /Chưa có tài khoản/ })).not.toBeInTheDocument()
    fireEvent.change(screen.getByLabelText('Email'), { target: { value: user.email } })
    fireEvent.change(screen.getByLabelText('Mật khẩu'), { target: { value: 'ExistingPassword123!' } })
    fireEvent.click(screen.getByRole('button', { name: /Đăng nhập/ }))
    await waitFor(() => expect(onLogin).toHaveBeenCalledWith(user))
    expect(fetcher.mock.calls.some(([path]) => path === '/api/register')).toBe(false)
  })

  it.each(['student', 'teacher', 'parent'])('shows a pending message without auto-login for %s requests', async role => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementation(async input => {
      if (String(input).endsWith('/registration/status')) return json({ enabled: true, approval_required: true })
      if (String(input).endsWith('/csrf')) return json({ token: 'test-csrf' })
      return json({ message: 'Đã gửi yêu cầu đăng ký. Vui lòng chờ quản trị viên duyệt trước khi đăng nhập.', status: 'pending' }, 201)
    })
    const onLogin = vi.fn()
    render(<AuthScreen onLogin={onLogin}/>)
    fireEvent.click(await screen.findByRole('button', { name: 'Chưa có tài khoản? Đăng ký' }))
    fireEvent.change(screen.getByLabelText('Họ và tên'), { target: { value: 'Nguyễn Như' } })
    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'new@example.test' } })
    fireEvent.change(screen.getByLabelText('Mật khẩu'), { target: { value: 'Registration123!' } })
    fireEvent.change(screen.getByLabelText('Nhập lại mật khẩu'), { target: { value: 'Registration123!' } })
    fireEvent.change(screen.getByLabelText('Vai trò'), { target: { value: role } })
    if (role === 'parent') fireEvent.change(screen.getByLabelText('PIN phụ huynh (tài khoản gia đình)'), { target: { value: '629184' } })
    fireEvent.click(screen.getByRole('button', { name: 'Gửi yêu cầu đăng ký' }))
    expect(await screen.findByRole('status')).toHaveTextContent('Vui lòng chờ quản trị viên duyệt')
    expect(screen.getByRole('button', { name: /Đăng nhập/ })).toBeVisible()
    expect(screen.getByLabelText('Mật khẩu')).toHaveValue('')
    expect(onLogin).not.toHaveBeenCalled()
    expect(fetcher.mock.calls.some(([path]) => path === '/api/login')).toBe(false)
    const registration = fetcher.mock.calls.find(([path]) => path === '/api/register')!
    expect(JSON.parse(String(registration[1]?.body))).toMatchObject({ role, parent_pin: role === 'parent' ? '629184' : null })
  })
})

describe('admin registration review', () => {
  it('requires confirmation, then approves and refreshes the queue', async () => {
    let reviewed = false
    const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, options) => {
      if (String(input).endsWith('/csrf')) return json({ token: 'test-csrf' })
      if (options?.method === 'POST') { reviewed = true; return json({ message: 'Đã duyệt tài khoản.' }) }
      return json(reviewed ? [] : [{ id: 12, name: 'Nguyễn Như', email: 'new@example.test', role: 'teacher', created_at: '2026-10-21T10:00:00Z' }])
    })
    const onReviewed = vi.fn(), notify = vi.fn()
    render(<RegistrationReview notify={notify} onReviewed={onReviewed}/>)
    fireEvent.click(await screen.findByRole('button', { name: 'Chấp nhận' }))
    expect(fetcher.mock.calls.some(([, options]) => options?.method === 'POST')).toBe(false)
    fireEvent.click(screen.getByRole('button', { name: 'Hủy' }))
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Chấp nhận' }))
    fireEvent.click(screen.getByRole('button', { name: 'Xác nhận' }))
    await screen.findByText('Không có yêu cầu đăng ký đang chờ duyệt.')
    expect(fetcher).toHaveBeenCalledWith('/api/registrations/12/review', expect.objectContaining({ method: 'POST', body: JSON.stringify({ decision: 'approved' }) }))
    expect(onReviewed).toHaveBeenCalledOnce()
    expect(notify).toHaveBeenCalledWith('Đã duyệt tài khoản.')
  })

  it('keeps a failed rejection available to retry and shows the server error', async () => {
    vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, options) => {
      if (String(input).endsWith('/csrf')) return json({ token: 'test-csrf' })
      if (options?.method === 'POST') return json({ message: 'Không thể xử lý yêu cầu.' }, 503)
      return json([{ id: 12, name: 'Nguyễn Như', email: 'new@example.test', role: 'student', created_at: '2026-10-21T10:00:00Z' }])
    })
    const onReviewed = vi.fn()
    render(<RegistrationReview notify={vi.fn()} onReviewed={onReviewed}/>)
    fireEvent.click(await screen.findByRole('button', { name: 'Từ chối' }))
    fireEvent.click(screen.getByRole('button', { name: 'Xác nhận' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('Không thể xử lý yêu cầu.')
    expect(screen.getByRole('dialog', { name: 'Từ chối đăng ký?' })).toBeVisible()
    expect(onReviewed).not.toHaveBeenCalled()
  })
})
