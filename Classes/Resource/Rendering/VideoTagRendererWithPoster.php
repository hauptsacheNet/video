<?php

declare(strict_types=1);

namespace Hn\Video\Resource\Rendering;

use Hn\Video\Mp4\Mp4SimpleExtractor;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\Processing\LocalImageProcessor;
use TYPO3\CMS\Core\Resource\Rendering\VideoTagRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * Enhanced video tag renderer that automatically adds poster images from embedded thumbnails
 */
class VideoTagRendererWithPoster extends VideoTagRenderer
{
    /**
     * Returns the priority of the renderer
     * Higher priority than core VideoTagRenderer (1) to override it
     */
    public function getPriority(): int
    {
        return 10;
    }

    /**
     * Render video tag with automatic poster image extraction
     */
    public function render(FileInterface $file, $width, $height, array $options = [], $usedPathsRelativeToCurrentScript = false): string
    {
        // Get the basic video tag from parent
        $videoTag = parent::render($file, $width, $height, $options, $usedPathsRelativeToCurrentScript);
        
        // Try to add poster attribute
        $posterUrl = $this->generatePosterUrl($file);
        
        if ($posterUrl !== null) {
            // Insert poster attribute into the video tag
            $videoTag = str_replace('<video', '<video poster="' . htmlspecialchars($posterUrl) . '"', $videoTag);
        }
        
        return $videoTag;
    }

    /**
     * Generate poster URL from embedded thumbnail or existing processed image
     */
    protected function generatePosterUrl(FileInterface $file): ?string
    {
        // Only process video files
        if (!$this->canRender($file)) {
            return null;
        }

        try {
            // Check if we have an embedded thumbnail
            if (Mp4SimpleExtractor::hasEmbeddedJpeg($file->getForLocalProcessing())) {
                // Generate a processed poster image
                return $this->createProcessedPosterImage($file);
            }
        } catch (\Exception $e) {
            // Silently fail - video will still work without poster
        }
        
        return null;
    }

    /**
     * Create a processed poster image from the embedded thumbnail
     */
    protected function createProcessedPosterImage(FileInterface $file): ?string
    {
        try {
            // First, try using TYPO3's file processing system
            $processingConfiguration = [
                'width' => 640,
                'height' => 360,
                'crop' => false,
            ];

            // Get the underlying file (not FileReference)
            $originalFile = $file instanceof FileReference ? $file->getOriginalFile() : $file;
            
            // Use TYPO3's file processing service
            $processedFile = $originalFile->process(ProcessedFile::CONTEXT_IMAGECROPSCALEMASK, $processingConfiguration);
            
            if ($processedFile && $processedFile->exists()) {
                $posterUrl = $processedFile->getPublicUrl();
                // Ensure we don't return the video file URL as poster
                if ($posterUrl && !str_ends_with($posterUrl, '.mp4')) {
                    return $posterUrl;
                }
            }
            
            // If processing failed, try direct extraction and create a simple processed image
            $jpegData = Mp4SimpleExtractor::extractJpeg($originalFile->getForLocalProcessing());
            if ($jpegData) {
                // Create a unique filename for the poster
                $videoIdentifier = md5($originalFile->getIdentifier());
                $relativePosterPath = 'fileadmin/_processed_/video_posters/poster_' . $videoIdentifier . '.jpg';
                
                // Use TYPO3's proper path utilities
                $absolutePosterPath = Environment::getPublicPath() . '/' . $relativePosterPath;
                
                // Create directory if it doesn't exist
                $posterDir = dirname($absolutePosterPath);
                if (!is_dir($posterDir)) {
                    GeneralUtility::mkdir_deep($posterDir);
                }
                
                // Save the extracted JPEG
                if (file_put_contents($absolutePosterPath, $jpegData)) {
                    // Return web-accessible URL with leading slash
                    return '/' . $relativePosterPath;
                }
            }
        } catch (\Exception $e) {
            // Processing failed, continue without poster
            // This is not critical - video will still work without poster
        }
        
        return null;
    }

    /**
     * Check if this renderer can handle the file
     */
    public function canRender(FileInterface $file): bool
    {
        // Only handle video files that our parent can handle
        return parent::canRender($file) && $file->getMimeType() === 'video/mp4';
    }
}