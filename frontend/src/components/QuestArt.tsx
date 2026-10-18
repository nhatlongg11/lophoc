export function QuestArt({ variant = 'book', className = '' }: { variant?: 'book' | 'planet' | 'star'; className?: string }) {
  return <svg className={'quest-art ' + className} viewBox="0 0 300 220" fill="none" aria-hidden="true">
    <g stroke="#242424" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round">
      <path d="M35 41v18m-9-9h18M257 143v18m-9-9h18" strokeWidth="2.5"/>
      <path d="m244 28 5 12 13 1-10 8 3 13-11-7-11 7 3-13-10-8 13-1Z" fill="#FFE597"/>
      <circle cx="45" cy="166" r="9" fill="#DCCBFF"/><path d="M69 26c8-9 18-8 23-3M267 103c10 2 17 10 15 19"/>
      {variant === 'book' ? <>
        <ellipse cx="154" cy="196" rx="76" ry="9" fill="#242424" stroke="none" opacity=".12"/>
        <path d="m100 159-12 23 25 3M187 162l13 20-24 5" fill="#242424"/>
        <path d="m83 109-22 11 5 17M214 108l21-6 5 16"/>
        <path d="m97 47 98-10 21 119-101 17Z" fill="#242424"/>
        <path d="m85 37 101-10 22 120-100 17c-11 1-17-5-18-14L74 54c-1-9 3-15 11-17Z" fill="#BFE8C2"/>
        <path d="m92 38 18 112 97-14M81 52l92-9" stroke="#242424"/>
        <path d="m110 151 97-15 2 11-99 17c-8 1-15-3-16-10 0-5 5-9 16-11" fill="#FFFEF4"/>
        <path d="m111 155 82-13M124 161l75-13" strokeWidth="1.5"/>
        <ellipse cx="126" cy="83" rx="4" ry="7" fill="#242424"/><ellipse cx="162" cy="78" rx="4" ry="7" fill="#242424"/>
        <path d="M134 100c6 9 16 7 21-3"/>
        <ellipse cx="113" cy="96" rx="8" ry="4" fill="#FFAFA0" stroke="none"/><ellipse cx="176" cy="87" rx="8" ry="4" fill="#FFAFA0" stroke="none"/>
        <path d="m224 48 13 4-17 58-12-4Z" fill="#FFE597"/><path d="m208 106 3 18 9-14" fill="#FFF9ED"/><path d="m211 124 1-6 3 1Z" fill="#242424"/><path d="m224 48 3-8c2-6 15-1 13 4l-3 8" fill="#FFC1D5"/>
      </> : variant === 'planet' ? <>
        <circle cx="151" cy="110" r="62" fill="#DCCBFF"/><path d="M96 109c-23 9-34 19-30 25 7 12 52 8 100-8s81-38 74-49c-4-6-20-6-41-2" fill="#FFE597"/><path d="M96 109c9 16 24 30 45 39" stroke="#242424" strokeDasharray="3 7"/><ellipse cx="133" cy="91" rx="4" ry="7" fill="#242424"/><ellipse cx="166" cy="91" rx="4" ry="7" fill="#242424"/><path d="M141 109c6 7 14 7 20 0"/><circle cx="206" cy="178" r="12" fill="#BFE8C2"/><path d="m74 175 15 4"/>
      </> : <>
        <path d="m151 33 28 47 54 11-36 43 5 56-51-22-52 22 5-56-36-43 55-11Z" fill="#FFE597"/><ellipse cx="132" cy="107" rx="4" ry="7" fill="#242424"/><ellipse cx="171" cy="107" rx="4" ry="7" fill="#242424"/><path d="M139 126c8 10 19 10 26 0"/><path d="m93 62-9-13M213 48l-8 14M53 127l15-2"/>
      </>}
      <path d="m55 89 5 8 10 1-7 6 2 9-10-5-8 5 2-10-6-6 9-1Z" fill="#FFC1D5" strokeWidth="2"/>
    </g>
  </svg>
}
