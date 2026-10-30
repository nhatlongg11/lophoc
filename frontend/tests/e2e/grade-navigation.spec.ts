import { test, expect, type Page } from '@playwright/test'

async function login(page: Page, email: string) {
  await page.goto('/app/')
  await page.getByLabel('Email', { exact: true }).fill(email)
  await page.getByLabel('Mật khẩu', { exact: true }).fill('MatKhau123!')
  await page.getByRole('button', { name: 'Đăng nhập', exact: true }).click()
  await expect(page.getByRole('heading', { name: /Chào/ })).toBeVisible()
}

test('class comes before subject and classifying old homework preserves the students result', async ({ browser }, testInfo) => {
  test.setTimeout(120_000)
  const teacherContext = await browser.newContext(), studentContext = await browser.newContext()
  try {
    const teacher = await teacherContext.newPage(), student = await studentContext.newPage()
    const errors: string[] = []
    teacher.on('pageerror', error => errors.push(error.message)); student.on('pageerror', error => errors.push(error.message))
    await login(teacher, 'giaovien@lophoc.local')
    const students = await (await teacher.request.get('/api/users?role=student')).json()
    const recipient = students.find((user: { email: string }) => user.email === 'hocsinh@lophoc.local').id
    const subjects = await (await teacher.request.get('/api/subjects')).json()
    const math = subjects.find((subject: { name: string }) => subject.name === 'Toán học').id
    const english = subjects.find((subject: { name: string }) => subject.name === 'Tiếng Anh').id
    const token = (await (await teacher.request.get('/api/csrf')).json()).token
    const title = 'Legacy work ' + Date.now()
    const created = await teacher.request.post('/api/assignments', { headers: { 'X-CSRF-TOKEN': token }, data: { title, instructions: 'Choose the answer', max_score: 10, due_at: new Date(Date.now() + 3 * 86400000).toISOString(), subject_id: math, student_ids: [recipient], submission_type: 'both', published: true } })
    expect(created.ok(), await created.text()).toBe(true)
    const id = (await created.json()).id
    const authored = await teacher.request.put('/api/assignments/' + id + '/questions', { headers: { 'X-CSRF-TOKEN': token }, data: { allowed_attempts: 1, grade_policy: 'first', release_answers: true, questions: [{ type: 'single', prompt: '2+2?', options: ['3', '4'], answer: '4', points: 1 }] } })
    expect(authored.ok(), await authored.text()).toBe(true)
    await login(student, 'hocsinh@lophoc.local')
    const studentToken = (await (await student.request.get('/api/csrf')).json()).token
    const started = await student.request.post('/api/assignments/' + id + '/start', { headers: { 'X-CSRF-TOKEN': studentToken } })
    const attempt = await started.json()
    const submitted = await student.request.post('/api/attempts/' + attempt.id + '/submit', { headers: { 'X-CSRF-TOKEN': studentToken }, data: { answers: { [attempt.questions[0].id]: '4' } } })
    expect(submitted.ok(), await submitted.text()).toBe(true)
    expect((await submitted.json()).score).toBe(10)

    await teacher.getByRole('navigation').getByRole('button', { name: 'Bài tập', exact: true }).click()
    await expect(teacher.getByRole('navigation', { name: 'Chọn lớp' })).toBeVisible()
    await expect(teacher.getByRole('navigation', { name: 'Lọc theo môn học' })).toHaveCount(0)
    await expect(teacher.getByRole('button', { name: 'Lớp 6', exact: true })).toHaveCount(0)
    await teacher.screenshot({ path: testInfo.outputPath('choose-class-before-subject.png') })
    await teacher.getByRole('button', { name: 'Bài chưa phân lớp', exact: true }).click()
    const card = teacher.getByRole('article').filter({ has: teacher.getByRole('heading', { name: title, exact: true }) })
    await card.getByRole('button', { name: 'Phân lớp & môn', exact: true }).click()
    const dialog = teacher.getByRole('dialog', { name: 'Phân lớp & môn học' })
    await dialog.getByRole('combobox', { name: 'Lớp', exact: true }).selectOption('4')
    await dialog.getByRole('combobox', { name: 'Môn học', exact: true }).selectOption(String(english))
    await dialog.getByRole('button', { name: 'Lưu phân loại', exact: true }).click()
    await expect(dialog).toBeHidden()
    await expect(card).toHaveCount(0)
    await teacher.getByRole('button', { name: 'Lớp 4', exact: true }).click()
    await teacher.getByRole('navigation', { name: 'Lọc theo môn học' }).getByRole('button', { name: 'Tiếng Anh', exact: true }).click()
    await expect(card).toBeVisible()
    await teacher.getByRole('button', { name: 'Lớp 5', exact: true }).click()
    await expect(card).toHaveCount(0)
    await expect(teacher.getByRole('button', { name: 'Tất cả môn học', exact: true })).toHaveAttribute('aria-pressed', 'true')

    await student.getByRole('navigation').getByRole('button', { name: 'Bài tập', exact: true }).click()
    await expect(student.getByRole('navigation', { name: 'Lọc theo môn học' })).toHaveCount(0)
    await student.getByRole('button', { name: 'Lớp 4', exact: true }).click()
    await student.getByRole('navigation', { name: 'Lọc theo môn học' }).getByRole('button', { name: 'Tiếng Anh', exact: true }).click()
    await student.setViewportSize({ width: 390, height: 900 })
    await expect(student.getByRole('navigation', { name: 'Điều hướng chính' })).not.toBeInViewport()
    await expect(student.getByRole('heading', { name: title, exact: true })).toBeVisible()
    expect(await student.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
    await student.screenshot({ path: testInfo.outputPath('class-four-subject-mobile.png') })
    await student.getByRole('article').filter({ has: student.getByRole('heading', { name: title, exact: true }) }).getByRole('button', { name: /Xem bài tập/ }).click()
    await expect(student.getByText(/10\/10/)).toBeVisible()
    const detail = await (await student.request.get('/api/assignments/' + id)).json()
    expect(detail.my_submission.status).toBe('graded')
    expect(Number(detail.my_submission.score)).toBe(10)
    expect(detail.student_ids).toEqual([recipient])
    expect(errors).toEqual([])
  } finally { await teacherContext.close(); await studentContext.close() }
})
