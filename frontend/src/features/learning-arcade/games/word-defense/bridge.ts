import type { ActionFields, ActionKind } from '../../types'
import type { Battle } from './types'

export type DefenseFrame = { battle: Battle; selected: string; lane: number; blocked: boolean; muted: boolean }
export type DefenseIntent = { kind: ActionKind; fields?: ActionFields }

/** No React objects or auth credentials enter Phaser. Snapshots in, decisions out. */
export class DefenseBridge {
  frame: DefenseFrame
  receivedAt = performance.now()
  private listeners = new Set<(frame: DefenseFrame) => void>()
  constructor(frame: DefenseFrame, readonly command: (intent: DefenseIntent) => void, readonly ready: () => void, readonly fail: (message: string) => void) { this.frame = frame }
  publish(frame: DefenseFrame) {
    if (this.frame.battle !== frame.battle) this.receivedAt = performance.now()
    this.frame = frame
    this.listeners.forEach(listener => listener(frame))
  }
  subscribe(listener: (frame: DefenseFrame) => void) { this.listeners.add(listener); return () => { this.listeners.delete(listener) } }
  destroy() { this.listeners.clear() }
}

export function predictionSeconds(frame: DefenseFrame, receivedAt: number, now: number) {
  return frame.blocked || frame.battle.paused || frame.battle.status !== 'playing' ? 0 : Math.max(0, Math.min(1.2, (now - receivedAt) / 1000))
}
export function predictedPosition(position: number, speed: number, battleTime: number, freezeUntil: number, elapsed: number) {
  const frozen = Math.min(elapsed, Math.max(0, freezeUntil - battleTime))
  return Math.min(1, position + speed * (elapsed - frozen + frozen * .25))
}
