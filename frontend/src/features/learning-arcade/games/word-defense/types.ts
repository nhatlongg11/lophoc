export type TowerKind = 'scribe' | 'spark' | 'cannon'
export type DefenseCard = { id: string; text: string; type: TowerKind; cost: number; damage: number; interval: number; duration: number; cooldown: number }
export type Enemy = { id: number; lane: number; kind: 'scout' | 'runner' | 'armored' | 'boss'; position: number; speed: number; health: number; max_health: number; clue: string }
export type Tower = { lane: number; text: string; type: TowerKind; level: number; ready: number; until: number }
export type Projectile = { id: number; target: number; lane: number; position: number; type: TowerKind }
export type BattleEffect = { id: number; lane: number; position: number; text: string; correct: boolean; until: number; kind: string; target?: number; type?: TowerKind }
export type Battle = {
  time: number; tick: number; paused: boolean; status: 'playing' | 'won' | 'lost'; stage: number;
  health: number; energy: number; wave: number; waves: number; spawned: number; total: number;
  kills: number; leaked: number; correct: number; wrong: number; combo: number; best_combo: number;
  shield_until: number; freeze_until: number; ability_ready: number; card_ready: Record<string, number>;
  cards: DefenseCard[]; enemies: Enemy[]; towers: Tower[]; projectiles: Projectile[];
  effects: BattleEffect[]; lessons: { id: number; text: string; correct: boolean }[];
}
