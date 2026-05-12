<?php
$EM_CONF[$_EXTKEY] = [
    'title' => 'Video',
    'description' => 'TYPO3 extension that compresses videos during upload to 720p H.264 MP4 using ffmpeg.wasm for optimal compatibility, storage, and performance.',
    'category' => 'plugin',
    'author' => 'Marco Pfeiffer',
    'author_email' => 'marco@hauptsache.net',
    'author_company' => 'hauptsache.net',
    'state' => 'stable',
    'version' => '2.0.1',
    'constraints' => [
        'depends' => [
            'php' => '8.2.0-8.99.99',
            'typo3' => '13.4.0-14.99.99',
        ],
        'conflicts' => [
        ],
        'suggests' => [
        ],
    ],
];