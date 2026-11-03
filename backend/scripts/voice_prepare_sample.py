"""Prepare a private, clean PCM WAV reference for the user's XiaoZhi voice clone."""
import io
import json
import sys
import wave

import av
import numpy as np


def main():
    request = json.loads(sys.stdin.buffer.read(8192).decode('utf-8'))
    chunks = []
    count = 0
    # Bound decoded duration as frames arrive, even for tiny compressed uploads.
    try:
        with av.open(request['audio'], mode='r') as container:
            resampler = av.AudioResampler(format='s16', layout='mono', rate=16000)
            for frame in container.decode(audio=0):
                for converted in resampler.resample(frame):
                    count += converted.samples
                    if count > 32*16000:
                        raise ValueError('sample_too_long')
                    chunks.append(converted.to_ndarray().reshape(-1).copy())
            for converted in resampler.resample(None):
                count += converted.samples
                if count > 32*16000:
                    raise ValueError('sample_too_long')
                chunks.append(converted.to_ndarray().reshape(-1).copy())
    except ValueError:
        raise
    except Exception as error:
        raise ValueError('audio_decode_failed') from error
    if count < 6*16000:
        raise ValueError('sample_too_short')
    pcm = np.concatenate(chunks).astype('<i2', copy=False)
    rms = np.sqrt(np.mean((pcm.astype(np.float64)/32768)**2))
    if rms < 0.004:
        raise ValueError('sample_too_quiet')
    output = io.BytesIO()
    with wave.open(output, 'wb') as wav:
        wav.setnchannels(1)
        wav.setsampwidth(2)
        wav.setframerate(16000)
        wav.writeframes(pcm.tobytes())
    sys.stdout.buffer.write(output.getvalue())


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        token = str(error)
        if token not in {'sample_too_short','sample_too_long','sample_too_quiet','audio_decode_failed'}:
            token = 'audio_decode_failed'
        print(token, file=sys.stderr)
        sys.exit(1)
