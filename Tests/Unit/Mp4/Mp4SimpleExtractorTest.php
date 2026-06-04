<?php

declare(strict_types=1);

namespace Hn\Video\Tests\Unit\Mp4;

use Hn\Video\Mp4\Mp4SimpleExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Test case for MP4 JPEG extraction functionality
 */
class Mp4SimpleExtractorTest extends TestCase
{
    private const TEST_VIDEO_PATH = __DIR__ . '/../../Fixtures/test_video.mp4';
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Skip tests if test video doesn't exist
        if (!file_exists(self::TEST_VIDEO_PATH)) {
            $this->markTestSkipped('Test video file not found: ' . self::TEST_VIDEO_PATH);
        }
    }
    
    /**
     * @test
     */
    public function hasEmbeddedJpegReturnsBooleanForValidFile(): void
    {
        $hasJpeg = Mp4SimpleExtractor::hasEmbeddedJpeg(self::TEST_VIDEO_PATH);
        $this->assertIsBool($hasJpeg);
    }
    
    /**
     * @test
     */
    public function hasEmbeddedJpegReturnsFalseForNonExistentFile(): void
    {
        $hasJpeg = Mp4SimpleExtractor::hasEmbeddedJpeg('/path/to/nonexistent/file.mp4');
        $this->assertFalse($hasJpeg);
    }
    
    /**
     * @test
     */
    public function extractJpegReturnsNullForNonExistentFile(): void
    {
        $jpegData = Mp4SimpleExtractor::extractJpeg('/path/to/nonexistent/file.mp4');
        $this->assertNull($jpegData);
    }
    
    /**
     * @test
     */
    public function canDetectEmbeddedJpegInTestVideo(): void
    {
        $hasEmbeddedJpeg = Mp4SimpleExtractor::hasEmbeddedJpeg(self::TEST_VIDEO_PATH);
        
        // If our video converter worked correctly, this should be true
        $this->assertTrue($hasEmbeddedJpeg, 'Test video should have an embedded JPEG from our converter');
    }
    
    /**
     * @test
     */
    public function canExtractValidJpegFromTestVideo(): void
    {
        $jpegData = Mp4SimpleExtractor::extractJpeg(self::TEST_VIDEO_PATH);
        
        $this->assertNotNull($jpegData, 'Should extract JPEG data from test video');
        $this->assertIsString($jpegData);
        $this->assertGreaterThan(1000, strlen($jpegData), 'JPEG data should be substantial (>1KB)');
        $this->assertLessThan(5 * 1024 * 1024, strlen($jpegData), 'JPEG data should be reasonable size (<5MB)');
        
        // Verify it's actually JPEG data by checking magic bytes
        $this->assertEquals("\xFF\xD8", substr($jpegData, 0, 2), 'Should start with JPEG magic bytes');
        
        // Should end with JPEG end marker
        $this->assertEquals("\xFF\xD9", substr($jpegData, -2), 'Should end with JPEG end marker');
    }
    
    /**
     * @test
     */
    public function extractedJpegCanBeSavedAndValidated(): void
    {
        $jpegData = Mp4SimpleExtractor::extractJpeg(self::TEST_VIDEO_PATH);
        
        if ($jpegData === null) {
            $this->markTestSkipped('No embedded JPEG to test with');
        }
        
        // Save to temporary file
        $tempFile = sys_get_temp_dir() . '/mp4_test_' . uniqid() . '.jpg';
        $bytesWritten = file_put_contents($tempFile, $jpegData);
        $this->assertNotFalse($bytesWritten, 'Should be able to save JPEG data');
        $this->assertEquals(strlen($jpegData), $bytesWritten, 'All bytes should be written');
        
        // Verify the file can be read as an image
        $imageInfo = getimagesize($tempFile);
        $this->assertIsArray($imageInfo, 'Should be a valid image file');
        $this->assertEquals(IMAGETYPE_JPEG, $imageInfo[2], 'Should be JPEG format');
        $this->assertGreaterThan(0, $imageInfo[0], 'Should have valid width');
        $this->assertGreaterThan(0, $imageInfo[1], 'Should have valid height');
        
        // Test that image dimensions are reasonable for a video thumbnail
        $this->assertLessThanOrEqual(2000, $imageInfo[0], 'Width should be reasonable for thumbnail');
        $this->assertLessThanOrEqual(2000, $imageInfo[1], 'Height should be reasonable for thumbnail');
        
        // Clean up
        unlink($tempFile);
    }
    
    /**
     * @test
     */
    public function extractJpegIsConsistentAcrossMultipleCalls(): void
    {
        $jpegData1 = Mp4SimpleExtractor::extractJpeg(self::TEST_VIDEO_PATH);
        $jpegData2 = Mp4SimpleExtractor::extractJpeg(self::TEST_VIDEO_PATH);
        
        if ($jpegData1 === null) {
            $this->markTestSkipped('No embedded JPEG to test with');
        }
        
        $this->assertEquals($jpegData1, $jpegData2, 'Multiple extractions should return identical data');
        $this->assertEquals(strlen($jpegData1), strlen($jpegData2), 'JPEG data length should be consistent');
    }
    
    /**
     * @test
     */
    public function extractJpegPerformanceIsReasonable(): void
    {
        $startTime = microtime(true);
        $jpegData = Mp4SimpleExtractor::extractJpeg(self::TEST_VIDEO_PATH);
        $endTime = microtime(true);
        
        $duration = $endTime - $startTime;
        
        if ($jpegData === null) {
            $this->markTestSkipped('No embedded JPEG to test with');
        }
        
        // Extraction should complete within 5 seconds for a ~9MB video
        $this->assertLessThan(5.0, $duration, 'JPEG extraction should complete within 5 seconds');
        
        // Log performance for reference
        $fileSizeMB = filesize(self::TEST_VIDEO_PATH) / (1024 * 1024);
        $this->addToAssertionCount(1); // Count this as an assertion
        // Performance info: processed {$fileSizeMB}MB in {$duration}s
    }
}