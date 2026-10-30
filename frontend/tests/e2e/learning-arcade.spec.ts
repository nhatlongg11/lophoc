import { test, expect, type Page, type Locator } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import fs from 'node:fs'
import type { ActivityContent, Engine, RoundContent } from '../../src/features/learning-arcade/types'

const fixtures: Partial<Record<Engine, { round: number; assignment: number; content: ActivityContent; title: string }>> = {}
async function login(page: Page, email = 'hocsinh5@eduquest.local') {
  await page.goto('/app/')
  await page.getByLabel('Email', { exact: true }).fill(email)
  await page.getByLabel('Mật khẩu', { exact: true }).fill('MatKhau123!')
  await page.getByRole('button', { name: 'Đăng nhập', exact: true }).click()
  await expect(page.getByRole('heading', { name: /Chào/ })).toBeVisible({ timeout: 20_000 })
}

async function openArcade(page: Page) {
  const navigation = page.getByRole('navigation', { name: 'Điều hướng chính' })
  if (!await navigation.isVisible()) await page.getByRole('button', { name: 'Mở trình đơn', exact: true }).click()
  await navigation.getByRole('button', { name: 'Trò chơi', exact: true }).click()
  await page.getByRole('button', { name: 'Lớp 5', exact: true }).click()
}

test.beforeAll(async ({ request }) => {
  fs.mkdirSync('tests/artifacts', { recursive: true })
  let csrf = (await (await request.get('/api/csrf')).json()).token
  expect((await request.post('/api/login', { headers: { 'X-CSRF-TOKEN': csrf }, data: { email: 'giaovien@lophoc.local', password: 'MatKhau123!' } })).ok()).toBeTruthy()
  csrf = (await (await request.get('/api/csrf')).json()).token
  const subjects = await (await request.get('/api/subjects')).json()
  const students = await (await request.get('/api/users?role=student')).json()
  const templates = await (await request.get('/api/arcade/templates')).json()
  const template = templates.find((t: { id: string }) => t.id === 'unit').content as RoundContent
  for (const activity of template.activities) {
    const title = 'E2E Arcade ' + activity.engine + ' ' + Date.now()
    const content = { ...template, title, subject_id: subjects.find((s: { name: string }) => s.name === 'Tiếng Anh').id, activities: [activity] }
    const saved = await request.post('/api/arcade/rounds', { headers: { 'X-CSRF-TOKEN': csrf }, data: { content, expected_revision: 0 } })
    expect(saved.status()).toBe(201)
    const round = (await saved.json()).id
    expect((await request.post('/api/arcade/rounds/' + round + '/preview', { headers: { 'X-CSRF-TOKEN': csrf } })).ok()).toBeTruthy()
    expect((await request.post('/api/arcade/rounds/' + round + '/publish', { headers: { 'X-CSRF-TOKEN': csrf } })).ok()).toBeTruthy()
    const assigned = await request.post('/api/arcade/rounds/' + round + '/assign', { headers: { 'X-CSRF-TOKEN': csrf }, data: { student_ids: [students.find((s: { email: string }) => s.email === 'hocsinh5@eduquest.local').id], allowed_attempts: 50 } })
    expect(assigned.status()).toBe(201)
    fixtures[activity.engine] = { round, assignment: (await assigned.json()).id, content: activity, title }
  }
})

for (const width of [1440, 390, 320]) {
  for (const engine of ['matching', 'word_builder', 'quick_challenge', 'memory_flip', 'fill_gap'] as const) {
    test(engine + ' completes with keyboard and touch controls at ' + width + 'px', async ({ page }) => {
      test.setTimeout(120_000)
      await page.setViewportSize({ width, height: 1000 })
      await login(page)
      const fixture = fixtures[engine]!
      await openArcade(page)
      await expect(page.getByRole('heading', { name: /cùng khám phá nhé/ })).toBeVisible()
      if (width === 1440 && engine === 'matching') await page.screenshot({ path: 'tests/artifacts/arcade-student-hub.png', fullPage: true })
      const card = page.getByRole('article').filter({ has: page.getByRole('heading', { name: fixture.title, exact: true }) })
      await card.getByRole('button', { name: /Khám phá vòng học|Luyện thêm một lượt/ }).click()
      await page.getByRole('button', { name: 'Bắt đầu chặng đầu tiên' }).click()
      await expect(page.getByRole('heading', { name: fixture.content.title, exact: true })).toBeVisible()
      await expect(page.locator('.arcade-play-surface')).toBeVisible()
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBeTruthy()
      await page.screenshot({ path: 'tests/artifacts/arcade-' + engine + '-' + width + '.png', fullPage: true })
      if (width === 390) expect((await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze()).violations).toEqual([])
      let recover = width === 390
      if (recover) {
        let aborted = false
        await page.route('**/api/arcade/sessions/*/actions', async route => {
          if (!aborted && ['match', 'flip', 'answer'].includes(route.request().postDataJSON()?.action?.kind)) { aborted = true; await route.abort('failed') } else await route.continue()
        })
      }
      async function answer(control: Locator, keyboard = false) {
        const response = page.waitForResponse(r => r.request().method() === 'POST' && r.url().includes('/arcade/sessions/') && r.url().endsWith('/actions') && r.ok())
        if (keyboard) await control.press('Enter'); else await control.click()
        if (recover) {
          const retry = page.getByRole('button', { name: 'Gửi lại thao tác đã giữ' })
          await expect(retry).toBeEnabled()
          await retry.click()
          recover = false
        }
        await response
        await expect(page.locator('.arcade-shell')).toHaveAttribute('aria-busy', 'false')
      }
      if (engine === 'matching') {
        if (width === 320) {
          await page.getByRole('button', { name: 'book', exact: true }).click()
          await page.getByRole('button', { name: 'giáo viên', exact: true }).click()
          await expect(page.getByText('Hai thẻ chưa khớp.', { exact: false })).toBeVisible()
        }
        for (const [index, pair] of fixture.content.pairs!.entries()) {
          const left = page.getByRole('button', { name: pair.left, exact: true }), right = page.getByRole('button', { name: pair.right, exact: true })
          if (index === 0) await left.press('Enter'); else await left.click()
          await answer(right)
          if (index < fixture.content.pairs!.length - 1) await expect(page.getByRole('button', { name: new RegExp('^' + pair.left + '.*Đã ghép') })).toBeDisabled()
        }
      } else if (engine === 'memory_flip') {
        await page.getByRole('checkbox', { name: 'Tự úp thẻ sau khi xem' }).uncheck()
        const known = new Map<number, string>(), related = new Map<string, string>()
        fixture.content.pairs!.forEach(p => { related.set(p.left, p.right); related.set(p.right, p.left) })
        for (let turn = 0; turn < 50; turn++) {
          if (await page.locator('.arcade-round-result').count()) break
          const buttons = page.locator('.arcade-memory-card'), count = await buttons.count(), remaining: number[] = []
          for (let i = 0; i < count; i++) if (!(await buttons.nth(i).getAttribute('class'))?.includes('matched')) remaining.push(i)
          let first = remaining.find(i => !known.has(i)) ?? remaining[0]
          for (const i of remaining) if (known.has(i) && remaining.some(j => j !== i && known.get(j) === related.get(known.get(i)!))) { first = i; break }
          await answer(buttons.nth(first), turn === 0)
          await expect(buttons.nth(first)).toHaveClass(/revealed/)
          const firstText = (await buttons.nth(first).locator('span').first().textContent())!
          known.set(first, firstText)
          const second = remaining.find(i => i !== first && known.get(i) === related.get(firstText)) ?? remaining.find(i => i !== first && !known.has(i)) ?? remaining.find(i => i !== first)!
          await answer(buttons.nth(second))
          if (await page.locator('.arcade-round-result').count()) break
          await expect(buttons.nth(second)).toHaveClass(/revealed/)
          known.set(second, (await buttons.nth(second).locator('span').first().textContent())!)
          if (await page.getByRole('button', { name: 'Úp thẻ để tiếp tục' }).count()) {
            await expect(page.getByRole('button', { name: 'Úp thẻ để tiếp tục' })).toBeEnabled()
            expect(await buttons.evaluateAll(nodes => nodes.every(node => (node as HTMLButtonElement).disabled))).toBeTruthy()
            await page.getByRole('button', { name: 'Úp thẻ để tiếp tục' }).click()
            await expect(buttons.nth(first)).not.toHaveClass(/revealed/)
          } else await expect(buttons.nth(first)).toHaveClass(/matched/)
        }
      } else {
        for (const [index, item] of fixture.content.items!.entries()) {
          if (engine === 'fill_gap') await expect(page.locator('.arcade-gap-sentence')).toContainText(item.prompt.split('___')[0].trim()); else await expect(page.getByRole('heading', { name: item.prompt, exact: true })).toBeVisible()
          if (engine === 'word_builder') {
            const words = item.accepted_orders![0].map(id => item.tokens!.find(t => t.id === id)!.text)
            await page.getByRole('button', { name: 'Thêm từ: ' + words[0], exact: true }).press('Enter')
            if (width === 390 && index === 0) {
              await expect(page.getByText('Đã lưu bản nháp trên máy chủ', { exact: true })).toBeVisible()
              await page.reload()
              await expect(page.locator('.arcade-answer-tray')).toContainText(words[0])
            }
            await page.getByRole('button', { name: 'Bỏ từ 1: ' + words[0], exact: true }).click()
            for (const word of words) await page.getByRole('button', { name: 'Thêm từ: ' + word, exact: true }).click()
            await answer(page.getByRole('button', { name: 'Kiểm tra câu' }))
          } else if (engine === 'quick_challenge' && item.answer_mode === 'choice') await answer(page.getByRole('button', { name: new RegExp('^[A-Z]\\s*' + item.accepted_answers![0].replace(/[.*+?^$()|[\]\\]/g, '\\$&') + '$') }), index === 0)
          else if (engine === 'fill_gap' && item.answer_mode !== 'typed') { await page.getByRole('button', { name: item.accepted_answers![0], exact: true }).click(); await answer(page.getByRole('button', { name: 'Kiểm tra', exact: true })) }
          else { await page.getByLabel(engine === 'fill_gap' ? 'Điền vào chỗ trống' : 'Câu trả lời', { exact: true }).fill(item.accepted_answers![0]); await answer(page.getByRole('button', { name: 'Kiểm tra', exact: true }), index === 0) }
          if (index < fixture.content.items!.length - 1) {
            await expect(page.getByRole('heading', { name: 'Chính xác! Bạn làm tốt lắm!' })).toBeVisible()
            await page.getByRole('button', { name: 'Tiếp tục học' }).click()
          }
        }
      }
      await expect(page.locator('.arcade-round-result')).toBeVisible()
      await expect(page.getByRole('heading', { name: /Hoàn thành xuất sắc|Thêm một bước tiến mới/ })).toBeVisible()
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBeTruthy()
      await page.screenshot({ path: 'tests/artifacts/arcade-result-' + engine + '-' + width + '.png', fullPage: true })
      const sessionId = Number(page.url().split('/').at(-1))
      const persisted = await (await page.request.get('/api/arcade/sessions/' + sessionId)).json()
      expect(persisted.status).toBe('completed')
      expect(persisted.round_result.accuracy).toBeGreaterThan(0)
      expect(persisted.round_result.accuracy).toBeLessThanOrEqual(100)
      if (engine === 'matching' && width === 320) expect(persisted.round_result.accuracy).toBe(80)
      if (engine !== 'memory_flip' && !(engine === 'matching' && width === 320)) expect(persisted.round_result.accuracy).toBe(100)
      const measurements = await page.evaluate(() => ({ navigation: performance.getEntriesByType('navigation').map(e => e.toJSON()), scripts: performance.getEntriesByType('resource').filter(e => (e as PerformanceResourceTiming).initiatorType === 'script').map(e => e.toJSON()) }))
      fs.writeFileSync('tests/artifacts/arcade-performance-' + engine + '-' + width + '.json', JSON.stringify(measurements, null, 2))
    })
  }
}


test('teacher creates, imports, previews, publishes, assigns, duplicates and archives a versioned collection', async ({ page, browser }) => {
  test.setTimeout(150_000)
  await login(page, 'giaovien@lophoc.local')
  await openArcade(page)
  await expect(page.getByRole('heading', { name: 'Một bài học, nhiều cách khám phá.' })).toBeVisible()
  await page.screenshot({ path: 'tests/artifacts/arcade-teacher-collection.png', fullPage: true })
  await page.getByRole('button', { name: 'Tạo vòng học mới' }).click()
  const templates = page.getByRole('dialog', { name: 'Chọn mẫu vòng học' })
  await expect(templates.getByRole('button', { name: /Tổng kết cuối bài/ })).toBeVisible()
  await templates.getByRole('button', { name: /Tổng kết cuối bài/ }).click()
  const title = 'E2E Thiết kế vòng học ' + Date.now()
  await page.getByLabel('Tên vòng học', { exact: true }).fill(title)
  await page.getByLabel('Mục tiêu của vòng học').fill('Luyện tập từ vựng học đường, xếp câu và tự ôn lại những khái niệm cần luyện.')
  await page.getByRole('combobox', { name: 'Ngân hàng câu hỏi', exact: true }).selectOption({ label: '[Mẫu khối 5] Nội dung tương thích Arcade' })
  await page.getByRole('checkbox', { name: 'Thử thách nhanh', exact: true }).check()
  await page.getByRole('button', { name: 'Phân tích & tạo chặng học' }).click()
  await expect(page.getByText('1 chặng tương thích.', { exact: false })).toBeVisible()
  await expect(page.getByText('Gợi ý:', { exact: false }).first()).toBeAttached()
  await page.getByRole('button', { name: 'Lưu bản nháp', exact: true }).click()
  await expect(page.getByText('Đã lưu bản nháp và phiên bản nội dung.', { exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Phát hành phiên bản', exact: true })).toBeDisabled()
  await page.screenshot({ path: 'tests/artifacts/arcade-teacher-builder.png', fullPage: true })
  await page.getByRole('button', { name: 'Xem thử trò chơi', exact: true }).click()
  await expect(page.getByText('GIÁO VIÊN · XEM THỬ', { exact: true })).toBeVisible()
  await page.getByRole('button', { name: 'Bắt đầu chặng đầu tiên' }).click()
  await expect(page.getByRole('button', { name: 'book', exact: true })).toBeVisible()
  await page.getByRole('button', { name: 'Lưu và rời vòng học', exact: true }).click()
  await page.getByRole('dialog').getByRole('button', { name: 'Lưu & rời vòng học', exact: true }).click()
  await expect(page.getByRole('button', { name: 'Phát hành phiên bản', exact: true })).toBeEnabled()
  await page.getByRole('button', { name: 'Phát hành phiên bản', exact: true }).click()
  await expect(page.getByText('Đã phát hành. Thầy cô có thể giao phiên bản này cho học sinh.', { exact: true })).toBeVisible()
  await page.getByRole('button', { name: 'Giao cho học sinh', exact: true }).click()
  const assignment = page.getByRole('dialog', { name: 'Giao vòng học cho học sinh' })
  await assignment.getByRole('checkbox', { name: /hocsinh5@eduquest.local/ }).check()
  await assignment.getByRole('button', { name: 'Xác nhận giao vòng học', exact: true }).click()
  await expect(assignment).toBeHidden()
  const roundId = Number(page.url().split('/').at(-1))
  const detail = await (await page.request.get('/api/arcade/rounds/' + roundId)).json()
  expect(detail.assignments).toHaveLength(1)
  expect(detail.versions).toHaveLength(1)
  const originalVersion = detail.round.published_version_id
  await page.getByLabel('Tên vòng học', { exact: true }).fill(title + ' · bản chỉnh sửa')
  await page.getByRole('button', { name: 'Lưu bản nháp', exact: true }).click()
  await expect(page.getByText('Đã lưu bản nháp và phiên bản nội dung.', { exact: true })).toBeVisible()
  const changed = await (await page.request.get('/api/arcade/rounds/' + roundId)).json()
  expect(changed.versions).toHaveLength(2)
  expect(changed.assignments[0].version_id).toBe(originalVersion)
  const studentContext = await browser.newContext(), student = await studentContext.newPage()
  await login(student)
  await openArcade(student)
  await expect(student.getByRole('heading', { name: title, exact: true })).toBeVisible()
  await expect(student.getByRole('heading', { name: title + ' · bản chỉnh sửa', exact: true })).toHaveCount(0)
  await studentContext.close()
  await page.getByRole('button', { name: 'Về bộ sưu tập' }).click()
  await page.getByLabel('Tìm trong bộ sưu tập').fill(title)
  await page.getByRole('button', { name: 'Tìm vòng học', exact: true }).click()
  const card = page.getByRole('article').filter({ has: page.getByRole('heading', { name: title + ' · bản chỉnh sửa', exact: true }) })
  await card.getByRole('button', { name: 'Xem kết quả', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Kết quả học tập qua trò chơi' })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Tiến bộ của từng học sinh' })).toBeVisible()
  await page.screenshot({ path: 'tests/artifacts/arcade-teacher-report.png', fullPage: true })
  const exportResponse = await page.request.get('/api/arcade/reports/export?round_id=' + roundId)
  expect(exportResponse.ok()).toBeTruthy()
  expect(await exportResponse.text()).toContain('An Nhiên')
  await page.getByRole('button', { name: 'Bộ sưu tập vòng học', exact: true }).click()
  await card.getByRole('button', { name: 'Tạo bản sao', exact: true }).click()
  await expect(page.getByLabel('Tên vòng học', { exact: true })).toHaveValue(title + ' · bản chỉnh sửa (bản sao)')
  await page.getByRole('button', { name: 'Về bộ sưu tập' }).click()
  const copy = page.getByRole('article').filter({ has: page.getByRole('heading', { name: title + ' · bản chỉnh sửa (bản sao)', exact: true }) })
  await copy.getByRole('button', { name: 'Lưu trữ', exact: true }).click()
  await page.getByRole('dialog').getByRole('button', { name: 'Xác nhận lưu trữ', exact: true }).click()
  await expect(copy).toHaveCount(0)
  await page.getByRole('checkbox', { name: 'Bao gồm vòng đã lưu trữ' }).check()
  await expect(copy.getByText('Đã lưu trữ', { exact: true })).toBeVisible()
})
