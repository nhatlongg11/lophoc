export function Brand({ large = false }: { large?: boolean }) {
  return <div className={`brand${large ? ' large' : ''}`} aria-label="CE - Connecting Education">
    <img className="brand-logo" src={`${import.meta.env.BASE_URL}img/logo-transparent.png`} alt="" />
    <span><b>CE</b><small>Connecting Education</small></span>
  </div>
}
