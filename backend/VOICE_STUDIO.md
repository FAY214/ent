# Home AI voice studio

## My voice for official XiaoZhi (2026-10-28)

Home now has two explicitly separate modes: **My voice for XiaoZhi** and
**AI text-to-speech**. The XiaoZhi mode records or imports the user's reference,
preserves the original audio even if ASR fails, prepares a mono 16 kHz PCM WAV,
and stores up to three private samples in the authenticated account.

`GET/POST /api/voice/profiles` owns listing, preparation, audio download, deletion,
and user-reported setup status. Audio download requires the Bearer token and
checks sample ownership; no private reference is served from `public/`.
The additive `puffsqueeze_voice_profiles` table stores audio as a BLOB.

The **official XiaoZhi** flow deliberately requires the user to download their
sample, sign in at `https://xiaozhi.me/console/`, perform voice cloning there,
and select the resulting voice for their agent. No private/undocumented official
API has been invented. `user_confirmed` records the user's own report; it does
not claim that a remote account was verified. The local application does not
train a voice model or automatically change the official cloud agent.

Reference recordings should be natural speech around 15–25 seconds; the local
preparer accepts 6–32 seconds, detects very quiet samples, and caps decoded
duration while reading frames. ASR text is optional for preparing the reference.

### Recognition compatibility fix

`faster-whisper==1.2.1` calls PyAV with `metadata_errors`; PyAV 19 no longer
accepts that argument. Pin **av==15.1.0**, included in `requirements-voice.txt`.
The previous failure occurred before the model saw any speech. The original
environment and the portable environment must both use the compatible decoder.
Errors are surfaced without returning private paths or transcript text in logs.

## Existing preset-voice reading mode

The Home page records up to 30 seconds through MediaRecorder. After recording,
the authenticated `/api/voice/transcribe` endpoint runs a local multilingual
Whisper tiny model on CPU. The user can correct the transcript, choose a voice,
then request `/api/voice/synthesize` to get a downloadable MP3.

The voices are Microsoft Edge online neural TTS via `edge-tts`:
`en-US-AnaNeural` (the supplied English doll clips' voice) and
`zh-CN-XiaoxiaoNeural`. This is web playback; it does not replace ESP32 assets
or invoke the XiaoZhi device's voice provider. TTS requires internet access.

## Local installation

Run from the project root with Python 3.12 or another supported Python:

```powershell
python -m venv .voice-venv
.voice-venv/Scripts/python.exe -m pip install -r backend/requirements-voice.txt
.voice-venv/Scripts/python.exe -c "from huggingface_hub import snapshot_download; snapshot_download('Systran/faster-whisper-tiny', local_dir='.voice-models/tiny', allow_patterns=['model.bin','config.json','tokenizer.json','vocabulary.*','README.md'])"
```

Set **absolute paths** in `backend/.env`:

```dotenv
VOICE_PYTHON=E:/path/to/project/.voice-venv/Scripts/python.exe
VOICE_WHISPER_MODEL=E:/path/to/project/.voice-models/tiny
```

PHP must support `proc_open`, private temporary uploads, and a request duration
of at least 45 seconds. HTTPS or localhost is required for microphone access.
The local venv and model are ignored and must be installed separately on a new
server; publishing only `dist/` does not deploy the voice backend.

## Data and limits

- Auth token determines the user; the API does not accept a caller's user ID.
- Recordings are limited to 2 MB and 32 seconds on the server (30 in the UI).
- Uploads and worker output are temporary, outside the web root, and removed
  after the request. The original recording stays in browser memory only.
- Confirmed text is limited to 600 characters. Only text goes to online TTS.
- Generated clips are saved only when the user selects **Save to my voices**.
  Up to three clips, capped at 1.8 MB of JSON, sync through the existing
  authenticated `voice_studio` account document. Download is also available.
- Workers receive JSON over stdin, use fixed scripts, bypass the shell, and
  are terminated after 40 seconds. Each user has a per-operation concurrency
  lock and a 3-second minimum interval.
- There is no system/browser voice fallback presented as an AI result.

Model and library references: [faster-whisper](https://github.com/SYSTRAN/faster-whisper),
[model](https://huggingface.co/Systran/faster-whisper-tiny),
[edge-tts](https://github.com/rany2/edge-tts).
