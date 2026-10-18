import { Icon } from '../../components/Icon'
export function Stars({ count }: { count: number }) { return <span className="arcade-stars" role="img" aria-label={count + ' trên 3 sao'}>{[1, 2, 3].map(n => <Icon name="star" key={n} className={n <= count ? 'earned' : ''}/>)}</span> }
