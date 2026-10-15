import type { ReactNode } from 'react'
import bandeau from '@/assets/ansd/bandeau-ansd.webp'
import logo20Ans from '@/assets/ansd/logo-ansd-20-ans.png'

/**
 * Bandeau institutionnel de l'ANSD, repris du site ansd.sn (observations
 * de forme, octobre 2026) : dégradé bleu clair → bleu profond, logo à
 * gauche sur la partie claire, texte blanc à droite sur la partie sombre.
 * Visuels auto-hébergés dans src/assets/ansd — jamais chargés depuis le
 * site de l'ANSD (pas de ressource externe en production).
 */
export function BandeauAnsd({ children, hauteur = 'normale' }: { children?: ReactNode; hauteur?: 'normale' | 'haute' }) {
  return (
    <header
      className={`flex items-center justify-between gap-4 bg-[#dbe9f6] bg-cover bg-left px-5 sm:px-7 ${
        hauteur === 'haute' ? 'h-24 sm:h-28' : 'h-[72px]'
      }`}
      style={{ backgroundImage: `url(${bandeau})` }}
    >
      <a href="https://www.ansd.sn" target="_blank" rel="noopener noreferrer" className="h-[82%] shrink-0" title="ansd.sn">
        <img
          src={logo20Ans}
          alt="ANSD — Agence nationale de la statistique et de la démographie"
          className="h-full w-auto drop-shadow-sm"
        />
      </a>
      {children && <div className="min-w-0 text-right text-white [text-shadow:0_1px_2px_rgb(0_0_0/0.25)]">{children}</div>}
    </header>
  )
}
