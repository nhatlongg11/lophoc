import Phaser from 'phaser'
import { atlasFrames, atlasUrl, textureKey, type SpriteName } from './assets'
import { DefenseBridge, predictedPosition, predictionSeconds, type DefenseFrame } from './bridge'
import { DefenseSound } from './sound'
import type { Battle, BattleEffect, Enemy, TowerKind } from './types'

export const WIDTH = 420, HEIGHT = 500
const INK = 0x26382f, PAPER = 0xfff8df
const laneX = (lane: number) => 78 + lane * 132
const enemyY = (position: number) => 142 + position * 228
const COLORS: Record<TowerKind, number> = { scribe: 0xffdd83, spark: 0xaedcf5, cannon: 0xe4b8ed }
const TOWERS: Record<TowerKind, SpriteName> = { scribe: 'tower', spark: 'towerUpgrade', cannon: 'boss' }
const font = '"Be Vietnam Pro", system-ui, sans-serif'

function label(scene: Phaser.Scene, x: number, y: number, text: string, size = 14, color = '#26382f') {
  return scene.add.text(x, y, text, { fontFamily: font, fontSize: size, fontStyle: 'bold', color, align: 'center', resolution: 2 }).setOrigin(.5)
}
function sprite(scene: Phaser.Scene, frame: SpriteName, x: number, y: number, size: number) {
  const image = scene.add.image(x, y, textureKey, frame)
  return image.setScale(size / Math.max(image.width, image.height))
}

export class DefenseBoot extends Phaser.Scene {
  constructor(private bridge: DefenseBridge, private reducedMotion: boolean) { super('DefenseBoot') }
  preload() {
    this.load.atlas(textureKey, atlasUrl, { frames: atlasFrames })
    this.load.once('loaderror', () => this.bridge.fail('Không tải được bộ ảnh Kenney. Kiểm tra kết nối rồi thử lại.'))
  }
  create() {
    if (!this.textures.exists(textureKey)) return
    this.scene.add('DefenseBattle', new DefenseBattle(this.bridge, this.reducedMotion), true)
    this.scene.add('DefenseHUD', new DefenseHUD(this.bridge), true)
    this.scene.stop()
    this.bridge.ready()
  }
}

type EnemyView = { root: Phaser.GameObjects.Container; body: Phaser.GameObjects.Image; clue: Phaser.GameObjects.Text; bar: Phaser.GameObjects.Graphics; shield: Phaser.GameObjects.Image; data: Enemy }
type TowerView = { root: Phaser.GameObjects.Container; body: Phaser.GameObjects.Image; title: Phaser.GameObjects.Text; timer: Phaser.GameObjects.Graphics; key: string }
type Shot = { image: Phaser.GameObjects.Image; target: number; lane: number; elapsed: number; from: number; to: number }

export class DefenseBattle extends Phaser.Scene {
  private enemies = new Map<number, EnemyView>()
  private enemyPool: EnemyView[] = []
  private towers = new Map<number, TowerView>()
  private shots: Shot[] = []
  private shotPool: Phaser.GameObjects.Image[] = []
  private seen = new Set<number>()
  private selection!: Phaser.GameObjects.Graphics
  private freeze!: Phaser.GameObjects.Rectangle
  private coreShield!: Phaser.GameObjects.Rectangle
  private core!: Phaser.GameObjects.Container
  private lastBattle: Battle | null = null
  private unsubscribe?: () => void
  private cues = new DefenseSound()
  private frames = 0
  private timings: number[] = []
  private updateStarted = 0
  private measureStart = () => { this.updateStarted = performance.now() }
  private measureEnd = () => {
    this.timings.push(performance.now() - this.updateStarted)
    if (this.timings.length > 120) this.timings.shift()
  }
  constructor(private bridge: DefenseBridge, private reducedMotion: boolean) { super('DefenseBattle') }
  create() {
    this.buildTerrain()
    // Bake Kenney scenery once; retain only animated entities on the display list.
    const scenery = this.children.list.filter(object => object !== this.core)
    const terrain = this.add.renderTexture(0, 0, WIDTH, HEIGHT).setOrigin(0).setDepth(0)
    terrain.draw(scenery)
    scenery.forEach(object => object.destroy())
    this.selection = this.add.graphics().setDepth(3)
    this.freeze = this.add.rectangle(WIDTH / 2, 262, WIDTH, 300, 0x99ddff, .24).setDepth(4).setVisible(false)
    this.coreShield = this.add.rectangle(WIDTH / 2, 428, 394, 7, 0x9cddff).setStrokeStyle(2, 0xffffff).setVisible(false).setDepth(16)
    this.input.on('pointerdown', (pointer: Phaser.Input.Pointer) => {
      this.cues.unlock()
      if (pointer.y < 112 || pointer.y > 430) return
      const lane = Phaser.Math.Clamp(Math.round((pointer.x - 78) / 132), 0, 2)
      this.bridge.command({ kind: 'defense_deploy', fields: { lane, spell_id: this.bridge.frame.selected } })
    })
    this.unsubscribe = this.bridge.subscribe(frame => this.accept(frame))
    this.game.events.on(Phaser.Core.Events.PRE_STEP, this.measureStart)
    this.game.events.on(Phaser.Core.Events.POST_RENDER, this.measureEnd)
    this.events.once(Phaser.Scenes.Events.SHUTDOWN, () => {
      this.unsubscribe?.(); this.cues.destroy()
      this.game.events.off(Phaser.Core.Events.PRE_STEP, this.measureStart)
      this.game.events.off(Phaser.Core.Events.POST_RENDER, this.measureEnd)
      this.enemies.clear(); this.towers.clear(); this.enemyPool = []; this.shots = []; this.shotPool = []
    })
    this.accept(this.bridge.frame)
  }
  private buildTerrain() {
    for (let y = 88; y < HEIGHT; y += 36) for (let x = 0; x < WIDTH; x += 36) {
      sprite(this, 'grass', x + 18, y + 18, 36).setTint((x + y) % 72 ? 0xf3f6d2 : 0xffffff)
    }
    this.add.rectangle(210, 92, WIDTH, 30, 0x29473b)
    label(this, 210, 92, '↓  BÓNG TỐI ĐANG ĐẾN  ↓', 11, '#f8e9ae')
    for (let lane = 0; lane < 3; lane++) {
      const x = laneX(lane)
      for (let y = 116; y < 404; y += 32) {
        sprite(this, 'pathLeft', x - 32, y, 32)
        sprite(this, 'path', x, y, 32)
        sprite(this, 'pathRight', x + 32, y, 32)
      }
      this.add.rectangle(x, 387, 100, 66, 0x213e31, .2).setStrokeStyle(2, 0xfff8d6, .65)
      sprite(this, 'towerBase', x, 388, 64)
      label(this, x, 388, '+', 30, '#fff7dd').setAlpha(.85)
      label(this, x, 424, String(lane + 1), 11, '#fff8df').setBackgroundColor('#30483a').setPadding(9, 3)
    }
    for (const [x, y, s] of [[10, 140, 52], [414, 158, 52], [13, 275, 46], [410, 345, 56], [16, 415, 42]]) sprite(this, 'tree', x, y, s).setDepth(1)
    for (const [x, y] of [[145, 176], [277, 278], [146, 364]]) { sprite(this, 'bush', x, y, 22); sprite(this, 'rock', x - 5, y + 48, 15) }
    this.add.rectangle(210, 467, WIDTH, 66, 0x29473b).setDepth(9)
    const archive = sprite(this, 'archive', 0, 0, 49)
    this.core = this.add.container(210, 459, [archive]).setDepth(11)
    sprite(this, 'archive', 70, 459, 37).setDepth(10)
    sprite(this, 'archive', 350, 459, 37).setDepth(10)
    label(this, 210, 490, 'THƯ VIỆN TRI THỨC · GIỮ VỮNG!', 11, '#fff0c0').setDepth(12)
  }
  private accept(frame: DefenseFrame) {
    const b = frame.battle
    this.cues.muted = frame.muted
    const stopped = frame.blocked || b.paused
    if (stopped) this.tweens.pauseAll(); else this.tweens.resumeAll()
    this.selection.clear()
    if (frame.selected && !stopped) {
      for (let lane = 0; lane < 3; lane++) this.selection.lineStyle(lane === frame.lane ? 3 : 2, 0xffed99, lane === frame.lane ? 1 : .55).strokeRoundedRect(laneX(lane) - 46, 357, 92, 62, 12)
    }
    if (this.lastBattle === b) return
    const previous = this.lastBattle
    this.syncEnemies(b)
    this.syncTowers(b)
    for (const effect of b.effects) {
      if (this.seen.has(effect.id)) continue
      this.seen.add(effect.id)
      if (effect.kind === 'shot') this.fire(effect, b.time)
      else this.impact(effect)
    }
    // Effects are short-lived; keep the deduplication set bounded across long sessions.
    if (this.seen.size > 500) this.seen = new Set(b.effects.map(effect => effect.id))
    if (previous && b.health < previous.health) {
      if (!this.reducedMotion) this.cameras.main.shake(170, .006)
      this.core.setAlpha(.4); this.tweens.add({ targets: this.core, alpha: 1, duration: 250 })
      this.cues.play('leak')
    }
    if (previous && b.freeze_until > previous.freeze_until) { this.burst(210, 300, 0xb9efff, 22); this.cues.play('ice') }
    if ((!previous || previous.wave !== b.wave || previous.paused && !b.paused && b.time === 0) && !b.paused) {
      this.announce('ĐỢT ' + b.wave + ' / ' + b.waves, b.wave === b.waves ? 'Đợt cuối · Giữ vững phòng tuyến!' : 'Đọc nghĩa · Đặt tháp · Bảo vệ thư viện', 0xffe4a0)
    }
    if (b.enemies.some(enemy => enemy.kind === 'boss') && !previous?.enemies.some(enemy => enemy.kind === 'boss')) {
      this.announce('TRÙM XUẤT HIỆN', 'Phá khiên · Chặn quân tiếp viện', 0xffb7b1)
      if (!this.reducedMotion) this.cameras.main.shake(220, .004)
    }
    if (b.status !== 'playing' && previous?.status === 'playing') this.announce(b.status === 'won' ? 'THƯ VIỆN AN TOÀN!' : 'PHÒNG TUYẾN THẤT THỦ', 'Đang lưu kết quả học tập…', b.status === 'won' ? 0xcbeaad : 0xffb7b1)
    this.lastBattle = b
    if (stopped) this.tweens.pauseAll()
  }
  private syncEnemies(b: Battle) {
    const ids = new Set(b.enemies.map(enemy => enemy.id))
    for (const [id, view] of this.enemies) if (!ids.has(id)) {
      this.enemies.delete(id)
      view.root.setVisible(false).setActive(false)
      this.enemyPool.push(view)
    }
    for (const enemy of b.enemies) {
      let view = this.enemies.get(enemy.id)
      if (!view) {
        view = this.enemyPool.pop() || this.makeEnemy(enemy)
        view.data = enemy
        this.tweens.killTweensOf(view.root)
        this.tweens.killTweensOf(view.body)
        view.root.setActive(true).setVisible(true).setAlpha(1).setScale(1).setPosition(laneX(enemy.lane), enemyY(enemy.position))
        view.body.setTexture(textureKey, enemy.kind).clearTint().setAlpha(1).setAngle(0)
        view.body.setScale((enemy.kind === 'boss' ? 60 : enemy.kind === 'armored' ? 39 : 33) / Math.max(view.body.width, view.body.height))
        view.clue.setFontSize(enemy.clue.length > 38 ? 10 : 12)
        this.enemies.set(enemy.id, view)
        if (!this.reducedMotion) { view.root.setAlpha(0); this.tweens.add({ targets: view.root, alpha: 1, duration: 180 }) }
      }
      if (enemy.health < view.data.health) {
        view.body.setTintFill(0xffffff)
        this.time.delayedCall(100, () => { if (view?.data.id === enemy.id) view.body.clearTint() })
      }
      view.data = enemy
      view.clue.setText(enemy.clue)
      view.bar.clear().fillStyle(0x25372f).fillRoundedRect(-22, -27, 44, 6, 3).fillStyle(enemy.kind === 'boss' ? 0xe99aae : 0x88cc77).fillRoundedRect(-21, -26, 42 * Math.max(0, enemy.health / enemy.max_health), 4, 2)
    }
  }
  private makeEnemy(enemy: Enemy): EnemyView {
    const shadow = this.add.ellipse(0, 15, 30, 11, 0x24382c, .23)
    const body = sprite(this, enemy.kind, 0, 0, 34)
    const bar = this.add.graphics()
    const clue = label(this, 0, -47, enemy.clue, 12).setWordWrapWidth(106, true).setBackgroundColor('#fff8df').setPadding(5, 4).setStroke('#fff8df', 1)
    const shield = sprite(this, 'shield', 0, 0, 66).setAlpha(.55).setVisible(false)
    const root = this.add.container(0, 0, [shadow, body, bar, clue, shield]).setDepth(6)
    return { root, body, bar, clue, shield, data: enemy }
  }
  private syncTowers(b: Battle) {
    const lanes = new Set(b.towers.map(tower => tower.lane))
    for (const [lane, view] of this.towers) if (!lanes.has(lane)) {
      view.root.destroy(); this.towers.delete(lane)
    }
    for (const tower of b.towers) {
      const key = tower.text + ':' + tower.level + ':' + tower.until
      const current = this.towers.get(tower.lane)
      if (current?.key === key) continue
      current?.root.destroy()
      const type = tower.type || 'scribe'
      const aura = this.add.ellipse(0, 12, 63, 28, COLORS[type], .6).setStrokeStyle(2, PAPER)
      const body = sprite(this, TOWERS[type], 0, -6, tower.level === 2 ? 56 : 47)
      if (type === 'spark') body.setAngle(-90)
      const title = label(this, 0, 23, tower.text, 12).setWordWrapWidth(110, true).setBackgroundColor('#26382f').setColor('#fff6d9').setPadding(5, 3)
      const timer = this.add.graphics()
      const root = this.add.container(laneX(tower.lane), 386, [aura, body, title, timer]).setDepth(8)
      this.towers.set(tower.lane, { root, body, title, timer, key })
      if (!this.reducedMotion) { root.setScale(.2); this.tweens.add({ targets: root, scale: 1, duration: 260, ease: 'Back.Out' }) }
      this.burst(laneX(tower.lane), 386, COLORS[type], 8)
      this.cues.play('place')
    }
  }
  private fire(effect: BattleEffect, battleTime: number) {
    const type = effect.type || 'scribe'
    const image = this.shotPool.pop() || this.add.image(0, 0, textureKey, 'projectile').setDepth(7)
    image.setTexture(textureKey, type === 'cannon' ? 'heavyProjectile' : 'projectile').setVisible(true).setActive(true).setAlpha(1).setTint(COLORS[type])
    image.setScale((type === 'cannon' ? 23 : 12) / Math.max(image.width, image.height)).setPosition(laneX(effect.lane), 373)
    const travel = Math.max(.08, (373 - enemyY(effect.position)) / 350)
    const elapsed = Math.min(Math.max(0, battleTime - (effect.until - 1)), Math.max(0, travel - .12))
    this.shots.push({ image, target: effect.target || -1, lane: effect.lane, elapsed, from: 373, to: enemyY(effect.position) })
    const tower = this.towers.get(effect.lane)
    if (tower && !this.reducedMotion) this.tweens.add({ targets: tower.body, y: -1, duration: 55, yoyo: true })
    this.cues.play('shot')
  }
  private impact(effect: BattleEffect) {
    const x = laneX(effect.lane), y = enemyY(effect.position)
    const color = effect.correct ? 0xffed9a : 0xffad9a
    const burst = sprite(this, effect.kind === 'hit' ? 'hit' : 'burst', x, y, effect.kind === 'hit' ? 27 : 46).setDepth(15).setTint(color)
    this.tweens.add({ targets: burst, alpha: 0, scale: burst.scale * 1.8, duration: this.reducedMotion ? 100 : 310, onComplete: () => burst.destroy() })
    this.burst(x, y, color, effect.kind === 'hit' ? 4 : 10)
    const text = label(this, x, y - 20, effect.text, 19, effect.correct ? '#fff7ba' : '#ffccbc').setStroke('#26382f', 4).setDepth(18)
    this.tweens.add({ targets: text, y: y - (this.reducedMotion ? 20 : 55), alpha: 0, duration: 650, onComplete: () => text.destroy() })
    this.cues.play(effect.correct ? 'hit' : 'leak')
  }
  private burst(x: number, y: number, color: number, count: number) {
    if (this.reducedMotion) return
    for (let i = 0; i < count; i++) {
      const angle = i / count * Math.PI * 2
      const particle = this.add.image(x, y, textureKey, 'spark').setDisplaySize(5, 5).setTint(color).setDepth(14)
      this.tweens.add({ targets: particle, x: x + Math.cos(angle) * 28, y: y + Math.sin(angle) * 28, alpha: 0, duration: 330, onComplete: () => particle.destroy() })
    }
  }
  private announce(title: string, subtitle: string, color: number) {
    const background = this.add.rectangle(0, 0, 360, 78, INK, .96).setStrokeStyle(2, color)
    const heading = label(this, 0, -13, title, 23, '#' + color.toString(16))
    const caption = label(this, 0, 18, subtitle, 11, '#fff8df')
    const root = this.add.container(210, 235, [background, heading, caption]).setDepth(25)
    this.tweens.add({ targets: root, alpha: 0, delay: 1300, duration: 300, onComplete: () => root.destroy() })
    this.cues.play('wave')
  }
  update(time: number, delta: number) {
    const started = performance.now(), frame = this.bridge.frame, b = frame.battle
    const stopped = frame.blocked || b.paused
    const prediction = predictionSeconds(frame, this.bridge.receivedAt, started)
    const gameTime = b.time + prediction
    this.freeze.setVisible(b.freeze_until > gameTime)
    this.coreShield.setVisible(b.shield_until > gameTime)
    for (const view of this.enemies.values()) {
      const enemy = view.data
      const y = enemyY(predictedPosition(enemy.position, enemy.speed, b.time, b.freeze_until, prediction))
      const blend = stopped ? 1 : 1 - Math.exp(-delta / 65)
      view.root.y += (y - view.root.y) * blend
      view.body.angle = this.reducedMotion || stopped ? 0 : Math.sin(time / 115 + enemy.id) * (enemy.kind === 'runner' ? 7 : 3)
      view.shield.setVisible(enemy.kind === 'boss' && Math.floor(gameTime) % 8 < 2)
    }
    if (!stopped) for (let i = this.shots.length - 1; i >= 0; i--) {
      const shot = this.shots[i]
      shot.elapsed += Math.min(delta, 50) / 1000
      const target = this.enemies.get(shot.target)
      const destination = target?.root.y || shot.to
      shot.image.y = shot.from - shot.elapsed * 350
      shot.image.rotation = 0
      if (shot.image.y <= destination + 8 || shot.elapsed > 1.1) {
        shot.image.setActive(false).setVisible(false)
        this.shotPool.push(shot.image); this.shots.splice(i, 1)
      }
    }
    if (this.frames % 6 === 0) for (const tower of b.towers) {
      const view = this.towers.get(tower.lane)
      if (!view) continue
      const card = b.cards.find(card => card.text === tower.text)
      const ratio = Phaser.Math.Clamp((tower.until - gameTime) / ((card?.duration || 9) + (tower.level === 2 ? 5 : 0)), 0, 1)
      view.timer.clear().fillStyle(INK).fillRect(-25, 37, 50, 4).fillStyle(COLORS[tower.type || 'scribe']).fillRect(-25, 37, 50 * ratio, 4)
    }
    if (++this.frames % 20 === 0) {
      const canvas = this.game.canvas
      canvas.dataset.engine = 'phaser-3'
      canvas.dataset.enemies = JSON.stringify([...this.enemies.values()].map(view => ({ id: view.data.id, y: Number(view.root.y.toFixed(2)), health: view.data.health })))
      canvas.dataset.projectiles = String(this.shots.length)
      canvas.dataset.towers = String(this.towers.size)
      canvas.dataset.frozen = String(b.freeze_until > gameTime)
      canvas.dataset.frameWorkP95Ms = ([...this.timings].sort((a, b) => a - b)[Math.floor(this.timings.length * .95)] || 0).toFixed(2)
    }
  }
}

export class DefenseHUD extends Phaser.Scene {
  private health!: Phaser.GameObjects.Text
  private energy!: Phaser.GameObjects.Text
  private wave!: Phaser.GameObjects.Text
  private combo!: Phaser.GameObjects.Text
  private bars!: Phaser.GameObjects.Graphics
  private signature = ''
  constructor(private bridge: DefenseBridge) { super('DefenseHUD') }
  create() {
    this.add.rectangle(210, 39, WIDTH, 78, INK)
    label(this, 18, 18, 'THƯ VIỆN', 10, '#d4e6bb').setOrigin(0, .5)
    label(this, 167, 18, 'NĂNG LƯỢNG', 10, '#f5d989').setOrigin(0, .5)
    this.health = label(this, 18, 41, '', 20, '#fff9df').setOrigin(0, .5)
    this.energy = label(this, 167, 41, '', 20, '#ffe6a3').setOrigin(0, .5)
    this.wave = label(this, 393, 24, '', 15, '#fff9df').setOrigin(1, .5)
    this.combo = label(this, 393, 49, '', 11, '#cde2bc').setOrigin(1, .5)
    this.bars = this.add.graphics()
  }
  update() {
    const frame = this.bridge.frame, b = frame.battle
    const prediction = predictionSeconds(frame, this.bridge.receivedAt, performance.now())
    const energy = Math.min(10, b.energy + prediction * 1.2)
    const signature = [b.health, Math.floor(energy * 10), b.wave, b.waves, b.combo, b.kills].join(':')
    if (signature === this.signature) return
    this.signature = signature
    this.health.setText(b.health + ' / 12')
    this.energy.setText(Math.floor(energy) + ' / 10')
    this.wave.setText('ĐỢT ' + b.wave + '/' + b.waves)
    this.combo.setText(b.combo >= 3 ? 'COMBO ×' + Math.min(3, b.combo) : b.kills + ' ĐÃ HẠ')
    this.bars.clear().fillStyle(0x526053).fillRoundedRect(18, 60, 119, 6, 3).fillRoundedRect(167, 60, 115, 6, 3)
    this.bars.fillStyle(b.health <= 4 ? 0xffa38e : 0xb4d88c).fillRoundedRect(18, 60, 119 * b.health / 12, 6, 3)
    this.bars.fillStyle(0xffdb80).fillRoundedRect(167, 60, 115 * energy / 10, 6, 3)
  }
}

export function createDefenseGame(parent: HTMLElement, bridge: DefenseBridge) {
  const reduced = matchMedia('(prefers-reduced-motion: reduce)').matches
  const game = new Phaser.Game({
    type: Phaser.AUTO, parent, width: WIDTH, height: HEIGHT,
    backgroundColor: '#a8c67d', transparent: false, banner: false,
    render: { antialias: true, roundPixels: true },
    scale: { mode: Phaser.Scale.FIT, autoCenter: Phaser.Scale.CENTER_BOTH },
    fps: { target: 60, smoothStep: true },
    audio: { noAudio: true },
    scene: [new DefenseBoot(bridge, reduced)],
    callbacks: { postBoot: game => { game.canvas.setAttribute('role', 'img'); game.canvas.setAttribute('aria-label', 'Chiến trường ba đường. Chọn thẻ từ và chạm vị trí đặt tháp; tháp tự bắn quân đang tiến về thư viện.') } },
  })
  const resize = new ResizeObserver(() => { if (game.scale) game.scale.refresh() })
  resize.observe(parent)
  return { game, destroy: () => { resize.disconnect(); bridge.destroy(); game.destroy(true) } }
}
