import type { ActivityResult, Review } from '../../types'
import './word-defense.css'

function word(item: Review) { return item.answer ? (Array.isArray(item.answer) ? item.answer.join(' / ') : item.answer) + ' → ' + item.prompt : item.prompt }

export function LearningRecap({ result }: { result: ActivityResult }) {
  const strong = result.review.filter(item => item.tries > 0 && item.first_correct)
  const review = result.review.filter(item => item.tries > 0 && !item.first_correct)
  const unused = result.review.filter(item => item.tries === 0)
  return <section className="wd-recap" aria-label="Sổ tay từ vựng sau trận đấu">
    <span className="eyebrow">SỔ TAY SAU TRẬN ĐẤU</span>
    <h3>{result.battle?.kills || 0} quân đã hạ · {result.battle?.health || 0}/12 sức bền thư viện</h3>
    {strong.length > 0 && <p><b>Ghép tốt:</b> {strong.map(word).join(' · ')}</p>}
    {review.length > 0 && <p><b>Cần luyện lại:</b> {review.map(word).join(' · ')}</p>}
    {unused.length > 0 && <p><b>Chưa gặp trong trận:</b> {unused.map(word).join(' · ')}</p>}
    <p className="wd-review-prompt">{review.length ? 'Lượt tới, ưu tiên đọc nghĩa của ' + review.slice(0, 3).map(item => item.prompt).join(', ') + ' trước khi đổi tháp.' : 'Giữ nhịp đọc nghĩa, chọn từ và canh năng lượng trong trận tiếp theo.'}</p>
  </section>
}
