"""Local neural TTS adapter. JSON on stdin, MP3 bytes on stdout; no shell input."""
import asyncio
import json
import sys

import edge_tts

VOICES = {"ana": "en-US-AnaNeural", "xiaoxiao": "zh-CN-XiaoxiaoNeural"}


async def main():
    request = json.loads(sys.stdin.buffer.read(8192).decode("utf-8"))
    text = request.get("text", "").strip()
    voice = VOICES[request.get("voice", "ana")]
    if not text or len(text) > 600:
        raise ValueError("Invalid text length")
    # Original voice level: do not amplify or normalize the audio.
    communicate = edge_tts.Communicate(text, voice, rate="-5%", volume="+0%")
    audio = bytearray()
    async for chunk in communicate.stream():
        if chunk["type"] == "audio":
            audio.extend(chunk["data"])
            if len(audio) > 1024 * 1024:
                raise ValueError("Audio too long")
    if not audio:
        raise ValueError("No audio received")
    sys.stdout.buffer.write(audio)


if __name__ == "__main__":
    try:
        asyncio.run(asyncio.wait_for(main(), timeout=35))
    except Exception as error:
        # Never include spoken text in server logs.
        print(type(error).__name__, file=sys.stderr)
        sys.exit(1)
