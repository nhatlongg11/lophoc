import { test, expect } from '@playwright/test'

test.use({ channel: 'chromium' })

test('closed registration stays hidden even when opened directly', async ({ page }) => {
  await page.route('**/api/me', route => route.fulfill({ status: 401, json: {} }))
  await page.route('**/api/registration/status', route => route.fulfill({ json: { enabled: false, approval_required: true } }))
  await page.goto('/app/?register=1')
  await expect(page.getByRole('button', { name: 'Đăng nhập', exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Chưa có tài khoản? Đăng ký', exact: true })).toHaveCount(0)
  await expect(page.getByLabel('Họ và tên', { exact: true })).toHaveCount(0)
})

for (const role of ['student', 'teacher', 'parent']) {
  test('an enabled registration form submits a ' + role + ' request without automatic login', async ({ page }) => {
    let logins = 0
    await page.route('**/api/me', route => route.fulfill({ status: 401, json: {} }))
    await page.route('**/api/csrf', route => route.fulfill({ json: { token: 'browser-csrf' } }))
    page.on('request', request => { if (request.url().endsWith('/api/login')) logins++ })
    await page.route('**/api/registration/status', route => route.fulfill({ json: { enabled: true, approval_required: true } }))
    await page.route('**/api/register', route => {
      expect(route.request().postDataJSON().role).toBe(role)
      return route.fulfill({ status: 201, json: { message: 'Đã gửi yêu cầu đăng ký. Vui lòng chờ quản trị viên duyệt trước khi đăng nhập.', status: 'pending' } })
    })
    await page.goto('/app/')
    await page.getByRole('button', { name: 'Chưa có tài khoản? Đăng ký', exact: true }).click()
    await page.getByLabel('Họ và tên', { exact: true }).fill('Như ' + role)
    await page.getByLabel('Email', { exact: true }).fill('browser-' + role + '@example.test')
    await page.getByLabel('Mật khẩu', { exact: true }).fill('Registration123!')
    await page.getByLabel('Nhập lại mật khẩu', { exact: true }).fill('Registration123!')
    await page.getByRole('combobox', { name: 'Vai trò', exact: true }).selectOption(role)
    if (role === 'parent') await page.getByLabel('PIN phụ huynh (tài khoản gia đình)', { exact: true }).fill('629184')
    await page.getByRole('button', { name: 'Gửi yêu cầu đăng ký', exact: true }).click()
    await expect(page.getByRole('status')).toContainText('Vui lòng chờ quản trị viên duyệt')
    await expect(page.getByRole('button', { name: 'Đăng nhập', exact: true })).toBeVisible()
    expect(logins).toBe(0)
  })
}

test('a material preview renders on HTTPS behind an HTTP proxy and reports rejected links', async ({ page, context, baseURL }) => {
  test.setTimeout(90_000)
  const publicOrigin = 'https://public.eduquest.test'
  await context.route(publicOrigin + '/**', async route => {
    const incoming = new URL(route.request().url())
    const response = await route.fetch({ url: baseURL + incoming.pathname + incoming.search, postData: route.request().postDataBuffer() ?? undefined })
    await route.fulfill({ response })
  })
  async function loginTeacher(origin: string) {
    await page.goto(origin + '/app/')
    await page.getByLabel('Email', { exact: true }).fill('giaovien@lophoc.local')
    await page.getByLabel('Mật khẩu', { exact: true }).fill('MatKhau123!')
    await page.getByRole('button', { name: 'Đăng nhập', exact: true }).click()
    await expect(page.getByRole('heading', { name: /Chào/ })).toBeVisible()
  }
  await loginTeacher(baseURL!)
  await page.getByRole('navigation').getByRole('button', { name: 'Kho tài liệu', exact: true }).click()
  await page.getByRole('button', { name: 'Thêm học liệu', exact: true }).click()
  const title = 'HTTPS cat ' + Date.now(), upload = page.getByRole('dialog')
  await upload.getByLabel('Tiêu đề tài liệu').fill(title)
  await upload.getByLabel('Tệp PDF, DOCX, ảnh hoặc định dạng được hỗ trợ').setInputFiles('public/img/logo-transparent.png')
  await upload.getByRole('button', { name: 'Lưu tài liệu', exact: true }).click()
  await expect(upload).toBeHidden()
  await context.clearCookies()
  await loginTeacher(publicOrigin)
  await page.getByRole('navigation').getByRole('button', { name: 'Kho tài liệu', exact: true }).click()
  const card = page.locator('article').filter({ has: page.getByRole('heading', { name: title, exact: true }) })
  const access = page.waitForResponse(response => /\/files\/\d+\/access$/.test(response.url()))
  await card.getByRole('button', { name: 'Xem tài liệu', exact: true }).click()
  const signed = (await (await access).json()).url
  expect(signed).toMatch(/^\/api\/file-preview\/\d+\?expires=/)
  const image = page.getByRole('dialog').getByAltText(title, { exact: true })
  await expect(image).toBeVisible()
  await expect.poll(() => image.evaluate((element: HTMLImageElement) => element.naturalWidth)).toBeGreaterThan(0)
  await expect(image).toHaveAttribute('src', /^blob:https:\/\/public\.eduquest\.test\//)
  await page.getByRole('button', { name: 'Đóng hộp thoại', exact: true }).click()
  await page.route('**/api/file-preview/**', route => route.fulfill({ status: 403, contentType: 'application/json', body: JSON.stringify({ message: 'Liên kết xem tài liệu đã hết hạn.' }) }))
  await card.getByRole('button', { name: 'Xem tài liệu', exact: true }).click()
  await expect(page.getByRole('dialog').getByRole('alert')).toHaveText('Liên kết xem tài liệu đã hết hạn.')
  await expect(page.getByRole('dialog').getByRole('img')).toHaveCount(0)
})

test('an uploaded PDF opens its document viewer', async ({ page }) => {
  const content = 'BT /F1 18 Tf 40 160 Td (EduQuest PDF preview) Tj ET\n'
  const objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>', '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 200] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>', '<< /Length ' + Buffer.byteLength(content) + ' >>\nstream\n' + content + 'endstream', '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>']
  let pdf = '%PDF-1.4\n'
  const offsets = [0]
  objects.forEach((object, index) => { offsets.push(Buffer.byteLength(pdf)); pdf += (index + 1) + ' 0 obj\n' + object + '\nendobj\n' })
  const xref = Buffer.byteLength(pdf)
  pdf += 'xref\n0 6\n0000000000 65535 f \n' + offsets.slice(1).map(offset => String(offset).padStart(10, '0') + ' 00000 n \n').join('') + 'trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n' + xref + '\n%%EOF\n'
  await page.goto('/app/')
  await page.getByLabel('Email', { exact: true }).fill('giaovien@lophoc.local')
  await page.getByLabel('Mật khẩu', { exact: true }).fill('MatKhau123!')
  await page.getByRole('button', { name: 'Đăng nhập', exact: true }).click()
  await expect(page.getByRole('heading', { name: /Chào/ })).toBeVisible()
  const title = 'PDF preview ' + Date.now()
  const token = (await (await page.request.get('/api/csrf')).json()).token
  const upload = await page.request.post('/api/library', { headers: { 'X-CSRF-TOKEN': token }, multipart: { name: title, file: { name: 'preview.pdf', mimeType: 'application/pdf', buffer: Buffer.from(pdf) } } })
  expect(upload.ok(), await upload.text()).toBeTruthy()
  await page.getByRole('navigation').getByRole('button', { name: 'Kho tài liệu', exact: true }).click()
  const errors: string[] = []
  page.on('console', message => { if (message.type() === 'error' && /sandbox|plugin|PDF/i.test(message.text())) errors.push(message.text()) })
  await page.locator('article').filter({ has: page.getByRole('heading', { name: title, exact: true }) }).getByRole('button', { name: 'Xem tài liệu', exact: true }).click()
  const frame = page.getByRole('dialog').locator('iframe')
  await expect(frame).toBeVisible()
  await expect(frame).toHaveAttribute('src', /^blob:/)
  const source = await frame.getAttribute('src')
  await expect.poll(() => page.frames().some(child => child.parentFrame()?.url() === source)).toBe(true)
  const viewer = page.frames().find(child => child.parentFrame()?.url() === source)!
  await expect(viewer.locator('pdf-viewer')).toBeVisible()
  expect(errors).toEqual([])
  await page.getByRole('button', { name: 'Đóng hộp thoại', exact: true }).click()
  await page.route('**/api/file-preview/**', route => route.fulfill({ status: 200, contentType: 'text/html', body: '<p>Unexpected HTML instead of a PDF</p>' }))
  await page.locator('article').filter({ has: page.getByRole('heading', { name: title, exact: true }) }).getByRole('button', { name: 'Xem tài liệu', exact: true }).click()
  await expect(page.getByRole('dialog').getByRole('alert')).toContainText('không đúng định dạng tài liệu')
  await expect(page.getByRole('dialog').locator('iframe')).toHaveCount(0)
})
