<?php

defined('TYPO3') or die();

(function () {
    // Register video thumbnail extractor processor
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['fal']['processors']['VideoThumbnailExtractor'] = [
        'className' => \Hn\Video\Resource\Processing\VideoThumbnailExtractor::class,
        'before' => ['LocalImageProcessor'],
    ];
    
    // Register enhanced video tag renderer with poster support
    // This will be executed after core renderers are registered, giving it priority
    $rendererRegistry = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Resource\Rendering\RendererRegistry::class);
    $rendererRegistry->registerRendererClass(\Hn\Video\Resource\Rendering\VideoTagRendererWithPoster::class);
    unset($rendererRegistry);
})();