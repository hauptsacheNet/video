<?php

return [
    'video:test-thumbnail' => [
        'class' => \Hn\Video\Command\VideoThumbnailTestCommand::class,
        'schedulable' => false,
    ],
    'video:test-filelist' => [
        'class' => \Hn\Video\Command\TestFileListThumbnailCommand::class,
        'schedulable' => false,
    ],
    'video:test-renderer' => [
        'class' => \Hn\Video\Command\TestVideoRendererCommand::class,
        'schedulable' => false,
    ],
];