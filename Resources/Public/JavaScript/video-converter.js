import { FFmpeg } from '@ffmpeg/ffmpeg';

/**
 * @param videoFile {File} The original video file
 * @param onProgress {(progress: number) => void} A callback that gets the progress event as parameter
 * @returns {Promise<File>} The final mp4 file
 */
export async function createMp4File (videoFile, onProgress) {
    const ffmpeg = new FFmpeg();
    await ffmpeg.load({
        // coreURL: import.meta.resolve(`@ffmpeg/core/ffmpeg-core.js`),
        // wasmURL: import.meta.resolve(`@ffmpeg/core/ffmpeg-core.wasm`),
        coreURL: import.meta.resolve(`@ffmpeg/core-mt/ffmpeg-core.js`),
        wasmURL: import.meta.resolve(`@ffmpeg/core-mt/ffmpeg-core.wasm`),
        workerURL: import.meta.resolve(`@ffmpeg/core-mt/ffmpeg-core.worker.js`),
    });
    ffmpeg.on("log", ({message}) => console.log(message));
    ffmpeg.on("progress", ({progress}) => onProgress(progress));
    const params = [];

    // reduce multi threading for filters ~ it appears to be broken in some cases
    // the encoder still runs in multiple threads
    params.push('-filter_threads', '1');

    // mount the file using WORKERFS. That way, we don't need to load the file into memory
    await ffmpeg.createDir('input');
    await ffmpeg.mount('WORKERFS', { blobs: [{ name: 'input', data: videoFile }] }, '/input');
    params.push('-i', `input/input`);

    params.push('-vf', 'scale=w=1280:h=720:force_original_aspect_ratio=decrease:force_divisible_by=2');
    params.push('-c:v', 'libx264'); // encoder/codec
    params.push('-crf:v', '21', '-maxrate:v', '4M', '-bufsize:v', '8M'); // quality - max 0.5 mbyte/sec, 30 mbyte/min
    params.push('-level:v', '3.2', '-profile:v', 'high', '-pix_fmt:v', 'yuv420p'); // compatibility
    // NOTE: There is no easy way to limit fps without potentially introducing stutter or messing with intent, so I don't

    params.push('-c:a', 'aac'); // encoder/codec
    params.push('-b:a', '128k'); // quality
    // NOTE: I don't mess with sample rate or even channel count and hope ffmpeg uses sensible defaults

    params.push('-f', 'mp4');
    params.push('-movflags', '+faststart'); // important: move metadata to the beginning of the video
    params.push('output.mp4');

    await ffmpeg.exec(params);
    const resultFileName = videoFile.name.replace(/\.[^.]+$|$/, '.mp4');
    const result = new File([await ffmpeg.readFile('output.mp4')], resultFileName, {type: 'video/mp4'});
    ffmpeg.terminate();
    if (result.size < 100) {
        throw new Error("Conversion failed for unknown reasons. See Browser Console for more details.")
    }

    return result;
}

/**
 * @param videoFile {File} The original video file
 * @param onProgress {(progress: number) => void} A callback that gets the progress event as parameter
 * @param emitFile {(file: File) => void} A callback that gets the fragment files as parameter
 * @returns {Promise<File>} The final m3u8 playlist file
 */
export async function createHlsFiles (videoFile, onProgress, emitFile) {
    const ffmpeg = new FFmpeg();
    ffmpeg.on("log", ({message}) => console.log(message));
    ffmpeg.on("progress", ({progress}) => onProgress(progress));
    await ffmpeg.load({
        // coreURL: import.meta.resolve(`@ffmpeg/core/ffmpeg-core.js`),
        // wasmURL: import.meta.resolve(`@ffmpeg/core/ffmpeg-core.wasm`),
        coreURL: import.meta.resolve(`@ffmpeg/core-mt/ffmpeg-core.js`),
        wasmURL: import.meta.resolve(`@ffmpeg/core-mt/ffmpeg-core.wasm`),
        workerURL: import.meta.resolve(`@ffmpeg/core-mt/ffmpeg-core.worker.js`),
    });

    const params = [];

    // disable multi threading for filters ~ it appears to be broken in some cases
    // the encoder still runs in multiple threads
    params.push('-filter_threads', '1');
    params.push('-filter_complex_threads', '1');

    // mount the file using WORKERFS. That way, we don't need to load the file into memory
    await ffmpeg.createDir('input');
    await ffmpeg.mount('WORKERFS', { blobs: [{ name: 'input', data: videoFile }] }, '/input');
    params.push('-i', `input/input`);

    params.push('-filter_complex', [
        '[0:v]split=2[v1][v2]', // split video into two streams
        '[v1]scale=w=1280:h=720:force_original_aspect_ratio=decrease:force_divisible_by=2[720p]', // scale first stream to 720p
        '[v2]scale=w=640:h=360:force_original_aspect_ratio=decrease:force_divisible_by=2[360p]', // scale second stream to 360p
    ].join(';'));

    params.push('-map', '[720p]');
    params.push('-c:v:0', 'libx264'); // encoder/codec
    params.push('-crf:v:0', '21', '-maxrate:v:0', '4M', '-bufsize:v:0', '8M'); // quality - max 0.5 mbyte/sec, 30 mbyte/min
    params.push('-level:v:0', '3.2', '-profile:v:0', 'high', '-pix_fmt:v:0', 'yuv420p'); // compatibility

    params.push('-map', '[360p]');
    params.push('-c:v:1', 'libx264'); // encoder/codec
    params.push('-crf:v:1', '20', '-maxrate:v:1', '2M', '-bufsize:v:1', '4M'); // quality - max 0.2 mbyte/sec, 12 mbyte/min
    params.push('-level:v:1', '3.0', '-profile:v:1', 'main', '-pix_fmt:v:1', 'yuv420p'); // compatibility

    params.push('-map', '0:a');
    params.push('-c:a:0', 'aac'); // encoder/codec
    params.push('-b:a:0', '128k'); // quality

    params.push('-var_stream_map', [
        'v:0,name:720p,agroup:main',
        'v:1,name:360p,agroup:main',
        'a:0,name:audio,agroup:main,default:yes'
    ].join(' '));

    params.push('-f', 'hls', '-hls_time', '2', '-hls_playlist_type', 'vod');
    params.push('-hls_flags', 'independent_segments');
    params.push('-hls_segment_type', 'mpegts'); // TODO test with fmp4
    params.push('-hls_segment_filename', `%v_%03d.ts`);
    params.push('-master_pl_name', 'output.m3u8');
    params.push('%v.m3u8');

    ffmpeg.on("log", ({message}) => {
        const match = message.match(/Opening '([^']+)' for writing/);
        if (match && match[1] !== 'output.m3u8') {
            console.log('try to read', match[1]);
            ffmpeg.readFile(match[1])
                .then(data => {
                    emitFile(new File([data], match[1]));
                    console.log("successfully read file", match[1]);
                })
                .catch(console.error);

        }
    });

    await ffmpeg.exec(params);

    // output the final playlist file separately
    const m3u8FileName = videoFile.name.replace(/\.[^.]+$|$/, '.m3u8');
    const result = new File([await ffmpeg.readFile('output.m3u8')], m3u8FileName, {type: 'application/x-mpegURL'});
    ffmpeg.terminate();
    return result;
}