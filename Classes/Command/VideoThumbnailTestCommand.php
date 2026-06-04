<?php

declare(strict_types=1);

namespace Hn\Video\Command;

use Hn\Video\Resource\Processing\VideoThumbnailExtractor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Environment;

/**
 * Command to test video thumbnail extraction
 */
class VideoThumbnailTestCommand extends Command
{
    protected function configure(): void
    {
        $this->setDescription('Test video thumbnail extraction from MP4 files')
            ->addArgument(
                'video-file',
                InputArgument::REQUIRED,
                'Path to the video file (absolute path or relative to public/)'
            )
            ->addArgument(
                'output-file',
                InputArgument::OPTIONAL,
                'Output path for the extracted thumbnail (defaults to thumbnail.jpg in var/)',
                null
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $videoFile = $input->getArgument('video-file');
        $outputFile = $input->getArgument('output-file');
        
        // Resolve absolute path for video file
        if (!str_starts_with($videoFile, '/')) {
            $videoFile = Environment::getPublicPath() . '/' . ltrim($videoFile, '/');
        }
        
        // Default output path
        if ($outputFile === null) {
            $outputFile = Environment::getVarPath() . '/thumbnail.jpg';
        } elseif (!str_starts_with($outputFile, '/')) {
            $outputFile = Environment::getVarPath() . '/' . ltrim($outputFile, '/');
        }
        
        $io->title('Video Thumbnail Extraction Test');
        $io->section('Configuration');
        $io->definitionList(
            ['Video file' => $videoFile],
            ['Output file' => $outputFile]
        );
        
        // Check if video file exists
        if (!file_exists($videoFile)) {
            $io->error("Video file does not exist: $videoFile");
            return Command::FAILURE;
        }
        
        // First, analyze the video file using PHP MP4 parser
        $io->section('Video Analysis');
        $analysis = VideoThumbnailExtractor::analyzeVideoFile($videoFile);
        
        if (isset($analysis['error'])) {
            $io->error("Failed to analyze video: " . $analysis['error']);
            return Command::FAILURE;
        }
        
        $io->definitionList(
            ['Has embedded poster' => $analysis['has_attached_picture'] ? 'Yes ✅' : 'No ❌'],
            ['Detection method' => $analysis['method'] ?? 'unknown']
        );
        
        // Extract thumbnail
        $io->section('Thumbnail Extraction');
        $io->text('Attempting to extract thumbnail...');
        
        $success = VideoThumbnailExtractor::testExtractThumbnail($videoFile, $outputFile);
        
        if ($success) {
            $io->success("Thumbnail extracted successfully to: $outputFile");
            
            // Show file size
            $fileSize = filesize($outputFile);
            $io->text("Thumbnail file size: " . $this->formatBytes($fileSize));
            
            return Command::SUCCESS;
        } else {
            if ($analysis['has_attached_picture']) {
                $io->error('Failed to extract thumbnail (MP4 parsing error)');
            } else {
                $io->error('No embedded poster found. This extension only extracts embedded poster images from MP4 files.');
            }
            return Command::FAILURE;
        }
    }

    protected function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' bytes';
        }
    }
}