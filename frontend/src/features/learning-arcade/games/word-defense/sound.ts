/** Tiny synthesized cues: no network audio, autoplay, or long-lived audio nodes. */
export class DefenseSound {
  private context: AudioContext | null = null
  muted = true
  unlock() {
    if (this.muted) return
    try { this.context ??= new AudioContext(); void this.context.resume() } catch { /* Optional audio. */ }
  }
  play(cue: 'place' | 'shot' | 'hit' | 'leak' | 'wave' | 'ice') {
    const context = this.context
    if (this.muted || !context || context.state !== 'running') return
    const frequencies = { place: 440, shot: 310, hit: 720, leak: 130, wave: 660, ice: 940 }
    const oscillator = context.createOscillator(), gain = context.createGain()
    oscillator.type = cue === 'leak' ? 'sawtooth' : 'triangle'
    oscillator.frequency.setValueAtTime(frequencies[cue], context.currentTime)
    oscillator.frequency.exponentialRampToValueAtTime(frequencies[cue] * .5, context.currentTime + .12)
    gain.gain.setValueAtTime(cue === 'shot' ? .012 : .035, context.currentTime)
    gain.gain.exponentialRampToValueAtTime(.001, context.currentTime + .15)
    oscillator.connect(gain); gain.connect(context.destination)
    oscillator.start(); oscillator.stop(context.currentTime + .16)
    oscillator.onended = () => { oscillator.disconnect(); gain.disconnect() }
  }
  destroy() { void this.context?.close(); this.context = null }
}
