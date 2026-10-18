import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import { Button } from '../../../../components/ui'
import { Icon } from '../../../../components/Icon'
import type { ActionFields, ActionKind, EngineProps } from '../../types'
import { DefenseBridge } from './bridge'
import type { createDefenseGame } from './scenes'
import type { DefenseCard } from './types'
import './word-defense.css'

const roles = { scribe: { name: 'Cân bằng', icon: '◆' }, spark: { name: 'Bắn nhanh', icon: 'ϟ' }, cannon: { name: 'Pháo mạnh', icon: '✦' } }

export default function WordDefense({ activity, busy, send, muted = true, suspended = false }: EngineProps) {
  const battle = activity.battle!
  const [selected, setSelected] = useState(''), [lane, setLane] = useState(0)
  const [loaded, setLoaded] = useState(false), [assetError, setAssetError] = useState(''), [attempt, setAttempt] = useState(0)
  const [hidden, setHidden] = useState(document.hidden), [issuing, setIssuing] = useState(false)
  const host = useRef<HTMLDivElement>(null), bridge = useRef<DefenseBridge | null>(null)
  const latest = useRef({ battle, busy, send, suspended, hidden, loaded, selected, lane })
  const alive = useRef(false), commandWaiting = useRef(false), pauseWaiting = useRef(false)
  useLayoutEffect(() => {
    latest.current = { battle, busy, send, suspended, hidden, loaded, selected, lane }
    bridge.current?.publish({ battle, selected, lane, blocked: suspended || hidden || !loaded, muted })
  }, [battle, busy, send, suspended, hidden, loaded, selected, lane, muted])

  async function command(kind: ActionKind, fields: ActionFields = {}) {
    if (commandWaiting.current) { if (kind === 'defense_pause') pauseWaiting.current = true; return }
    commandWaiting.current = true; setIssuing(true)
    try {
      while (latest.current.busy && alive.current) await new Promise(resolve => setTimeout(resolve, 25))
      const current = latest.current
      if (!alive.current || current.busy) return
      if (kind !== 'defense_pause' && (current.suspended || current.hidden || !current.loaded)) return
      if (kind === 'defense_deploy') {
        const card = current.battle.cards.find(card => card.id === fields.spell_id)
        if (!card || current.battle.paused || current.battle.energy < card.cost || (current.battle.card_ready[card.id] || 0) > current.battle.time) return
      }
      await current.send(kind, fields)
    } finally {
      commandWaiting.current = false
      if (alive.current) {
        setIssuing(false)
        if (pauseWaiting.current) { pauseWaiting.current = false; void intent.current('defense_pause') }
      }
    }
  }
  function deploy(targetLane: number) {
    setLane(targetLane)
    if (selected) void command('defense_deploy', { lane: targetLane, spell_id: selected })
  }
  const intent = useRef((kind: ActionKind, fields: ActionFields = {}) => {
    if (fields.lane !== undefined) setLane(fields.lane)
    void command(kind, fields)
  })
  useLayoutEffect(() => { intent.current = (kind, fields = {}) => { if (fields.lane !== undefined) setLane(fields.lane); void command(kind, fields) } })

  useEffect(() => {
    alive.current = true
    let cancelled = false, engine: ReturnType<typeof createDefenseGame> | undefined
    const current = latest.current
    const channel = new DefenseBridge(
      { battle: current.battle, selected: current.selected, lane: current.lane, blocked: true, muted },
      ({ kind, fields }) => intent.current(kind, fields),
      () => { if (!cancelled) setLoaded(true) },
      message => { if (!cancelled) setAssetError(message) },
    )
    bridge.current = channel
    void import('./scenes').then(({ createDefenseGame }) => {
      if (!cancelled && host.current) engine = createDefenseGame(host.current, channel)
    }).catch(() => { if (!cancelled) setAssetError('Không mở được bộ máy trò chơi. Vui lòng thử lại.') })
    return () => { cancelled = true; engine?.destroy(); if (bridge.current === channel) bridge.current = null; channel.destroy() }
  // The game instance lives for one activity, not for each authoritative snapshot.
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [attempt])
  useEffect(() => {
    alive.current = true
    if (!latest.current.battle.paused && latest.current.battle.status === 'playing') void intent.current('defense_pause')
    const visibility = () => { setHidden(document.hidden); if (document.hidden) void intent.current('defense_pause') }
    const blur = () => { setHidden(true); void intent.current('defense_pause') }
    const focus = () => setHidden(document.hidden)
    document.addEventListener('visibilitychange', visibility); window.addEventListener('blur', blur); window.addEventListener('focus', focus)
    return () => {
      alive.current = false
      document.removeEventListener('visibilitychange', visibility); window.removeEventListener('blur', blur); window.removeEventListener('focus', focus)
    }
  }, [])
  useEffect(() => {
    if ((suspended || hidden || !loaded) && !battle.paused && battle.status === 'playing' && !busy && !commandWaiting.current) void intent.current('defense_pause')
  }, [suspended, hidden, loaded, battle.paused, battle.status, busy])
  useEffect(() => {
    if (!loaded) return
    const timer = setInterval(() => {
      const current = latest.current
      if (!current.busy && !commandWaiting.current && !current.battle.paused && !current.suspended && !current.hidden && current.battle.status === 'playing') void current.send('defense_sync')
    }, 400)
    return () => clearInterval(timer)
  }, [loaded])

  const frozen = battle.paused || suspended || hidden || !loaded
  const card = battle.cards.find(card => card.id === selected)
  const fronts = [0, 1, 2].map(i => battle.enemies.filter(enemy => enemy.lane === i).sort((a, b) => b.position - a.position)[0])
  const role = (card: DefenseCard) => roles[card.type || 'scribe']
  return <div className="word-defense" data-testid="word-defense" data-time={battle.time} data-kills={battle.kills} data-health={battle.health}>
    <div className="wd-match-title"><div><span>CE ARCADE / WORD DEFENSE</span><strong>Bảo vệ thư viện</strong></div><span className={'wd-live ' + (frozen ? 'paused' : '')}>{frozen ? 'SẴN SÀNG' : 'ĐANG GIAO TRANH'}</span></div>
    <div className="wd-battlefield">
      <div ref={host} className="wd-phaser"/>
      {!loaded && <div className="wd-curtain" role="status"><span className="wd-crest">CE</span><h3>{assetError ? 'Chưa tải được chiến trường' : 'Đang mở cổng thư viện…'}</h3><p>{assetError || 'Chuẩn bị tháp, nạp năng lượng và tập hợp quân phòng thủ.'}</p>{assetError && <Button onClick={() => { setAssetError(''); setLoaded(false); setAttempt(n => n + 1) }}>Tải lại hình ảnh</Button>}</div>}
      {loaded && frozen && <div className="wd-curtain"><span className="wd-crest"><Icon name="book"/></span><span className="eyebrow">WORD DEFENSE · ĐỢT {battle.wave}/{battle.waves}</span><h3>{battle.time === 0 ? 'Giữ vững thư viện!' : 'Trận đấu tạm dừng'}</h3><p>Đọc nghĩa trên quân địch.<br/>Chọn thẻ từ → chạm bệ tháp cùng đường.<br/><b>Ghép đúng để bắn mạnh. Ghép sai chỉ gây 2 sát thương.</b></p><div className="wd-role-guide"><span>◆ Cân bằng · 3</span><span>ϟ Bắn nhanh · 2</span><span>✦ Pháo mạnh · 4</span></div><Button disabled={issuing || suspended || hidden} onClick={() => void command('defense_resume')}><Icon name="play"/> {battle.time === 0 ? 'Bắt đầu bảo vệ' : 'Tiếp tục trận đấu'}</Button></div>}
    </div>
    <div className="wd-lanes" aria-label="Đặt tháp theo đường">{fronts.map((enemy, i) => <button type="button" key={i} className={lane === i ? 'selected' : ''} disabled={issuing || frozen} onClick={() => deploy(i)} aria-label={'Đặt tháp đường ' + (i + 1)}><b>{i + 1}</b><span>{enemy?.clue || 'Giữ đường'}</span></button>)}</div>
    <div className="wd-deck-heading"><strong>THÁP TỪ VỰNG</strong><span>{card ? card.text + ' · ' + card.cost + ' năng lượng → chọn đường' : 'Chọn thẻ, rồi chạm bệ tháp'}</span></div>
    <div className="wd-hand" aria-label="Thẻ phép từ vựng">{battle.cards.map(card => {
      const cooldown = Math.max(0, (battle.card_ready[card.id] || 0) - battle.time)
      return <button type="button" key={card.id} data-role={card.type} data-cost={card.cost} aria-label={card.text + ' · ' + role(card).name + ' · ' + card.cost + ' năng lượng'} aria-pressed={selected === card.id} className={selected === card.id ? 'selected' : ''} disabled={frozen || issuing || cooldown > 0} onClick={() => setSelected(card.id)}><span className="wd-card-top"><i aria-hidden="true">{role(card).icon}</i><b>{card.cost}<span aria-hidden="true">ϟ</span></b></span><strong>{card.text}</strong><small>{cooldown > 0 ? Math.ceil(cooldown) + 's hồi' : role(card).name}</small><span className="wd-card-meter" style={{ width: cooldown ? (1 - cooldown / card.cooldown) * 100 + '%' : '100%' }}/></button>
    })}</div>
    <div className="wd-actions"><button type="button" disabled={issuing || frozen || battle.energy < 2 || !battle.towers.some(tower => tower.lane === lane && tower.level === 1)} onClick={() => void command('defense_upgrade', { lane })}><span aria-hidden="true">↑</span><span>Nâng đường {lane + 1}<small>2 năng lượng</small></span></button><button type="button" className="wd-ice" disabled={issuing || frozen || battle.energy < 4 || battle.ability_ready > battle.time} onClick={() => void command('defense_ability')}><Icon name="sparkle"/><span>Khiên băng<small>{battle.ability_ready > battle.time ? Math.ceil(battle.ability_ready - battle.time) + 's hồi' : '4 năng lượng · 4s'}</small></span></button><button type="button" className="wd-pause" aria-label="Tạm dừng" disabled={issuing || frozen} onClick={() => void command('defense_pause')}><span aria-hidden="true">Ⅱ</span></button></div>
    <div className="wd-screen-reader" role="status" aria-live="polite">Thư viện {battle.health}/12. Năng lượng {Math.floor(battle.energy)}/10. Đợt {battle.wave}/{battle.waves}. {battle.kills} quân đã hạ.</div>
    {battle.stage === 5 && <p className="wd-tip">Trùm dựng khiên 2 giây mỗi 8 giây và gọi quân nhanh vào hai đường bên. Dùng Khiên băng để giữ phòng tuyến.</p>}
    <p className="wd-credit">Kenney · CC0 <span>Ôn lại từ vựng sau trận đấu</span></p>
  </div>
}
