import { FFmpeg } from '@ffmpeg/ffmpeg';

/**
 * Initializes FFmpeg and mounts the input file
 * @param videoFile {File} The video file to process
 * @param onProgress {(progress: number) => void} Progress callback
 * @returns {Promise<FFmpeg>} Initialized FFmpeg instance with mounted file
 */
async function initFFmpeg(videoFile, onProgress) {
    const ffmpeg = new FFmpeg();
    await ffmpeg.load({
        coreURL: import.meta.resolve(`@ffmpeg/core-mt/ffmpeg-core.js`),
        wasmURL: import.meta.resolve(`@ffmpeg/core-mt/ffmpeg-core.wasm`),
        workerURL: import.meta.resolve(`@ffmpeg/core-mt/ffmpeg-core.worker.js`),
    });
    
    // Set up progress and log handlers
    ffmpeg.on("log", ({message}) => console.log(message));
    ffmpeg.on("progress", ({progress}) => onProgress(progress));
    
    // Mount the file
    await ffmpeg.createDir('input');
    await ffmpeg.mount('WORKERFS', { blobs: [{ name: 'input', data: videoFile }] }, '/input');
    
    return ffmpeg;
}

/**
 * Analyzes video file properties by running a quick FFmpeg command and parsing the output logs
 * @param ffmpeg {FFmpeg} The initialized FFmpeg instance with mounted file
 * @returns {Promise<{
 *   videoCodec: string|null,
 *   videoWidth: number|null,
 *   videoHeight: number|null,
 *   videoBitrate: number|null,
 *   videoFps: number|null,
 *   audioCodec: string|null,
 *   audioBitrate: number|null
 * }>} The video properties
 */
async function analyzeVideo(ffmpeg) {
    // Create a promise that will resolve with the video properties
    return new Promise(async (resolve) => {
        const videoProps = {
            videoCodec: null,
            videoWidth: null,
            videoHeight: null,
            videoBitrate: null,
            videoFps: null,
            audioCodec: null,
            audioBitrate: null
        };
        
        // Set up log handler to capture stream information
        const logHandler = ({ message }) => {
            if (message.includes('Stream #') && message.includes('Video:')) {
                const codecMatch = message.match(/Video: ([a-z0-9]+)/i);
                if (codecMatch) {
                    videoProps.videoCodec = codecMatch[1].toLowerCase();
                }

                const resolutionMatch = message.match(/(\d+)x(\d+)/);
                if (resolutionMatch) {
                    videoProps.videoWidth = parseInt(resolutionMatch[1], 10);
                    videoProps.videoHeight = parseInt(resolutionMatch[2], 10);
                }
                
                const bitrateMatch = message.match(/(\d+) kb\/s/);
                if (bitrateMatch) {
                    videoProps.videoBitrate = parseInt(bitrateMatch[1], 10);
                }
                
                const fpsMatch = message.match(/(\d+(?:\.\d+)?) fps/);
                if (fpsMatch) {
                    videoProps.videoFps = parseFloat(fpsMatch[1]);
                }
            }
            
            // Parse audio stream info
            if (message.includes('Stream #') && message.includes('Audio:')) {
                const codecMatch = message.match(/Audio: ([a-z0-9]+)/i);
                if (codecMatch) {
                    videoProps.audioCodec = codecMatch[1].toLowerCase();
                }
                
                const bitrateMatch = message.match(/(\d+) kb\/s/);
                if (bitrateMatch) {
                    videoProps.audioBitrate = parseInt(bitrateMatch[1], 10);
                }
            }
        };
        
        // Add temporary log handler
        ffmpeg.on("log", logHandler);
        
        try {
            // Run FFmpeg with -i input only to get file information
            await ffmpeg.exec(['-i', 'input/input']);
        } catch (error) {
            // This is expected - FFmpeg exits with error when only using -i without output
            // But we've already captured the stream information from logs
        } finally {
            // Remove the temporary log handler
            ffmpeg.off("log", logHandler);
            resolve(videoProps);
        }
    });
}

/**
 * @param videoFile {File} The original video file
 * @param onProgress {(progress: number) => void} A callback that gets the progress event as parameter
 * @returns {Promise<File>} The final mp4 file
 */
export async function createMp4File (videoFile, onProgress) {
    // Initialize FFmpeg and mount the file (only once)
    const ffmpeg = await initFFmpeg(videoFile, onProgress);
    
    // Analyze the video to determine if conversion is needed
    const videoProps = await analyzeVideo(ffmpeg);
    console.log("Video analysis:", videoProps);
    
    // Extract a smart thumbnail using FFmpeg's thumbnail filter
    const thumbnailParams = [
        '-i', 'input/input',
        '-filter_threads', '1', // disable multi threading for filters (broken in wasm)
        '-vf', 'thumbnail=100,scale=w=640:h=480:force_original_aspect_ratio=decrease:force_divisible_by=2',
        '-vframes', '1', // extract one frame
        '-q:v', '5', // JPEG quality (2-5 is a sensible range)
        'thumbnail_%03d.jpg' // use pattern format
    ];
    
    try {
        await ffmpeg.exec(thumbnailParams);
        console.log("Smart thumbnail extracted successfully");
    } catch (error) {
        console.warn("Failed to extract thumbnail, continuing without it:", error);
    }
    
    const params = [];
    
    // Input files - video and thumbnail
    params.push('-i', 'input/input');
    
    // Check if thumbnail was created successfully (always expect pattern-based file)
    let hasThumbnail = false;
    try {
        const thumbnailData = await ffmpeg.readFile('thumbnail_001.jpg');
        if (thumbnailData && thumbnailData.length > 0) {
            console.log("Thumbnail created successfully, size:", thumbnailData.length, "bytes");
            params.push('-i', 'thumbnail_001.jpg');
            hasThumbnail = true;
        } else {
            console.log("Thumbnail file exists but is empty");
        }
    } catch (error) {
        console.log("No thumbnail available to embed:", error.message);
    }
        
    // reduce multi threading for filters ~ it appears to be broken in some cases
    // the encoder still runs in multiple threads
    params.push('-filter_threads', '1');
    
    // Map video stream
    params.push('-map', '0:v:0');
    
    // Video stream handling
    // Only copy if we have all the information we need and it meets our requirements
    const isH264 = videoProps.videoCodec === 'h264';
    const hasValidDimensions = videoProps.videoWidth !== null && videoProps.videoHeight !== null;
    const isSmallEnough = hasValidDimensions && videoProps.videoWidth <= 1280 && videoProps.videoHeight <= 720;
    const hasValidBitrate = videoProps.videoBitrate !== null;
    const hasReasonableBitrate = hasValidBitrate && videoProps.videoBitrate > 0 && videoProps.videoBitrate <= 4000; // 4Mbps max
    
    if (isH264 && isSmallEnough && hasReasonableBitrate) {
        // Video is already good, just copy it
        console.log("Video stream meets requirements, using copy");
        params.push('-c:v:0', 'copy');
    } else {
        // Video needs conversion
        console.log("Video stream needs conversion");
        
        params.push('-vf', 'scale=w=1280:h=720:force_original_aspect_ratio=decrease:force_divisible_by=2');
        params.push('-c:v:0', 'libx264'); // encoder/codec
        params.push('-crf:v:0', '21', '-maxrate:v:0', '4M', '-bufsize:v:0', '8M'); // quality - max 0.5 mbyte/sec, 30 mbyte/min
        params.push('-level:v:0', '3.2', '-profile:v:0', 'high', '-pix_fmt:v:0', 'yuv420p'); // compatibility
        // NOTE: There is no easy way to limit fps without potentially introducing stutter or messing with intent, so I don't
    }
    
    // Map audio stream
    params.push('-map', '0:a?');
    
    // Audio stream handling
    const isAac = videoProps.audioCodec === 'aac';
    const hasValidAudioBitrate = videoProps.audioBitrate !== null;
    const hasReasonableAudioBitrate = hasValidAudioBitrate && videoProps.audioBitrate <= 128;
    
    if (isAac && hasReasonableAudioBitrate) {
        // Audio is already good, just copy it
        console.log("Audio stream meets requirements, using copy");
        params.push('-c:a', 'copy');
    } else {
        // Audio needs conversion
        console.log("Audio stream needs conversion");
        params.push('-c:a', 'aac'); // encoder/codec
        params.push('-b:a', '128k'); // quality
        // NOTE: I don't mess with sample rate or even channel count and hope ffmpeg uses sensible defaults
    }
    
    // Add thumbnail as attached picture if available
    if (hasThumbnail) {
        params.push('-map', '1'); // map the thumbnail image (input 1)
        params.push('-c:v:1', 'mjpeg'); // ensure it's mjpeg codec
        params.push('-disposition:v:1', 'attached_pic'); // mark it as attached picture
        console.log("Adding smart thumbnail as attached picture to MP4");
    }
    
    // Output format and options
    params.push('-f', 'mp4');
    params.push('-movflags', '+faststart'); // important: move metadata to the beginning of the video
    params.push('output.mp4');
    
    // Execute FFmpeg command
    await ffmpeg.exec(params);
    
    // Create result file
    const resultFileName = videoFile.name.replace(/\.[^.]+$|$/, '.mp4');
    const result = new File([await ffmpeg.readFile('output.mp4')], resultFileName, {type: 'video/mp4'});
    ffmpeg.terminate();
    
    if (result.size < 100) {
        throw new Error("Conversion failed for unknown reasons. See Browser Console for more details.");
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