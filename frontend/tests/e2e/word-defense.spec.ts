import { test, expect, type Page } from '@playwright/test'
import fs from 'node:fs'
import type { RoundContent, Session } from '../../src/features/learning-arcade/types'

test.use({ hasTouch: true })
async function openBattle(page: Page, student = false, expectAssets = true) {
  let csrf = (await (await page.request.get('/api/csrf')).json()).token
  expect((await page.request.post('/api/login', { headers: { 'X-CSRF-TOKEN': csrf }, data: { email: 'giaovien@lophoc.local', password: 'MatKhau123!' } })).ok()).toBeTruthy()
  csrf = (await (await page.request.get('/api/csrf')).json()).token
  const templates = await (await page.request.get('/api/arcade/templates')).json()
  const content = structuredClone(templates.find((t: { id: string }) => t.id === 'word-defense').content) as RoundContent
  const subjects = await (await page.request.get('/api/subjects')).json()
  content.subject_id = subjects.find((s: { name: string }) => s.name === 'Tiếng Anh').id
  content.title = 'Word Defense browser ' + Date.now()
  content.activities = [content.activities[0]]
  const headers = { 'X-CSRF-TOKEN': csrf }
  const saved = await page.request.post('/api/arcade/rounds', { headers, data: { content, expected_revision: 0 } })
  expect(saved.status()).toBe(201)
  const id = (await saved.json()).id
  let session = await (await page.request.post('/api/arcade/rounds/' + id + '/preview', { headers })).json() as Session
  if (student) {
    expect((await page.request.post('/api/arcade/rounds/' + id + '/publish', { headers })).ok()).toBeTruthy()
    const students = await (await page.request.get('/api/users?role=student')).json()
    const assigned = await page.request.post('/api/arcade/rounds/' + id + '/assign', { headers, data: { student_ids: [students.find((s: { email: string }) => s.email === 'hocsinh5@eduquest.local').id], allowed_attempts: 5 } })
    expect(assigned.status()).toBe(201)
    const assignment = (await assigned.json()).id
    expect((await page.request.post('/api/logout', { headers })).ok()).toBeTruthy()
    csrf = (await (await page.request.get('/api/csrf')).json()).token
    expect((await page.request.post('/api/login', { headers: { 'X-CSRF-TOKEN': csrf }, data: { email: 'hocsinh5@eduquest.local', password: 'MatKhau123!' } })).ok()).toBeTruthy()
    csrf = (await (await page.request.get('/api/csrf')).json()).token
    session = await (await page.request.post('/api/arcade/rounds/' + id + '/start', { headers: { 'X-CSRF-TOKEN': csrf }, data: { assignment_id: assignment } })).json() as Session
  }
  await page.goto('/app/#arcade/play/' + session.id)
  await page.getByRole('button', { name: 'Bắt đầu chặng đầu tiên' }).tap()
  if (expectAssets) await expect(page.getByRole('button', { name: 'Bắt đầu bảo vệ' })).toBeVisible()
  return { session, content }
}

for (const width of [320, 390, 430, 1024]) {
  test('Kenney assets, touch deployment, movement and pause at ' + width + 'px', async ({ page }) => {
    test.setTimeout(90_000)
    await page.setViewportSize({ width, height: 844 })
    const errors: string[] = []
    page.on('pageerror', e => errors.push(e.message))
    const atlas = page.waitForResponse(r => r.url().includes('kenney-atlas') && r.status() === 200)
    const { session } = await openBattle(page)
    await atlas
    const game = page.getByTestId('word-defense'), canvas = game.locator('canvas')
    await page.getByRole('button', { name: 'Bắt đầu bảo vệ' }).tap()
    await expect.poll(async () => Number(await game.getAttribute('data-time'))).toBeGreaterThanOrEqual(3)
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy()
    // WebGL clears its drawing buffer after presentation; inspect the displayed canvas.
    const battlefieldImage = await canvas.screenshot()
    expect(battlefieldImage.byteLength).toBeGreaterThan(10_000)
    for (const control of await game.locator('button').all()) {
      const box = await control.boundingBox(); if (box) expect(box.height).toBeGreaterThanOrEqual(44)
    }
    await expect(canvas).toHaveAttribute('data-engine', 'phaser-3')
    const firstPosition = JSON.parse((await canvas.getAttribute('data-enemies')) || '[]')[0]
    await expect.poll(async () => JSON.parse((await canvas.getAttribute('data-enemies')) || '[]').find((enemy: { id: number }) => enemy.id === firstPosition.id)?.y || 0).toBeGreaterThan(firstPosition.y + 2)
    await game.getByRole('button', { name: /^book ·/ }).tap()
    const battlefieldBox = await canvas.boundingBox()
    await canvas.tap({ position: { x: battlefieldBox!.width * 78 / 420, y: battlefieldBox!.height * 386 / 500 } })
    await expect.poll(async () => Number(await game.getAttribute('data-kills')), { timeout: 20_000 }).toBeGreaterThan(0)
    await page.evaluate(() => scrollTo(0, 0))
    fs.mkdirSync('tests/artifacts', { recursive: true })
    await page.screenshot({ path: 'tests/artifacts/word-defense-active-' + width + '.png', fullPage: true })
    await page.getByRole('button', { name: 'Tạm dừng', exact: true }).tap()
    await expect(page.getByRole('button', { name: 'Tiếp tục trận đấu' })).toBeVisible({ timeout: 20_000 })
    const paused = Number(await game.getAttribute('data-time'))
    await page.waitForTimeout(1000)
    expect(Number(await game.getAttribute('data-time'))).toBe(paused)
    fs.mkdirSync('tests/artifacts', { recursive: true })
    await page.screenshot({ path: 'tests/artifacts/word-defense-' + width + '.png', fullPage: true })
    const metrics = { width, canvasScreenshotBytes: battlefieldImage.byteLength, frameWorkP95Ms: await canvas.getAttribute('data-frame-work-p95-ms'), errors }
    fs.writeFileSync('tests/artifacts/word-defense-' + width + '-metrics.json', JSON.stringify(metrics, null, 2))
    expect(errors).toEqual([])
    await page.reload()
    await expect(page.getByRole('button', { name: 'Tiếp tục trận đấu' })).toBeVisible()
    const restored = await (await page.request.get('/api/arcade/sessions/' + session.id)).json()
    expect(restored.current_activity.battle.paused).toBeTruthy()
    expect(restored.current_activity.battle.kills).toBeGreaterThan(0)
  })
}

test('a student wins a complete live match and results survive reload', async ({ page }) => {
  test.setTimeout(100_000)
  await page.setViewportSize({ width: 390, height: 844 })
  const { session, content } = await openBattle(page, true)
  await page.getByRole('button', { name: 'Bắt đầu bảo vệ' }).tap()
  let projectileSeen = false, latest: Session = session
  page.on('response', async response => {
    if (!response.url().endsWith('/actions') || !response.ok()) return
    try { latest = await response.json(); projectileSeen ||= (latest.current_activity?.battle?.projectiles.length || 0) > 0 } catch { /* A navigation can dispose a response. */ }
  })
  const finish = Date.now() + 75_000
  while (Date.now() < finish && !await page.locator('.arcade-round-result').count()) {
    const b = latest.current_activity?.battle
    if (b && !b.paused) {
      for (let lane = 0; lane < 3; lane++) {
        const enemy = b.enemies.filter(e => e.lane === lane).sort((a, c) => c.position - a.position)[0]
        if (!enemy) continue
        const pair = content.activities[0].pairs!.find(p => p.right === enemy.clue)!
        const tower = b.towers.find(t => t.lane === lane)
        const definition = b.cards.find(card => card.text === pair.left)!
        if ((!tower || tower.text !== pair.left || tower.until - b.time < 1) && b.energy >= definition.cost && (b.card_ready[definition.id] || 0) <= b.time) {
          const card = page.getByRole('button', { name: new RegExp('^' + pair.left + ' ·') })
          if (await card.isEnabled()) {
            await card.tap()
            const control = page.getByRole('button', { name: 'Đặt tháp đường ' + (lane + 1), exact: true })
            if (await control.isEnabled()) await control.tap()
          }
        }
      }
    }
    await page.waitForTimeout(250)
  }
  await expect(page.getByRole('heading', { name: 'Chiến thắng · Thư viện an toàn!' })).toBeVisible()
  expect(projectileSeen).toBeTruthy()
  expect(latest.round_result?.results[0].battle?.status).toBe('won')
  expect(latest.round_result?.xp_awarded).toBeGreaterThan(0)
  await page.screenshot({ path: 'tests/artifacts/word-defense-victory.png', fullPage: true })
  await page.reload()
  await expect(page.getByRole('heading', { name: 'Chiến thắng · Thư viện an toàn!' })).toBeVisible()
})

test('missing atlas has an actionable loading failure and never starts a blank battlefield', async ({ page }) => {
  await page.route('**/*kenney-atlas*.webp', route => route.abort())
  await openBattle(page, false, false)
  await expect(page.getByRole('button', { name: 'Tải lại hình ảnh' })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Bắt đầu bảo vệ' })).toHaveCount(0)
})

test('Phaser slot input, ice shield, mute and exit clean up the live battlefield', async ({ page }) => {
  test.setTimeout(60_000)
  await page.setViewportSize({ width: 390, height: 844 })
  await openBattle(page)
  const game = page.getByTestId('word-defense'), canvas = game.locator('canvas')
  await page.getByRole('button', { name: 'Bắt đầu bảo vệ' }).tap()
  await game.getByRole('button', { name: /^book ·/ }).tap()
  const box = await canvas.boundingBox()
  await canvas.tap({ position: { x: box!.width * 78 / 420, y: box!.height * 386 / 500 } })
  await expect(canvas).toHaveAttribute('data-towers', '1')
  await game.getByRole('button', { name: /^Khiên băng/ }).tap()
  await expect(canvas).toHaveAttribute('data-frozen', 'true')
  await page.getByRole('button', { name: 'Bật âm thanh phản hồi' }).tap()
  await expect(page.getByRole('button', { name: 'Tắt âm thanh phản hồi' })).toBeVisible()
  await page.getByRole('button', { name: 'Lưu và rời vòng học' }).tap()
  await page.getByRole('dialog').getByRole('button', { name: 'Lưu & rời vòng học' }).tap()
  await expect(page.locator('.wd-phaser canvas')).toHaveCount(0)
  let requests = 0
  page.on('request', request => { if (request.url().endsWith('/actions')) requests++ })
  await page.waitForTimeout(1000)
  const settled = requests
  await page.waitForTimeout(1000)
  expect(requests).toBe(settled)
})

test('portrait rendering stays lightweight under a throttled desktop CPU', async ({ page, browserName }) => {
  test.skip(browserName !== 'chromium', 'CPU throttling measurement uses Chromium CDP; WebKit has separate compatibility coverage.')
  await page.setViewportSize({ width: 390, height: 844 })
  const cdp = await page.context().newCDPSession(page)
  await cdp.send('Emulation.setCPUThrottlingRate', { rate: 4 })
  await openBattle(page)
  await page.getByRole('button', { name: 'Bắt đầu bảo vệ' }).tap()
  const game = page.getByTestId('word-defense')
  await expect.poll(async () => Number(await game.getAttribute('data-time'))).toBeGreaterThan(4)
  const timing = await page.evaluate(async () => {
    const intervals: number[] = []
    let last = performance.now()
    await new Promise<void>(resolve => {
      function sample(now: number) { intervals.push(now - last); last = now; if (intervals.length >= 120) resolve(); else requestAnimationFrame(sample) }
      requestAnimationFrame(sample)
    })
    intervals.shift(); intervals.sort((a, b) => a - b)
    return { medianFrameMs: intervals[Math.floor(intervals.length / 2)], p95FrameMs: intervals[Math.floor(intervals.length * .95)], framesOver50Ms: intervals.filter(n => n > 50).length }
  })
  const frameWorkP95Ms = Number(await game.locator('canvas').getAttribute('data-frame-work-p95-ms'))
  fs.mkdirSync('tests/artifacts', { recursive: true })
  fs.writeFileSync('tests/artifacts/word-defense-cpu4-metrics.json', JSON.stringify({ environment: 'Desktop Chromium, 4x CPU throttle, 390x844 viewport; not a physical device measurement', ...timing, frameWorkP95Ms }, null, 2))
  // This includes the entire Phaser step and renderer, not only a Canvas draw call.
  expect(frameWorkP95Ms).toBeGreaterThan(0)
  expect(frameWorkP95Ms).toBeLessThan(20)
  expect(timing.p95FrameMs).toBeLessThan(50)
})

test('an undefended live battlefield ends in defeat and shows restart', async ({ page }) => {
  test.setTimeout(100_000)
  await page.setViewportSize({ width: 390, height: 844 })
  await openBattle(page)
  await page.getByRole('button', { name: 'Bắt đầu bảo vệ' }).tap()
  await expect(page.getByRole('heading', { name: 'Trận đấu kết thúc · Thử chiến thuật mới nhé!' })).toBeVisible({ timeout: 85_000 })
  await expect(page.getByRole('button', { name: 'Thử lại nếu còn lượt' })).toBeVisible()
  await page.screenshot({ path: 'tests/artifacts/word-defense-defeat.png', fullPage: true })
})

test('teacher edits vocabulary and battle type then previews the saved game', async ({ page }) => {
  const { session } = await openBattle(page)
  await page.goto('/app/#arcade/builder/' + session.round_id)
  await page.getByLabel('Kiểu trận đấu', { exact: false }).selectOption('5')
  await page.getByLabel('Khái niệm 1', { exact: true }).fill('journal')
  await page.getByLabel('Nghĩa hoặc kết quả 1', { exact: true }).fill('nhật ký')
  await page.getByRole('button', { name: 'Lưu bản nháp', exact: true }).click()
  await expect(page.getByText('Bản nháp v2', { exact: true })).toBeVisible()
  await page.getByRole('button', { name: 'Xem thử trò chơi', exact: true }).click()
  await page.getByRole('button', { name: 'Bắt đầu chặng đầu tiên' }).tap()
  await expect(page.getByRole('button', { name: 'Bắt đầu bảo vệ' })).toBeVisible()
  await expect(page.getByRole('button', { name: /^journal ·/ })).toBeVisible()
  await expect(page.getByText('Trùm dựng khiên', { exact: false })).toBeVisible()
})
