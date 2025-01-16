[![Packagist Version](https://img.shields.io/packagist/v/hn/video.svg)](https://packagist.org/packages/hn/video)
[![Packagist](https://img.shields.io/packagist/l/hn/video.svg)](https://packagist.org/packages/hn/video)
[![Packagist](https://img.shields.io/packagist/dt/hn/video.svg)](https://packagist.org/packages/hn/video)
[![Packagist](https://img.shields.io/packagist/dm/hn/video.svg)](https://packagist.org/packages/hn/video)

## what does this extension do

- At the moment, this extension compresses videos during the upload process to 720p h264 mp4 file using a [web assembly version of ffmpeg](https://ffmpegwasm.netlify.app).
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

Adding these headers globally to your frontend might introduce unintended side effects. To minimize such issues, consider restricting the headers to the following URLs:

- `/typo3`: Covers the entire backend; omitting some urls may cause certain iframes to stop functioning.
- `/typo3conf/ext/video/Resources/Public`: Includes the worker script and WebAssembly files.

If the required headers are not properly configured, the extension will display a warning when accessing views containing the file uploader (e.g., the file list view).

## known issues

- Empty folders in the Filelist have an upload button that avoids the drag-uploader in TYPO3 13.

## future plans

- allow for quality configuration
  - the 720p default is a pretty good compromise between quality, compatibility and file size, but you might have different requirements
- create posters and thumbnails for video files
  - this would allow to populate the poster property of the `<video>` tag as a placeholder before playing the video
  - it could give a better overview within the fileadmin, where videos currently have no thumbnail/preview
- hook into the file upload process to create HLS video fragments
  - reliably serve your videos to clients with a bad connection by offering different resolutions
  - can improve upload speeds with slow internet connections
  - avoid max upload size limits on your hoster
  - Cut videos by just modifying the playlist file. e.g. cut out the audio etc. Maybe even a tiny video editor in the backend.
- implement some form of optional server side video conversion
  - allows to use more complex video formats like av1 (which would take forever in wasm)
  - reduces requirements on the client computer (although increases internet bandwith requirement)

Video compression is done within the browser so there is no server dependency for video compression.

## v1 vs v2

The old v1 video extension did work completely differently.
It assumed that you upload original video files and that you want to exactly specify what format to use every time you embed a video.
It therefore had to use server side video conversion or an api service.
It was way too complicated for most use cases.

v2 is a completely different extension with a much simpler approach that will likely fit more users.