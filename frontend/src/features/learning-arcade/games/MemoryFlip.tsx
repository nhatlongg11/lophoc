import { QuestionImage } from '../../../components/QuestionMedia'
import { useEffect, useState } from 'react'
import { Button } from '../../../components/ui'
import { Icon } from '../../../components/Icon'
import type { EngineProps } from '../types'

export default function MemoryFlip({ activity, busy, send }: EngineProps) {
  const [automatic, setAutomatic] = useState(true)
  useEffect(() => {
    if (!automatic || !activity.state.resolving || busy) return
    const timer = setTimeout(() => void send('resolve'), 1600)
    return () => clearTimeout(timer)
  }, [automatic, activity.state.resolving, busy, send])
  return <section aria-label="Lật thẻ ghi nhớ"><p className="arcade-instruction">Lật hai thẻ để tìm cặp có liên hệ. Em có thể tắt tự úp để đọc lâu hơn.</p><div className="arcade-memory-grid">{activity.cards?.map((card, i) => <button type="button" key={card.id} className={'arcade-memory-card ' + (card.revealed ? 'revealed ' : '') + (card.matched ? 'matched' : '')} aria-label={card.revealed ? (card.text + (card.matched ? ' · Đã ghép' : ' · Đang mở')) : 'Lật thẻ ' + (i + 1)} aria-pressed={card.revealed} disabled={busy || card.matched || card.revealed || activity.state.resolving} onClick={() => void send('flip', { card_id: card.id })}>{card.revealed ? <>{card.image && <img src={card.image} alt=""/>}<span>{card.text}<QuestionImage media={card.media}/></span>{card.matched && <small><Icon name="check"/> Đã ghép</small>}</> : <><Icon name="sparkle"/><span className="sr-only">Thẻ {i + 1}</span></>}</button>)}</div><div className="arcade-memory-controls"><label className="check-row"><input type="checkbox" checked={automatic} onChange={e => setAutomatic(e.target.checked)}/>Tự úp thẻ sau khi xem</label>{activity.state.resolving && <Button secondary disabled={busy} onClick={() => void send('resolve')}>Úp thẻ để tiếp tục</Button>}</div><p className="arcade-subtle">{activity.state.matched.length}/{activity.total} đôi · {activity.state.flips} lượt lật · {activity.state.wrong} lượt chưa khớp</p></section>
}
