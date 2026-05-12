<?php

return [
    'dependencies' => [
        'backend',
    ],
    'tags' => [
        'backend.module',
        'backend.form',
        'backend.navigation-component',
    ],
    'imports' => [
        '@hn/video/' => 'EXT:video/Resources/Public/JavaScript/',

        // override the drag-uploader.js from the backend module
        '@typo3/backend/drag-uploader.js' => 'EXT:video/Resources/Public/JavaScript/drag-uploader.js',
        '@hn/video/typo3/backend/drag-uploader.js' => 'EXT:backend/Resources/Public/JavaScript/drag-uploader.js',

        // add node_modules in the import list
        '@ffmpeg/core-mt/' => 'EXT:video/Resources/Public/node_modules/@ffmpeg/core-mt/dist/esm/',
        '@ffmpeg/ffmpeg' => 'EXT:video/Resources/Public/node_modules/@ffmpeg/ffmpeg/dist/esm/index.js',
        '@ffmpeg/ffmpeg/' => 'EXT:video/Resources/Public/node_modules/@ffmpeg/ffmpeg/dist/esm/',
    ],
];
