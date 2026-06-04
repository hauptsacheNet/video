<?php

declare(strict_types=1);

namespace Hn\Video\Command;

use Hn\Video\Resource\Rendering\VideoTagRendererWithPoster;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AsCommand(
    name: 'video:test-renderer',
    description: 'Test video tag generation with poster images'
)]
class TestVideoRendererCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument(
            'video_path',
            InputArgument::REQUIRED,
            'Path to the video file (relative to fileadmin or absolute)'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $videoPath = $input->getArgument('video_path');

        $io->title('Video Tag Renderer Test');

        try {
            // Get the file object
            $resourceFactory = GeneralUtility::makeInstance(ResourceFactory::class);
            $file = $resourceFactory->retrieveFileOrFolderObject($videoPath);
            
            if (!$file || !$file->exists()) {
                $io->error("Video file not found: $videoPath");
                return Command::FAILURE;
            }

            $io->section('File Information');
            $io->definitionList(
                ['Identifier' => $file->getIdentifier()],
                ['MIME Type' => $file->getMimeType()],
                ['Size' => $this->formatBytes($file->getSize())],
                ['Is Video' => $file->getType() === 4 ? 'Yes' : 'No']
            );

            // Test our enhanced video renderer
            $renderer = GeneralUtility::makeInstance(VideoTagRendererWithPoster::class);
            
            if (!$renderer->canRender($file)) {
                $io->error('File cannot be rendered by our video renderer');
                return Command::FAILURE;
            }

            $io->section('Video Tag Generation');
            
            // Generate video tag with different sizes
            $sizes = [
                'Small (320x240)' => [320, 240],
                'Medium (640x360)' => [640, 360],
                'Large (1280x720)' => [1280, 720],
            ];

            foreach ($sizes as $sizeName => [$width, $height]) {
                $io->writeln("<info>Testing: $sizeName</info>");
                
                $videoTag = $renderer->render($file, $width, $height);
                
                // Check if poster attribute is present
                $hasPoster = strpos($videoTag, 'poster=') !== false;
                $status = $hasPoster ? '✅' : '❌';
                
                $io->writeln("$status Has poster attribute: " . ($hasPoster ? 'Yes' : 'No'));
                
                if ($hasPoster) {
                    // Extract poster URL
                    preg_match('/poster="([^"]+)"/', $videoTag, $matches);
                    $posterUrl = $matches[1] ?? 'Not found';
                    $io->writeln("   Poster URL: $posterUrl");
                }
                
                $io->writeln("   HTML: " . htmlspecialchars($videoTag));
                $io->newLine();
            }

            $io->success('Video renderer test completed successfully');
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Test failed: ' . $e->getMessage());
            $io->writeln('Stack trace:');
            $io->writeln($e->getTraceAsString());
            return Command::FAILURE;
        }
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        
        for ($i = 0; $bytes >= 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }
}