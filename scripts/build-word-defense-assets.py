"""Rebuild or verify the Word Defense atlas from its source manifest.

Requires Pillow for developer tooling only. The web application has no added dependency.
"""
import argparse
import hashlib
import json
from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parents[1]
ASSETS = ROOT / 'frontend/src/features/learning-arcade/games/word-defense/assets'


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check', action='store_true', help='Verify every packed pixel and source checksum without changing files')
    args = parser.parse_args()
    manifest = json.loads((ASSETS / 'manifest.json').read_text(encoding='utf-8'))
    output = ASSETS / manifest['atlas']
    atlas = Image.open(output).convert('RGBA') if args.check else Image.new('RGBA', (512, 192))
    for role, sprite in manifest['sprites'].items():
        source = ROOT / sprite['source']
        if hashlib.sha256(source.read_bytes()).hexdigest() != sprite['sha256']:
            raise ValueError(f'{role}: source checksum changed: {source}')
        image = Image.open(source).convert('RGBA')
        x, y, width, height = sprite['frame']
        if image.size != (width, height):
            raise ValueError(f'{role}: source dimensions do not match the manifest')
        if args.check:
            packed = atlas.crop((x, y, x + width, y + height))
            # RGB values under alpha=0 are immaterial and may be discarded by WebP.
            expected = Image.new('RGBA', image.size)
            expected.alpha_composite(image)
            actual = Image.new('RGBA', packed.size)
            actual.alpha_composite(packed)
            if expected.tobytes() != actual.tobytes():
                raise ValueError(f'{role}: atlas does not contain the original sprite pixels')
        else:
            atlas.paste(image, (x, y))
    if not args.check:
        atlas.save(output, lossless=True, method=6)
    print(f'{"Verified" if args.check else "Built"} {len(manifest["sprites"])} real Kenney sprites; {output.stat().st_size:,} bytes')


if __name__ == '__main__':
    main()
