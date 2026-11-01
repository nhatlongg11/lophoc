import { readdir, readFile, writeFile } from 'node:fs/promises'
import { join } from 'node:path'
import { gzipSync } from 'node:zlib'

const root = process.argv[2]
if (!root) throw new Error('Usage: node precompress-assets.mjs <frontend-output-directory>')

// Compress once during the image build; Nginx serves these without runtime compression.
for (const path of await readdir(root, { recursive: true })) {
  if (!/\.(?:js|css|svg|html)$/.test(path)) continue
  const file = join(root, path)
  await writeFile(file + '.gz', gzipSync(await readFile(file), { level: 9 }))
}
