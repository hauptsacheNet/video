<?php
$EM_CONF[$_EXTKEY] = [
    'title' => 'Video',
    'description' => 'TYPO3 extension that compresses videos during upload to 720p H.264 MP4 using ffmpeg.wasm for optimal compatibility and reduced storage. Additionally generates embedded poster images for improved video previews.',
    'category' => 'plugin',
    'author' => 'Marco Pfeiffer',
    'author_email' => 'marco@hauptsache.net',
    'author_company' => 'hauptsache.net',
    'state' => 'stable',
    'version' => '2.1.0',
    'constraints' => [
        'depends' => [
            'php' => '8.0.0-8.9.99',
            'typo3' => '12.4.2-13.9.99',
        ],
        'conflicts' => [
        ],
        'suggests' => [
        ],
    ],
];