import { test, expect } from '@playwright/test'

test('an admin reviews requests in the admin screen while pending users cannot be edited', async ({ page }, testInfo) => {
  const users = [{ id: 1, name: 'Quản trị viên', email: 'admin@example.test', role: 'admin', active: true, registration_status: null }, { id: 12, name: 'Nguyễn Như', email: 'nhu@example.test', role: 'teacher', active: false, registration_status: 'pending' }, { id: 13, name: 'Lê Minh', email: 'minh@example.test', role: 'student', active: false, registration_status: 'pending' }]
  const errors: string[] = []
  page.on('pageerror', error => errors.push(error.message))
  await page.route('**/api/**', route => {
    const path = new URL(route.request().url()).pathname
    let body: unknown = []
    if (path === '/api/me') body = users[0]
    else if (path === '/api/csrf') body = { token: 'test-csrf' }
    else if (path === '/api/dashboard') body = { students: 0, assignments: 0, submissions: 0, notifications: 0, upcoming_lessons: [], due_assignments: [] }
    else if (path === '/api/reports') body = null
    else if (path === '/api/users') body = users
    else if (path === '/api/settings') body = {}
    else if (path === '/api/registrations') body = users.filter(user => user.registration_status === 'pending').map(user => ({ ...user, created_at: '2026-10-21T08:00:00Z' }))
    else if (/\/registrations\/\d+\/review$/.test(path)) {
      const user = users.find(user => user.id === Number(path.split('/')[3]))!
      user.registration_status = route.request().postDataJSON().decision
      user.active = user.registration_status === 'approved'
      body = { message: user.active ? 'Đã duyệt tài khoản.' : 'Đã từ chối yêu cầu đăng ký.' }
    }
    return route.fulfill({ json: body })
  })
  await page.goto('/app/')
  await page.getByRole('navigation').getByRole('button', { name: 'Quản trị hệ thống', exact: true }).click()
  const pending = page.getByRole('row').filter({ hasText: 'nhu@example.test' })
  await expect(pending.getByText('Chờ duyệt', { exact: true })).toBeVisible()
  await expect(pending.getByRole('button', { name: 'Sửa', exact: true })).toHaveCount(0)
  await page.getByRole('button', { name: 'Duyệt đăng ký', exact: true }).click()
  await expect(page.getByRole('button', { name: 'Thêm mới', exact: true })).toHaveCount(0)
  await page.screenshot({ path: testInfo.outputPath('registration-queue-desktop.png') })
  await page.getByRole('row').filter({ hasText: 'nhu@example.test' }).getByRole('button', { name: 'Chấp nhận', exact: true }).click()
  await expect(page.getByRole('dialog', { name: 'Chấp nhận đăng ký?' })).toBeVisible()
  await page.getByRole('button', { name: 'Xác nhận', exact: true }).click()
  await expect(page.getByRole('row').filter({ hasText: 'nhu@example.test' })).toHaveCount(0)
  await page.getByRole('button', { name: 'Tài khoản', exact: true }).click()
  await expect(pending.getByText('Hoạt động', { exact: true })).toBeVisible()
  await page.getByRole('button', { name: 'Duyệt đăng ký', exact: true }).click()
  await page.setViewportSize({ width: 390, height: 900 })
  await page.getByRole('row').filter({ hasText: 'minh@example.test' }).getByRole('button', { name: 'Từ chối', exact: true }).click()
  await expect(page.getByRole('dialog', { name: 'Từ chối đăng ký?' })).toBeVisible()
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
  await page.screenshot({ path: testInfo.outputPath('registration-confirm-mobile.png') })
  await page.getByRole('button', { name: 'Xác nhận', exact: true }).click()
  await expect(page.getByText('Không có yêu cầu đăng ký đang chờ duyệt.')).toBeVisible()
  expect(errors).toEqual([])
})
