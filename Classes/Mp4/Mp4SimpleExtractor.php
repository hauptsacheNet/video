<?php

declare(strict_types=1);

namespace Hn\Video\Mp4;

/**
 * Simple MP4 JPEG extractor that searches for JPEG magic bytes
 * More reliable than full MP4 parsing for just extracting embedded images
 */
class Mp4SimpleExtractor
{
    /**
     * Extract JPEG data from MP4 file by searching for JPEG magic bytes
     */
    public static function extractJpeg(string $filePath): ?string
    {
        if (!file_exists($filePath)) {
            return null;
        }
        
        $fileHandle = fopen($filePath, 'rb');
        if (!$fileHandle) {
            return null;
        }
        
        try {
            $fileSize = filesize($filePath);
            $chunkSize = 64 * 1024; // Read 64KB chunks
            $overlap = 10; // Overlap to catch JPEG headers at chunk boundaries
            
            $previousChunk = '';
            $position = 0;
            
            while ($position < $fileSize) {
                // Read chunk with overlap from previous chunk
                fseek($fileHandle, $position);
                $chunk = fread($fileHandle, $chunkSize);
                
                if (empty($chunk)) {
                    break;
                }
                
                // Combine with overlap from previous chunk
                $searchData = $previousChunk . $chunk;
                
                // Search for JPEG start marker with better validation
                $offset = 0;
                while (($jpegStart = strpos($searchData, "\xFF\xD8", $offset)) !== false) {
                    // Check if this is followed by a valid JPEG marker
                    if ($jpegStart + 4 < strlen($searchData)) {
                        $nextBytes = substr($searchData, $jpegStart + 2, 2);
                        // Valid JPEG markers after FFD8: FFE0 (JFIF), FFE1 (Exif), FFDB (quantization), FFC0/FFC2 (SOF), FFFE (comment)
                        if (in_array($nextBytes, ["\xFF\xE0", "\xFF\xE1", "\xFF\xDB", "\xFF\xC0", "\xFF\xC2", "\xFF\xFE"], true)) {
                            // This looks like a real JPEG header
                            break;
                        }
                    }
                    $offset = $jpegStart + 1;
                }
                
                if ($jpegStart !== false && $offset <= $jpegStart) {
                    // Found JPEG start, now find the end (FF D9)
                    $actualPosition = $position - strlen($previousChunk) + $jpegStart;
                    
                    // Read from JPEG start to find the end
                    fseek($fileHandle, $actualPosition);
                    $remainingData = fread($fileHandle, min(5 * 1024 * 1024, $fileSize - $actualPosition)); // Max 5MB
                    
                    // Search for all FFD9 markers and find the correct one
                    // The first FFD9 might appear in JPEG data, so we need to be smarter
                    $lastValidEnd = false;
                    $searchOffset = 0;
                    
                    while (($jpegEnd = strpos($remainingData, "\xFF\xD9", $searchOffset)) !== false) {
                        // Extract up to this potential end marker
                        $jpegData = substr($remainingData, 0, $jpegEnd + 2);
                        
                        // Basic validation: check if this looks like a complete JPEG
                        if (strlen($jpegData) > 100 && substr($jpegData, 0, 2) === "\xFF\xD8") {
                            // Check for JFIF or Exif marker near the beginning
                            $hasJfif = strpos(substr($jpegData, 0, 30), "JFIF") !== false;
                            $hasExif = strpos(substr($jpegData, 0, 30), "Exif") !== false;
                            $hasLavc = strpos(substr($jpegData, 0, 50), "Lavc") !== false;
                            
                            if ($hasJfif || $hasExif || $hasLavc || strlen($jpegData) < 10000) {
                                // This looks like a valid JPEG end
                                $lastValidEnd = $jpegEnd;
                                break;
                            }
                        }
                        
                        // Continue searching after this marker
                        $searchOffset = $jpegEnd + 2;
                        $lastValidEnd = $jpegEnd; // Keep track of the last end marker
                    }
                    
                    if ($lastValidEnd !== false) {
                        // Extract complete JPEG (including end marker)
                        $jpegData = substr($remainingData, 0, $lastValidEnd + 2);
                        
                        // Verify it's a valid size (between 1KB and 5MB)
                        if (strlen($jpegData) > 1024 && strlen($jpegData) < 5 * 1024 * 1024) {
                            // Additional validation: check if it starts with JPEG header
                            if (substr($jpegData, 0, 2) === "\xFF\xD8") {
                                return $jpegData;
                            }
                        }
                    }
                }
                
                // Keep last few bytes for overlap
                $previousChunk = substr($searchData, -$overlap);
                $position += $chunkSize;
            }
            
            return null;
            
        } finally {
            fclose($fileHandle);
        }
    }
    
    /**
     * Check if MP4 file contains embedded JPEG
     */
    public static function hasEmbeddedJpeg(string $filePath): bool
    {
        return self::extractJpeg($filePath) !== null;
    }
}