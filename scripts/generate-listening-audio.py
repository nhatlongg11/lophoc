"""Generate original public-domain tones for an EduQuest listening activity."""
import math
import struct
import wave
from pathlib import Path

target = Path(__file__).resolve().parents[1] / "frontend/public/audio/rising-notes.wav"
target.parent.mkdir(parents=True, exist_ok=True)
sample_rate = 22050
with wave.open(str(target), "wb") as stream:
    stream.setparams((1, 2, sample_rate, 0, "NONE", "not compressed"))
    for frequency, duration in [(0, .3), (330, .6), (0, .3), (550, .6), (0, .4)]:
        count = int(sample_rate * duration)
        for i in range(count):
            envelope = min(1, i / 300, (count - i) / 600)
            sample = int(11000 * envelope * math.sin(2 * math.pi * frequency * i / sample_rate)) if frequency else 0
            stream.writeframesraw(struct.pack("<h", sample))
print("Generated original listening tones.")
