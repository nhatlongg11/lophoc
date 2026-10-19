import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { MaterialLibrary } from './exam-materials'

afterEach(() => vi.restoreAllMocks())

function library(role = 'teacher', remove?: () => Promise<Response>) {
  let deleted = false
  const material = { id: 12, name: 'Bài ôn tập.pdf', folder: '', exam_category: 'general', visibility: 'private', download_allowed: true, created_at: '2026-10-19T07:00:00Z' }
  const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, options) => {
    const path = String(input)
    let body: unknown = []
    if (options?.method === 'DELETE') {
      if (remove) return remove()
      deleted = true
      body = { message: 'Đã xóa tài liệu.' }
    } else if (path.startsWith('/api/library?')) body = deleted ? [] : [material]
    else if (path === '/api/arcade/rounds') body = { data: [] }
    else if (path === '/api/csrf') body = { token: 'test-csrf' }
    return new Response(JSON.stringify(body), { headers: { 'Content-Type': 'application/json' } })
  })
  const notify = vi.fn()
  render(<MaterialLibrary user={{ id: 1, name: 'Teacher', email: 'teacher@example.test', role }} notify={notify}/>)
  return { fetcher, notify }
}

describe('deleting library documents', () => {
  it('requires confirmation and lets the teacher cancel without deleting', async () => {
    const { fetcher } = library()
    fireEvent.click(await screen.findByRole('button', { name: 'Xóa tài liệu' }))
    const confirmation = screen.getByRole('dialog', { name: 'Xóa tài liệu?' })
    expect(within(confirmation).getByText('Bài ôn tập.pdf', { exact: true })).toBeVisible()
    fireEvent.click(within(confirmation).getByRole('button', { name: 'Hủy' }))
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(fetcher.mock.calls.some(([, options]) => options?.method === 'DELETE')).toBe(false)
    expect(screen.getByRole('heading', { name: 'Bài ôn tập.pdf' })).toBeVisible()
  })

  it('deletes the confirmed document and refreshes the library', async () => {
    const { fetcher, notify } = library()
    fireEvent.click(await screen.findByRole('button', { name: 'Xóa tài liệu' }))
    fireEvent.click(screen.getByRole('button', { name: 'Xác nhận xóa' }))
    await screen.findByText('Chưa có tài liệu phù hợp.')
    expect(fetcher).toHaveBeenCalledWith('/api/library/12', expect.objectContaining({ method: 'DELETE', credentials: 'same-origin', headers: expect.objectContaining({ 'X-CSRF-TOKEN': 'test-csrf' }) }))
    expect(screen.queryByRole('heading', { name: 'Bài ôn tập.pdf' })).not.toBeInTheDocument()
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(notify).toHaveBeenCalledWith('Đã xóa tài liệu khỏi kho và ngừng chia sẻ với học sinh.')
  })

  it('prevents repeated submissions and preserves the document when deletion fails', async () => {
    let finish!: (response: Response) => void
    const { fetcher, notify } = library('teacher', () => new Promise(resolve => { finish = resolve }))
    fireEvent.click(await screen.findByRole('button', { name: 'Xóa tài liệu' }))
    fireEvent.click(screen.getByRole('button', { name: 'Xác nhận xóa' }))
    await waitFor(() => expect(fetcher.mock.calls.filter(([, options]) => options?.method === 'DELETE')).toHaveLength(1))
    expect(screen.getByRole('button', { name: 'Đang xóa…' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Hủy' })).toBeDisabled()
    fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' })
    expect(screen.getByRole('dialog')).toBeVisible()
    finish(new Response(JSON.stringify({ message: 'Không thể xóa tài liệu. Vui lòng thử lại.' }), { status: 503, headers: { 'Content-Type': 'application/json' } }))
    expect(await screen.findByRole('alert')).toHaveTextContent('Không thể xóa tài liệu. Vui lòng thử lại.')
    expect(screen.getByRole('button', { name: 'Xác nhận xóa' })).toBeEnabled()
    expect(screen.getByRole('heading', { name: 'Bài ôn tập.pdf' })).toBeVisible()
    expect(notify).not.toHaveBeenCalled()
  })

  it('does not offer deletion to students', async () => {
    library('student')
    await screen.findByRole('heading', { name: 'Bài ôn tập.pdf' })
    expect(screen.queryByRole('button', { name: 'Xóa tài liệu' })).not.toBeInTheDocument()
  })
})
