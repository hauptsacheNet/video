# Custom @ffmpeg/core-mt build (2 GiB heap)

The files in `Resources/Public/node_modules/@ffmpeg/core-mt/dist/esm/`
(`ffmpeg-core.js`, `ffmpeg-core.wasm`, `ffmpeg-core.worker.js`) are **not** the
stock npm release. They are a custom build of `@ffmpeg/core-mt` 0.12.10 with the
WASM heap raised from 1 GiB to 2 GiB.

## Building

From the extension root:

```bash
bash Build/ffmpeg-core/build.sh
```

The script is self-contained and reproducible: it clones the pinned upstream
commit into a temp directory, patches it, builds in Docker, copies the three
output files into `Resources/Public/node_modules/@ffmpeg/core-mt/dist/esm/`,
and verifies that both the JS glue and the `.wasm` actually declare a 2 GiB
heap. A cold build takes roughly an hour; Docker layer caching makes reruns
much faster.

### Requirements

- Docker 23+ with buildx. The build exports to a local directory, which the
  default `docker` driver cannot do — the script automatically creates a
  `docker-container` builder named `ffmpeg-core-builder` on first run.
- `make`, `git`, `python3` on the host.

### What the script does

`build.sh` follows the official build docs
(<https://ffmpegwasm.netlify.app/docs/contribution/core/>):

1. Fetches `ffmpegwasm/ffmpeg.wasm` at the pinned commit `f876f90`
   (the release that produced core-mt 0.12.10).
2. Applies `0001-raise-core-mt-memory-to-2GB.patch`:
   - `build/ffmpeg-wasm.sh`: `-sINITIAL_MEMORY=1024MB` → `2048MB` for the MT
     core. Without memory growth, emscripten sets
     `MAXIMUM_MEMORY = INITIAL_MEMORY`, so the binary declares a fixed 2 GiB
     shared heap.
   - `build/ffmpeg.sh`: `emmake make -j` → `-j4`. Unbounded parallelism can
     OOM-kill the BuildKit container on smaller Docker VMs; this does not
     affect the produced binary.
3. Runs `make prd-mt` (Docker/emscripten toolchain) and installs the output
   into the extension, failing loudly if the result does not declare
   min = max = 32768 pages (2 GiB, shared).

## Why a custom build

The multi-threaded ffmpeg.wasm core uses a **fixed, non-growable** shared heap
(`ALLOW_MEMORY_GROWTH` is not recommended with pthreads, so upstream compiles a
fixed 1 GiB and the `.wasm` hard-declares that maximum). Transcoding large/4K
uploads exceeds 1 GiB and aborts with `RuntimeError: Aborted(OOM)` — surfacing in
the backend as the confusing `TypeError: e.message is undefined` because the OOM
originates in a pthread worker whose error object has no `.message`.

The limit cannot be raised from JavaScript: instantiating a larger
`WebAssembly.Memory` than the module declares is a `LinkError`. The core must be
recompiled.

## Caveats

- `ffmpeg-core.js` and `ffmpeg-core.wasm` must always come from the **same**
  build: the glue's `INITIAL_MEMORY` default and the wasm's declared memory
  limits have to match, otherwise instantiation fails with a `LinkError`.
- Running `npm install` in `Resources/Public/` must not be allowed to overwrite
  `@ffmpeg/core-mt` with the stock release, or the 1 GiB OOM returns.
