import { QuestionImage } from '../../../components/QuestionMedia'
import { useState } from 'react'
import { Icon } from '../../../components/Icon'
import type { EngineProps, Card } from '../types'

export default function Matching({ activity, busy, send, feedback }: EngineProps) {
  const [selected, setSelected] = useState<{ left?: string; right?: string }>({})
  const cards = activity.cards || []
  async function choose(card: Card) {
    if (busy || card.matched || !card.side) return
    const next = { ...selected, [card.side]: card.id }
    setSelected(next)
    if (next.left && next.right) {
      try { await send('match', { left_id: next.left, right_id: next.right }) } finally { setSelected({}) }
    }
  }
  return <section aria-label="Ghép đôi tri thức"><p className="arcade-instruction">Chạm một thẻ ở mỗi bên để ghép đôi.</p><div className="arcade-pair-columns">{(['left', 'right'] as const).map(side => <div key={side} className="arcade-pair-column" aria-label={side === 'left' ? 'Khái niệm' : 'Nghĩa hoặc kết quả'}>{cards.filter(c => c.side === side).map(card => <button type="button" key={card.id} className={'arcade-card ' + (card.matched ? 'matched' : selected[side] === card.id ? 'selected' : feedback?.correct === false && feedback.card_ids?.includes(card.id) ? 'incorrect' : '')} disabled={busy || card.matched} aria-pressed={selected[side] === card.id} onClick={() => void choose(card)}>{card.image && <img src={card.image} alt="" loading="lazy"/>}<span>{card.text}<QuestionImage media={card.media}/></span>{card.matched && <small><Icon name="check"/> Đã ghép</small>}</button>)}</div>)}</div><p className="arcade-subtle">{activity.state.matched.length}/{activity.total} đôi đã tìm thấy · {activity.state.wrong} lượt cần thử lại</p></section>
}
