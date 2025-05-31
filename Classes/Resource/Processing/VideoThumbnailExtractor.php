<?php

declare(strict_types=1);

namespace Hn\Video\Resource\Processing;

use TYPO3\CMS\Core\Core\Environment;
use Hn\Video\Mp4\Mp4SimpleExtractor;
use TYPO3\CMS\Core\Imaging\GraphicalFunctions;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\Processing\LocalPreviewHelper;
use TYPO3\CMS\Core\Resource\Processing\ProcessorInterface;
use TYPO3\CMS\Core\Resource\Processing\TaskInterface;
use TYPO3\CMS\Core\Type\File\ImageInfo;
use TYPO3\CMS\Core\Utility\CommandUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Extracts thumbnails from video files, specifically from MP4 files with embedded poster images
 */
class VideoThumbnailExtractor implements ProcessorInterface
{
    /**
     * Returns TRUE if this processor can process the given task
     */
    public function canProcessTask(TaskInterface $task): bool
    {
        // Process both preview and crop/scale/mask tasks for video files
        return $task->getType() === 'Image'
            && in_array($task->getName(), ['Preview', 'CropScaleMask'], true)
            && in_array($task->getSourceFile()->getMimeType(), [
                'video/mp4',
                'video/quicktime',
                'video/x-msvideo',
                'video/x-flv',
                'video/webm',
                'video/ogg',
                'video/x-matroska'
            ], true);
    }

    /**
     * Processes the given task
     */
    public function processTask(TaskInterface $task): void
    {
        try {
            $result = $this->processVideoTask($task);
            if ($result === null) {
                $task->setExecuted(true);
                $task->getTargetFile()->setUsesOriginalFile();
            } elseif (!empty($result['filePath']) && file_exists($result['filePath'])) {
                $task->setExecuted(true);
                $imageInformation = GeneralUtility::makeInstance(ImageInfo::class, $result['filePath']);
                $task->getTargetFile()->setName($task->getTargetFileName());
                $task->getTargetFile()->updateProperties([
                    'width' => $imageInformation->getWidth(),
                    'height' => $imageInformation->getHeight(),
                    'size' => $imageInformation->getSize(),
                    'checksum' => $task->getConfigurationChecksum(),
                ]);
                $task->getTargetFile()->updateWithLocalFile($result['filePath']);
            } else {
                // Failed to extract/process thumbnail
                $task->setExecuted(false);
            }
        } catch (\Exception $e) {
            error_log("Video thumbnail processing failed: " . $e->getMessage());
            $task->setExecuted(false);
        }
    }

    /**
     * Process video task and return result similar to LocalPreviewHelper
     */
    protected function processVideoTask(TaskInterface $task): ?array
    {
        $sourceFile = $task->getSourceFile();
        $configuration = $task->getConfiguration();
        
        // Extract thumbnail from video
        $tempFile = $this->extractVideoThumbnail($sourceFile);
        
        if ($tempFile === null) {
            return null;
        }
        
        // Generate final processed file
        $targetFilePath = $this->getTemporaryFilePath($task);
        $result = $this->resizeThumbnail($tempFile, $targetFilePath, $configuration);
        
        // Clean up temp file
        unlink($tempFile);
        
        return $result;
    }

    /**
     * Returns the path to a temporary file for processing
     */
    protected function getTemporaryFilePath(TaskInterface $task): string
    {
        return GeneralUtility::tempnam('video_thumb_', '.' . $task->getTargetFileExtension());
    }

    /**
     * Extract thumbnail from video file
     * Uses PHP MP4 parser to extract embedded poster image from MP4 files
     */
    protected function extractVideoThumbnail(File $sourceFile): ?string
    {
        $sourcePath = $sourceFile->getForLocalProcessing(false);
        $tempPath = Environment::getVarPath() . '/transient/' . uniqid('video_thumb_') . '.jpg';
        
        // Ensure transient directory exists
        $transientDir = dirname($tempPath);
        if (!is_dir($transientDir)) {
            GeneralUtility::mkdir_deep($transientDir);
        }
        
        // Try to extract embedded poster image from MP4 using simple extractor
        if ($sourceFile->getMimeType() === 'video/mp4') {
            try {
                $jpegData = Mp4SimpleExtractor::extractJpeg($sourcePath);
                
                if ($jpegData !== null) {
                    file_put_contents($tempPath, $jpegData);
                    return $tempPath;
                }
            } catch (\Exception $e) {
                // Log error but continue - this is not a critical failure
                error_log("MP4 JPEG extraction failed: " . $e->getMessage());
            }
        }
        
        // For non-MP4 files or if extraction failed, we cannot extract thumbnails
        // without FFmpeg. This is acceptable since the main use case is MP4 files
        // with embedded poster images from our own video converter.
        return null;
    }


    /**
     * Resize the thumbnail to the requested dimensions
     */
    protected function resizeThumbnail(string $sourcePath, string $targetFilePath, array $configuration): array
    {
        // Parse dimensions from configuration (similar to LocalPreviewHelper)
        $width = $this->parseDimension($configuration['width'] ?? 0);
        $height = $this->parseDimension($configuration['height'] ?? 0);
        $maxWidth = (int)($configuration['maxWidth'] ?? 0);
        $maxHeight = (int)($configuration['maxHeight'] ?? 0);
        
        // If no dimensions specified, just copy the file
        if ($width === 0 && $height === 0 && $maxWidth === 0 && $maxHeight === 0) {
            copy($sourcePath, $targetFilePath);
            return ['filePath' => $targetFilePath];
        }
        
        // Get image info
        $imageInfo = GeneralUtility::makeInstance(ImageInfo::class, $sourcePath);
        $sourceWidth = $imageInfo->getWidth();
        $sourceHeight = $imageInfo->getHeight();
        
        // Validate source dimensions to prevent division by zero
        if ($sourceWidth <= 0 || $sourceHeight <= 0) {
            // Invalid image dimensions - just copy the original
            copy($sourcePath, $targetFilePath);
            return ['filePath' => $targetFilePath];
        }
        
        // Calculate target dimensions
        if ($maxWidth > 0 || $maxHeight > 0) {
            // Calculate dimensions respecting max constraints
            $ratio = min(
                $maxWidth > 0 ? $maxWidth / $sourceWidth : PHP_FLOAT_MAX,
                $maxHeight > 0 ? $maxHeight / $sourceHeight : PHP_FLOAT_MAX,
                1.0 // Don't upscale
            );
            $targetWidth = (int)round($sourceWidth * $ratio);
            $targetHeight = (int)round($sourceHeight * $ratio);
        } else {
            // Use explicit width/height
            if ($width === 0) {
                $targetWidth = (int)round($sourceWidth * ($height / $sourceHeight));
                $targetHeight = $height;
            } elseif ($height === 0) {
                $targetWidth = $width;
                $targetHeight = (int)round($sourceHeight * ($width / $sourceWidth));
            } else {
                $targetWidth = $width;
                $targetHeight = $height;
            }
        }
        
        // Use GraphicalFunctions to resize
        $graphicalFunctions = GeneralUtility::makeInstance(GraphicalFunctions::class);
        $result = $graphicalFunctions->resize($sourcePath, 'WEB', $targetWidth, $targetHeight, '', ['sample' => true]);
        
        if ($result && $result->getRealPath()) {
            // Move the result to our target path
            rename($result->getRealPath(), $targetFilePath);
            return ['filePath' => $targetFilePath];
        } else {
            // Fallback: just copy original
            copy($sourcePath, $targetFilePath);
            return ['filePath' => $targetFilePath];
        }
    }
    
    /**
     * Parse dimension value (handle 'c' suffix for crop mode)
     */
    protected function parseDimension($value): int
    {
        if (is_string($value) && str_ends_with($value, 'c')) {
            return (int)rtrim($value, 'c');
        }
        return (int)$value;
    }

    /**
     * Test method to extract thumbnail from a video file
     * Can be used for testing the extraction functionality
     */
    public static function testExtractThumbnail(string $videoPath, string $outputPath): bool
    {
        try {
            $jpegData = Mp4SimpleExtractor::extractJpeg($videoPath);
            
            if ($jpegData !== null) {
                return file_put_contents($outputPath, $jpegData) !== false;
            }
        } catch (\Exception $e) {
            error_log("MP4 JPEG extraction failed: " . $e->getMessage());
        }
        
        return false;
    }

    /**
     * Test method to analyze video file structure
     */
    public static function analyzeVideoFile(string $videoPath): array
    {
        try {
            $hasJpeg = Mp4SimpleExtractor::hasEmbeddedJpeg($videoPath);
            
            return [
                'has_attached_picture' => $hasJpeg,
                'method' => $hasJpeg ? 'simple_extractor' : 'none'
            ];
        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage(),
                'has_attached_picture' => false,
                'method' => 'none'
            ];
        }
    }
}