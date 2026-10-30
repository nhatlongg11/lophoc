import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { Homework } from './homework'

afterEach(() => vi.restoreAllMocks())
const json = (body: unknown) => new Response(JSON.stringify(body), { headers: { 'Content-Type': 'application/json' } })
const user = { id: 2, name: 'Như', email: 'nhu@example.test', role: 'teacher' }
const subjects = [{ id: 1, name: 'Toán' }, { id: 2, name: 'Tiếng Anh' }]
const assignment = { id: 12, grade: '3', subject_id: 1, subject_name: 'Toán', title: 'Phép cộng', instructions: 'Chọn đáp án đúng.', max_score: 10, question_count: 1, student_count: 1, student_ids: [2], submitted_count: 0, allowed_attempts: 1, published: true, due_at: '2099-10-30T10:00:00Z' }

function transport() {
  let classified = false
  const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, options) => {
    const path = String(input)
    if (path === '/api/subjects') return json(subjects)
    if (path === '/api/assignments/16/classification' && options?.method === 'PATCH') { classified = true; return json({ message: 'Đã cập nhật.' }) }
    if (path.startsWith('/api/assignments?')) {
      const query = new URL(path, 'http://localhost').searchParams
      const rows = [assignment, { ...assignment, id: 13, subject_id: 2, subject_name: 'Tiếng Anh', title: 'Từ vựng' }, { ...assignment, id: 14, subject_id: null, subject_name: null, title: 'Bài cũ' }, { ...assignment, id: 15, grade: '5', title: 'Bài lớp 5' }, { ...assignment, id: 16, grade: classified ? '4' : null, subject_id: classified ? 2 : 1, title: 'Bài chưa phân lớp' }]
      return json(rows.filter(row => (!query.has('grade') || (query.get('grade') === 'unclassified' ? !row.grade : row.grade === query.get('grade')))).filter(row => query.has('unassigned') ? row.subject_id === null : !query.has('subject_id') || String(row.subject_id) === query.get('subject_id')))
    }
    if (path === '/api/csrf') return json({ token: 'test-csrf' })
    if (path === '/api/users?role=student') return json([{ ...user, role: 'student', active: true }])
    return json([])
  })
  return fetcher
}

describe('homework subjects and automatic quiz results', () => {
  it('shows only classes 3, 4 and 5 before subjects and resets the subject when switching class', async () => {
    const fetcher = transport()
    render(<Homework user={user} notify={vi.fn()}/>)
    expect(screen.getByRole('navigation', { name: 'Chọn lớp' })).toBeVisible()
    expect(screen.getByRole('button', { name: 'Lớp 3' })).toBeVisible()
    expect(screen.getByRole('button', { name: 'Lớp 4' })).toBeVisible()
    expect(screen.getByRole('button', { name: 'Lớp 5' })).toBeVisible()
    expect(screen.queryByRole('button', { name: 'Lớp 6' })).not.toBeInTheDocument()
    expect(screen.queryByRole('navigation', { name: 'Lọc theo môn học' })).not.toBeInTheDocument()
    expect(fetcher.mock.calls.some(([path]) => String(path).startsWith('/api/assignments'))).toBe(false)
    fireEvent.click(screen.getByRole('button', { name: 'Lớp 3' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Toán' }))
    await screen.findByRole('heading', { name: 'Phép cộng' })
    fireEvent.click(screen.getByRole('button', { name: 'Lớp 5' }))
    await screen.findByRole('heading', { name: 'Bài lớp 5' })
    expect(screen.queryByRole('heading', { name: 'Phép cộng' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Tất cả môn học' })).toHaveAttribute('aria-pressed', 'true')
  })

  it('lets a teacher classify old work without losing access to it', async () => {
    const fetcher = transport()
    render(<Homework user={user} notify={vi.fn()}/>)
    fireEvent.click(screen.getByRole('button', { name: 'Bài chưa phân lớp' }))
    const heading = await screen.findByRole('heading', { name: 'Bài chưa phân lớp' })
    fireEvent.click(within(heading.closest('article')!).getByRole('button', { name: 'Phân lớp & môn' }))
    const dialog = screen.getByRole('dialog', { name: 'Phân lớp & môn học' })
    await within(dialog).findByRole('option', { name: 'Tiếng Anh' })
    fireEvent.change(within(dialog).getByLabelText('Lớp'), { target: { value: '4' } })
    fireEvent.change(within(dialog).getByLabelText('Môn học'), { target: { value: '2' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Lưu phân loại' }))
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(fetcher).toHaveBeenCalledWith('/api/assignments/16/classification', expect.objectContaining({ method: 'PATCH', body: JSON.stringify({ grade: '4', subject_id: 2 }) }))
    fireEvent.click(screen.getByRole('button', { name: 'Lớp 4' }))
    await screen.findByRole('heading', { name: 'Bài chưa phân lớp' })
  })

  it('filters by subject, keeps unclassified work accessible, and shares the filter with file assignments', async () => {
    const fetcher = transport()
    render(<Homework user={user} notify={vi.fn()} legacy={subject => <p data-testid="file-subject">{subject}</p>}/>)
    fireEvent.click(screen.getByRole('button', { name: 'Lớp 3' }))
    await screen.findByRole('heading', { name: 'Từ vựng' })
    const filter = screen.getByRole('navigation', { name: 'Lọc theo môn học' })
    fireEvent.click(within(filter).getByRole('button', { name: 'Toán' }))
    await screen.findByRole('heading', { name: 'Phép cộng' })
    expect(screen.queryByRole('heading', { name: 'Từ vựng' })).not.toBeInTheDocument()
    expect(within(filter).getByRole('button', { name: 'Toán' })).toHaveAttribute('aria-pressed', 'true')
    fireEvent.click(screen.getByRole('button', { name: 'Nộp văn bản / tệp' }))
    expect(screen.getByTestId('file-subject')).toHaveTextContent('1')
    fireEvent.click(within(filter).getByRole('button', { name: 'Chưa phân môn' }))
    fireEvent.click(screen.getByRole('button', { name: 'Bài tập & lượt làm' }))
    await screen.findByRole('heading', { name: 'Bài cũ' })
    expect(screen.queryByRole('heading', { name: 'Phép cộng' })).not.toBeInTheDocument()
    expect(fetcher.mock.calls.some(([path]) => path === '/api/assignments?unassigned=1&grade=3')).toBe(true)
  })

  it('preselects the chosen subject and defaults to automatic grading with answer release', async () => {
    transport()
    render(<Homework user={user} notify={vi.fn()}/>)
    fireEvent.click(screen.getByRole('button', { name: 'Lớp 3' }))
    const filter = await screen.findByRole('navigation', { name: 'Lọc theo môn học' })
    await within(filter).findByRole('button', { name: 'Tiếng Anh' })
    fireEvent.click(within(filter).getByRole('button', { name: 'Tiếng Anh' }))
    fireEvent.click(screen.getByRole('button', { name: 'Tạo bài tập có câu hỏi' }))
    const dialog = screen.getByRole('dialog')
    await waitFor(() => expect(within(dialog).getByLabelText('Môn học')).toHaveValue('2'))
    expect(within(dialog).getByLabelText('Hình thức bài tập')).toHaveValue('quiz')
    expect(within(dialog).getByLabelText('Hiện đáp án và giải thích sau khi nộp')).toBeChecked()
    expect(within(dialog).queryByRole('option', { name: 'Tự luận' })).not.toBeInTheDocument()
    fireEvent.change(within(dialog).getByLabelText('Hình thức bài tập'), { target: { value: 'mixed' } })
    expect(within(dialog).getByRole('option', { name: 'Tự luận' })).toBeInTheDocument()
  })

  it('shows reviewed essays as graded without inventing a per-question manual score', async () => {
    const fetcher = transport(), base = fetcher.getMockImplementation()!
    fetcher.mockImplementation(async (input, options) => {
      if (String(input) === '/api/assignments/12') return json(assignment)
      if (String(input) === '/api/assignments/12/attempts') return json([{ id: 44, attempt_number: 1, status: 'graded', submitted_at: '2026-10-28T08:00:00Z', score: 8, questions: [{ id: 7, type: 'essay', prompt: 'Viết đoạn văn', answer: null, points: 1 }], answers: { 7: 'Bài văn của em' }, grading: { 7: { correct: null, points: 0, max_points: 1 } } }])
      return base(input, options)
    })
    render(<Homework user={{ ...user, role: 'student' }} notify={vi.fn()}/>)
    fireEvent.click(screen.getByRole('button', { name: 'Lớp 3' }))
    const heading = await screen.findByRole('heading', { name: 'Phép cộng' })
    fireEvent.click(within(heading.closest('article')!).getByRole('button', { name: /Xem bài tập/ }))
    fireEvent.click(await screen.findByText('Xem bài làm và đáp án'))
    expect(screen.getByText('Đã được giáo viên chấm')).toBeVisible()
    expect(screen.queryByText(/0\/1 điểm/)).not.toBeInTheDocument()
    expect(screen.queryByText('Chờ giáo viên chấm')).not.toBeInTheDocument()
  })

  it('shows automatic score, wrong answers and explanations immediately after submission', async () => {
    const fetcher = transport()
    const start = { id: 44, student_id: 2, attempt_number: 1, questions: [{ id: 7, type: 'single', prompt: '2 + 2?', options: ['3', '4'], points: 1 }], answers: {}, submitted_at: null, expires_at: '2099-10-30T10:00:00Z', status: 'in_progress', score: null }
    const base = fetcher.getMockImplementation()!
    fetcher.mockImplementation(async (input, options) => {
      if (String(input) === '/api/assignments/12') return json(assignment)
      if (String(input) === '/api/assignments/12/start') return json(start)
      if (String(input) === '/api/attempts/44/submit') return json({ ...start, submitted_at: '2026-10-28T08:00:00Z', status: 'graded', score: 0, questions: [{ ...start.questions[0], answer: '4', explanation: 'Hai cộng hai bằng bốn.' }], answers: { 7: '3' }, grading: { 7: { correct: false, points: 0, max_points: 1 } } })
      return base(input, options)
    })
    render(<Homework user={{ ...user, role: 'student' }} notify={vi.fn()}/>)
    fireEvent.click(screen.getByRole('button', { name: 'Lớp 3' }))
    fireEvent.click(within(await screen.findByRole('heading', { name: 'Phép cộng' }).then(heading => heading.closest('article')!)).getByRole('button', { name: /Xem bài tập/ }))
    fireEvent.click(await screen.findByRole('button', { name: 'Bắt đầu / tiếp tục làm bài' }))
    fireEvent.click(await screen.findByRole('radio', { name: /3$/ }))
    expect(screen.queryByText('Đáp án đúng:')).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Nộp bài' }))
    fireEvent.click(screen.getByRole('button', { name: 'Xác nhận nộp' }))
    expect(await screen.findByText('0/10')).toBeVisible()
    expect(screen.getByText('Đã chấm điểm tự động. Không cần chờ giáo viên chấm.')).toBeVisible()
    const review = screen.getByRole('list', { name: 'Chi tiết kết quả' })
    expect(review).toHaveTextContent('Sai')
    expect(review).toHaveTextContent('Câu trả lời của em: 3')
    expect(review).toHaveTextContent('Đáp án đúng: 4')
    expect(review).toHaveTextContent('Hai cộng hai bằng bốn.')
  })
})
