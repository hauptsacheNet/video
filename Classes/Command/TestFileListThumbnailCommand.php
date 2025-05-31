<?php

declare(strict_types=1);

namespace Hn\Video\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Command to test file list thumbnail generation
 */
class TestFileListThumbnailCommand extends Command
{
    protected function configure(): void
    {
        $this->setDescription('Test thumbnail generation like the file list module does')
            ->addArgument(
                'video-file',
                InputArgument::REQUIRED,
                'Path to the video file (relative to fileadmin/)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $videoFile = $input->getArgument('video-file');
        
        $io->title('File List Thumbnail Test');
        $io->section('Configuration');
        $io->definitionList(['Video file' => $videoFile]);
        
        try {
            // Get the file object like file list does
            $resourceFactory = GeneralUtility::makeInstance(ResourceFactory::class);
            $file = $resourceFactory->retrieveFileOrFolderObject($videoFile);
            
            if (!$file || !$file instanceof \TYPO3\CMS\Core\Resource\File) {
                $io->error("File not found or not a File object: $videoFile");
                return Command::FAILURE;
            }
            
            $io->section('File Information');
            $io->definitionList(
                ['Identifier' => $file->getIdentifier()],
                ['MIME Type' => $file->getMimeType()],
                ['Size' => $this->formatBytes($file->getSize())],
                ['Is Image' => $file->isImage() ? 'Yes' : 'No'],
                ['Is Media File' => $file->isMediaFile() ? 'Yes' : 'No']
            );
            
            // Test different thumbnail configurations like file list uses
            $configurations = [
                'Small thumbnail (32x32)' => [
                    'width' => '32c',
                    'height' => '32c',
                ],
                'Medium thumbnail (64x64)' => [
                    'width' => '64c',
                    'height' => '64c',
                ],
                'Large preview (150x150)' => [
                    'width' => 150,
                    'height' => 150,
                ],
            ];
            
            $io->section('Thumbnail Generation Tests');
            
            foreach ($configurations as $name => $config) {
                $io->text("Testing: $name");
                
                try {
                    // Generate thumbnail like file list does
                    $processedFile = $file->process(ProcessedFile::CONTEXT_IMAGECROPSCALEMASK, $config);
                    
                    if ($processedFile->isProcessed()) {
                        $io->success("  ✅ Generated: " . $processedFile->getPublicUrl());
                        $io->text("     Size: " . $this->formatBytes($processedFile->getSize()));
                        $io->text("     Dimensions: " . $processedFile->getProperty('width') . 'x' . $processedFile->getProperty('height'));
                    } else {
                        $io->warning("  ⚠️  Not processed - using original");
                        $io->text("     Original URL: " . $processedFile->getPublicUrl());
                    }
                } catch (\Exception $e) {
                    $io->error("  ❌ Failed: " . $e->getMessage());
                    $io->text("     " . $e->getFile() . ':' . $e->getLine());
                }
                
                $io->text('');
            }
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $io->error("Test failed: " . $e->getMessage());
            $io->text("Stack trace:");
            $io->text($e->getTraceAsString());
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