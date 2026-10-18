import manifest from './assets/manifest.json'
export const atlasUrl = new URL('./assets/kenney-atlas.webp', import.meta.url).href
export const assets = manifest.sprites
export type SpriteName = keyof typeof assets
export const textureKey = 'kenney-defense'
export const atlasFrames = Object.fromEntries(Object.entries(assets).map(([name, sprite]) => {
  const [x, y, w, h] = sprite.frame
  const crop = !['grass', 'path', 'pathLeft', 'pathRight', 'tree', 'towerBase', 'archive'].includes(name)
  const [left, top, width, height] = crop ? sprite.contentBounds : [0, 0, w, h]
  return [name, { frame: { x: x + left, y: y + top, w: width, h: height }, rotated: false, trimmed: false, spriteSourceSize: { x: 0, y: 0, w: width, h: height }, sourceSize: { w: width, h: height } }]
}))
