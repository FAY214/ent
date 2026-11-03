"""Transcribe a short private upload using the installed local Whisper model."""
import json
import sys
import av

from faster_whisper import WhisperModel
from faster_whisper.audio import decode_audio


def main():
    request = json.loads(sys.stdin.buffer.read(8192).decode("utf-8"))
    language = request["language"]
    if language not in ("en", "zh"):
        raise ValueError("Unsupported language")
    if int(av.__version__.split('.')[0]) >= 19:
        raise RuntimeError('asr_dependency_error')
    try:
        audio = decode_audio(request["audio"], sampling_rate=16000)
    except Exception as error:
        raise RuntimeError('audio_decode_failed') from error
    if len(audio) > 32 * 16000:
        raise ValueError("Recording too long")
    model = WhisperModel(request["model"], device="cpu", compute_type="int8", cpu_threads=4, local_files_only=True)
    segments, _ = model.transcribe(audio, language=language, beam_size=3, vad_filter=True,
                                   condition_on_previous_text=False)
    text = " ".join(segment.text.strip() for segment in segments).strip()[:600]
    sys.stdout.buffer.write(json.dumps({"text": text}, ensure_ascii=False).encode("utf-8"))


if __name__ == "__main__":
    try:
        main()
    except Exception as error:
        token = str(error) if str(error) in {'asr_dependency_error','audio_decode_failed'} else type(error).__name__
        print(token, file=sys.stderr)
        sys.exit(1)
