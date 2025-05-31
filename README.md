[![Packagist Version](https://img.shields.io/packagist/v/hn/video.svg)](https://packagist.org/packages/hn/video)
[![Packagist](https://img.shields.io/packagist/l/hn/video.svg)](https://packagist.org/packages/hn/video)
[![Packagist](https://img.shields.io/packagist/dt/hn/video.svg)](https://packagist.org/packages/hn/video)
[![Packagist](https://img.shields.io/packagist/dm/hn/video.svg)](https://packagist.org/packages/hn/video)

## what does this extension do

- It compresses videos during the upload process to 720p h264 mp4 file using a [web assembly version of ffmpeg](https://ffmpegwasm.netlify.app).
  This means there are no server dependencies for video compression.
  - This allows you to serve well compressed videos in a universally compatible format
  - It save storage space on your server by not uploading the original files
  - It potentially helps users upload videos that have a slow internet connection
- It automatically generates and embeds poster images (thumbnails) in MP4 files
  - Extracts a frame from the video and embeds it as cover art in the MP4
  - TYPO3's file processing system can extract these thumbnails for preview purposes
  - Automatically adds poster attributes to `<video>` tags in frontend output
  - Provides better video file overview in the fileadmin and improved user experience

![recording.clip.gif](recording.clip.gif)

## installation

Running multithreaded WebAssembly comes with certain security requirements.
To address these, browser vendors enforce the use of [specific cross-origin protections](https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/SharedArrayBuffer#security_requirements).

For this extension to function correctly, the backend and JavaScript files require the following HTTP headers:

```yaml
Cross-Origin-Opener-Policy: same-origin
Cross-Origin-Embedder-Policy: require-corp
```

These headers can be configured in your .htaccess file or Apache server configuration as shown below:

```apacheconf
<IfModule mod_headers.c>
    Header set Cross-Origin-Opener-Policy "same-origin"
    Header set Cross-Origin-Embedder-Policy "require-corp"
</IfModule>
```

Adding these headers globally to your frontend might introduce unintended side effects. If you try to set it only for specific folders, make sure that all resources either have the header or don't have the header. Mixing them within a document leads to errors.

If the required headers are not properly configured, the extension will display a warning when accessing views containing the file uploader (e.g., the file list view).

### PHP Upload Size Configuration

When working with video uploads, you should adjust your PHP configuration to accommodate larger file sizes. You can expect up to 30MB per minute of video, so set your upload limits accordingly. Add or modify the following settings in your PHP configuration (php.ini) or .htaccess file:

```apacheconf
# In php.ini
upload_max_filesize = 300M
post_max_size = 300M

# Or in .htaccess (with mod_php only)
<IfModule mod_php.c>
    php_value upload_max_filesize 300M
    php_value post_max_size 300M
</IfModule>
```

## known issues

- Empty folders in the Filelist have an upload button that avoids the drag-uploader in TYPO3 13.
- Videos that skip conversion (already optimized H.264 720p or smaller) may have embedded thumbnails that PHP cannot process correctly. This results in missing thumbnails in the file list. This primarily affects videos that were already processed by the extension and re-uploaded.

## testing thumbnail extraction

You can test the thumbnail extraction functionality using the built-in commands:

```bash
# Test thumbnail extraction from a video file
docker compose exec php vendor/bin/typo3 video:test-thumbnail fileadmin/your-video.mp4

# Test file list thumbnail generation (like the TYPO3 backend does)
docker compose exec php vendor/bin/typo3 video:test-filelist fileadmin/your-video.mp4

# Test video tag generation with poster images (frontend rendering)
docker compose exec php vendor/bin/typo3 video:test-renderer fileadmin/your-video.mp4

# Run automated unit tests
docker compose exec php bash -c "cd packages/video && ../../vendor/bin/phpunit"
```

The test commands will:
- Analyze the video file to check for embedded poster images
- Extract the embedded poster image using pure PHP (no server-side FFmpeg required)
- Generate thumbnails in various sizes like the TYPO3 backend
- Test frontend video tag generation with automatic poster attributes
- Display detailed information about the extraction and rendering process

## automated testing

The extension includes comprehensive PHPUnit tests that verify:
- JPEG detection and extraction from MP4 files
- Data validation and image integrity
- Performance and consistency
- Error handling for edge cases

Run tests with: `docker compose exec php bash -c "cd packages/video && ../../vendor/bin/phpunit --testdox"`

## future plans

- allow for quality configuration
  - the 720p default is a pretty good compromise between quality, compatibility and file size, but you might have different requirements
- hook into the file upload process to create HLS video fragments
  - reliably serve your videos to clients with a bad connection by offering different resolutions
  - can improve upload speeds with slow internet connections
  - avoid max upload size limits on your hoster
  - Cut videos by just modifying the playlist file. e.g. cut out the audio etc. Maybe even a tiny video editor in the backend.
- implement some form of optional server side video conversion
  - allows to use more complex video formats like av1 (which would take forever in wasm)
  - reduces requirements on the client computer (although increases internet bandwith requirement)

## v1 vs v2

The old v1 video extension did work completely differently.
It assumed that you upload original video files and that you want to exactly specify what format to use every time you embed a video.
It therefore had to use server side video conversion or an api service.
It was way too complicated for most use cases.

v2 is a completely different extension with a much simpler approach that will likely fit more users.