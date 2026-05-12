[![Packagist Version](https://img.shields.io/packagist/v/hn/video.svg)](https://packagist.org/packages/hn/video)
[![Packagist](https://img.shields.io/packagist/l/hn/video.svg)](https://packagist.org/packages/hn/video)
[![Packagist](https://img.shields.io/packagist/dt/hn/video.svg)](https://packagist.org/packages/hn/video)
[![Packagist](https://img.shields.io/packagist/dm/hn/video.svg)](https://packagist.org/packages/hn/video)

Compatible with TYPO3 13 LTS and TYPO3 14.

## what does this extension do

- It compresses videos during the upload process to 720p h264 mp4 file using a [web assembly version of ffmpeg](https://ffmpegwasm.netlify.app).
  This means there are no server dependencies for video compression.
  - This allows you to serve well compressed videos in a universally compatible format
  - It save storage space on your server by not uploading the original files
  - It potentially helps users upload videos that have a slow internet connection

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

## development

End-to-end tests live under `Build/tests/playwright/` and exercise the override
in a real TYPO3 backend (login, importmap rewiring, ffmpeg.wasm conversion).

```bash
# Docker (default): MySQL + chialab/php + Playwright containers.
bash Build/runTests.sh

# Local: host PHP + SQLite + local Playwright (auto-falls back when Docker is unavailable).
bash Build/runTests.sh --no-docker

# Build the TER zip (drops a video_<version>.zip into dist/).
composer build:ter
```

CI runs the matrix on every push and pull request (TYPO3 13.4 and 14.3).
Tagging a GitHub release triggers `release-ter.yml`, which sets the version
in `ext_emconf.php`, builds the zip, attaches it to the release, and publishes
to TER (requires a `TYPO3_API_TOKEN` repository secret).

## future plans

- create posters and thumbnails for video files
  - this would allow to populate the poster property of the `<video>` tag as a placeholder before playing the video
  - it could give a better overview within the fileadmin, where videos currently have no thumbnail/preview
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