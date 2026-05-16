<?php

declare(strict_types=1);

namespace App\Command;

use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:book:generate-print-ready-pdf',
    description: 'Assembles generated page images into a print-ready 21×21 cm PDF.',
)]
final class GeneratePrintReadyPdfCommand extends Command
{
    /** 21 cm expressed in PDF points (1 pt = 25.4 mm / 72) */
    private const PAGE_PT = 595.28;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('slug', InputArgument::OPTIONAL, 'Blueprint slug, e.g. forest-of-lost-stars.')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Path to master.json (overrides slug).')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Output PDF path (default: {blueprintDir}/print-ready.pdf).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $slug = trim((string) $input->getArgument('slug'));
        $sourcePath = trim((string) $input->getOption('source'));

        if ('' === $sourcePath) {
            if ('' === $slug) {
                $io->error('Provide either the <slug> argument or the --source option.');

                return Command::FAILURE;
            }

            $sourcePath = sprintf('%s/resources/book-blueprints/%s/master.json', $this->projectDir, $slug);
        }

        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            $io->error(sprintf('master.json not found or not readable: %s', $sourcePath));

            return Command::FAILURE;
        }

        $blueprintDir = dirname($sourcePath);
        if ('' === $slug) {
            $slug = basename($blueprintDir);
        }

        try {
            /** @var array<string, mixed> $master */
            $master = json_decode((string) file_get_contents($sourcePath), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $io->error(sprintf('Invalid JSON in master.json: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        $outputPath = trim((string) $input->getOption('output'));
        if ('' === $outputPath) {
            $outputPath = $blueprintDir.'/print-ready.pdf';
        }

        // Resolve ordered printable scenes from sceneDefinitions
        $sceneDefinitions = is_array($master['sceneDefinitions'] ?? null) ? $master['sceneDefinitions'] : [];
        $printable = [];
        foreach ($sceneDefinitions as $scene) {
            if (!is_array($scene)) {
                continue;
            }

            $type = (string) ($scene['type'] ?? '');
            if (!in_array($type, ['cover', 'story', 'backCover'], true)) {
                continue;
            }

            $printable[] = $scene;
        }

        usort($printable, static fn (array $a, array $b): int =>
            ((int) ($a['pageNumber'] ?? 0)) <=> ((int) ($b['pageNumber'] ?? 0))
        );

        if ([] === $printable) {
            $io->error('No printable scenes (cover, story, backCover) found in master.json.');

            return Command::FAILURE;
        }

        $io->title(sprintf('Print-ready PDF — %s', $slug));
        $io->writeln(sprintf('Scenes to assemble: %d', count($printable)));

        // Resolve PNG paths and fail fast on any missing image
        /** @var list<array{id:string,type:string,path:string}> $pages */
        $pages = [];
        foreach ($printable as $scene) {
            $sceneId = (string) ($scene['id'] ?? '');
            $type = (string) ($scene['type'] ?? '');

            $pngPath = 'cover' === $type
                ? sprintf('%s/generated-cover/%s-generated.png', $blueprintDir, $sceneId)
                : sprintf('%s/generated-pages/%s-generated.png', $blueprintDir, $sceneId);

            if (!is_file($pngPath) || !is_readable($pngPath)) {
                $io->error(sprintf(
                    'Missing PNG for "%s" (type: %s) at "%s". Run the full pipeline with --generate-images first.',
                    $sceneId,
                    $type,
                    $pngPath,
                ));

                return Command::FAILURE;
            }

            $io->writeln(sprintf('  %-12s %s', "[$type]", $pngPath));
            $pages[] = ['id' => $sceneId, 'type' => $type, 'path' => $pngPath];
        }

        // Build HTML with one div per page, images embedded as base64 data URIs
        $lastIndex = count($pages) - 1;
        $pageHtml = '';
        foreach ($pages as $i => $page) {
            $data = base64_encode((string) file_get_contents($page['path']));
            $dataUri = 'data:image/png;base64,'.$data;
            $break = $i < $lastIndex ? 'page-break-after:always;' : '';
            $pageHtml .= sprintf(
                '<div style="width:%.2fpt;height:%.2fpt;margin:0;padding:0;overflow:hidden;%s">'.
                '<img src="%s" style="width:%.2fpt;height:%.2fpt;display:block;" /></div>'."\n",
                self::PAGE_PT, self::PAGE_PT, $break,
                $dataUri,
                self::PAGE_PT, self::PAGE_PT,
            );
        }

        $pt = self::PAGE_PT;
        $html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8">
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { margin:0; padding:0; }
@page { size:{$pt}pt {$pt}pt; margin:0; }
</style>
</head>
<body>{$pageHtml}</body>
</html>
HTML;

        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setDefaultFont('DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->setPaper([0, 0, self::PAGE_PT, self::PAGE_PT]);
        $dompdf->loadHtml($html);

        $io->writeln('Rendering PDF…');
        $dompdf->render();

        $pdfContent = $dompdf->output();
        if ('' === $pdfContent || false === file_put_contents($outputPath, $pdfContent)) {
            $io->error(sprintf('Cannot write PDF to "%s".', $outputPath));

            return Command::FAILURE;
        }

        $sizeMb = round((int) filesize($outputPath) / 1024 / 1024, 2);
        $io->success(sprintf(
            'Print-ready PDF: %s (%s MB, %d pages)',
            $outputPath,
            $sizeMb,
            count($pages),
        ));

        return Command::SUCCESS;
    }
}
