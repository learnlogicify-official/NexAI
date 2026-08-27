# NexProctor face detection (MediaPipe)

Client-side face detection using Google MediaPipe **Face Detector**
(`@mediapipe/tasks-vision` 0.10.21) and the **BlazeFace short-range** model.

## Files

| Path | Purpose |
|------|---------|
| `vision_bundle.mjs` | MediaPipe JS (ESM) |
| `wasm/` | WebAssembly runtime (SIMD + no-SIMD) |
| `blaze_face_short_range.tflite` | Face detector model |

All processing runs in the browser. Face images are **not** sent to a third-party service.

## Licence

- MediaPipe Tasks: Apache-2.0 (Google)
- Model: see MediaPipe model cards / Google AI Edge terms

## Server note

Ensure the web server serves `.wasm` as `application/wasm` and `.mjs` as
`application/javascript` (or `text/javascript`). Most Apache/Nginx defaults are fine.
