<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Command\CheckAssetCompletenessCommand;
use App\Command\GenerateMasterFromBriefCommand;
use App\Command\QaCorrectMasterCommand;
use App\Command\ValidateBookBriefCommand;
use App\Message\PipelineStepMessage;
use App\Service\BookCreation\BookCreationStateManager;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsMessageHandler]
final class PipelineStepHandler
{
    public function __construct(
        private readonly ValidateBookBriefCommand $validateBookBriefCommand,
        private readonly GenerateMasterFromBriefCommand $generateMasterFromBriefCommand,
        private readonly QaCorrectMasterCommand $qaCorrectMasterCommand,
        private readonly CheckAssetCompletenessCommand $checkAssetCompletenessCommand,
        private readonly BookCreationStateManager $stateManager,
        private readonly MessageBusInterface $bus,
        private readonly KernelInterface $kernel,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function __invoke(PipelineStepMessage $message): void
    {
        $project = $this->stateManager->findProject($message->getProjectId());
        if (null === $project) {
            return;
        }

        $briefDir = sprintf('%s/resources/book-briefs', $this->projectDir);
        $blueprintDir = sprintf('%s/resources/book-blueprints/%s', $this->projectDir, $project->getSlug());
        $briefPath = sprintf('%s/%s.yaml', $briefDir, $project->getSlug());
        $masterPath = sprintf('%s/master.json', $blueprintDir);
        $runtimeDir = sprintf('%s/generated', $blueprintDir);

        $project->setCurrentStep(sprintf('step_%02d_%s', $message->getStep(), $message->getStepName()));
        $project->setProgressPct($message->getProgressPct());
        $project->setStatus('running');
        $this->stateManager->flush();

        $output = new BufferedOutput();
        $app = new \Symfony\Bundle\FrameworkBundle\Console\Application($this->kernel);
        $app->setAutoExit(false);

        try {
            match ($message->getStep()) {
                1 => $this->stepValidateBrief($briefPath, $output),
                2 => $this->stepGenerateMaster($briefPath, $blueprintDir, $output),
                3, 4 => $this->stepQaCorrect($briefPath, $masterPath, $output),
                5 => $this->stepQaGate($blueprintDir, $output),
                6 => $this->stepValidateBlueprint($masterPath, $app, $output),
                7 => $this->stepGenerateRuntimes($masterPath, $runtimeDir, $app, $output),
                8 => $this->stepValidateRuntimes($runtimeDir, $app, $output),
                9 => $this->stepGenerateImages($project->getSlug(), $blueprintDir, $app, $output),
                10 => $this->stepCheckAssets($blueprintDir, $output),
                11 => $this->stepSyncCatalog($app, $output),
                12 => $this->stepVerifyCatalog($project->getSlug(), $app, $output),
            };

            // Update QA scores from report if available
            $qaReportPath = $blueprintDir.'/claude-qa-report.json';
            if (is_file($qaReportPath)) {
                $report = json_decode((string) file_get_contents($qaReportPath), true);
                if (is_array($report) && isset($report['scores'])) {
                    $project->setQaScores($report['scores']);
                }
            }

            $project->addLog('info', sprintf('[Step %d/%d] %s — OK', $message->getStep(), PipelineStepMessage::TOTAL_STEPS, $message->getStepLabel()), $message->getStepName());

            if ($message->isFinal()) {
                $project->setStatus('validation');
                $project->setProgressPct(100);
                $project->setCurrentStep('completed');
                $project->addLog('success', 'Pipeline terminé — livre prêt pour validation finale', 'completed');
            } else {
                $next = $message->getNextStep();
                if (null !== $next) {
                    $this->bus->dispatch($next);
                }
            }

            $this->stateManager->flush();
        } catch (\Throwable $e) {
            $project->setStatus('failed');
            $project->setError(sprintf('[Step %d] %s: %s', $message->getStep(), $message->getStepLabel(), $e->getMessage()));
            $project->addLog('error', sprintf('ÉCHEC — %s', $e->getMessage()), $message->getStepName());
            $this->stateManager->flush();
        }
    }

    private function stepValidateBrief(string $briefPath, BufferedOutput $output): void
    {
        $input = new ArrayInput(['brief' => $briefPath]);
        $input->setInteractive(false);
        $exitCode = $this->validateBookBriefCommand->run($input, $output);
        if (0 !== $exitCode) {
            throw new \RuntimeException(sprintf('Validation du brief échouée: %s', trim($output->fetch())));
        }
    }

    private function stepGenerateMaster(string $briefPath, string $blueprintDir, BufferedOutput $output): void
    {
        $input = new ArrayInput(['--brief' => $briefPath, '--output-dir' => $blueprintDir]);
        $input->setInteractive(false);
        $exitCode = $this->generateMasterFromBriefCommand->run($input, $output);
        if (0 !== $exitCode) {
            throw new \RuntimeException(sprintf('Génération master échouée: %s', trim($output->fetch())));
        }
    }

    private function stepQaCorrect(string $briefPath, string $masterPath, BufferedOutput $output): void
    {
        $input = new ArrayInput(['--brief' => $briefPath, '--source' => $masterPath]);
        $input->setInteractive(false);
        $exitCode = $this->qaCorrectMasterCommand->run($input, $output);
        if (0 !== $exitCode) {
            throw new \RuntimeException(sprintf('QA corrective échouée: %s', trim($output->fetch())));
        }
    }

    private function stepQaGate(string $blueprintDir, BufferedOutput $output): void
    {
        $app = new \Symfony\Bundle\FrameworkBundle\Console\Application($this->kernel);
        $app->setAutoExit(false);
        $app->run(new ArrayInput(['command' => 'app:book:qa-gate', 'blueprint-dir' => $blueprintDir]), $output);
    }

    private function stepValidateBlueprint(string $masterPath, \Symfony\Bundle\FrameworkBundle\Console\Application $app, BufferedOutput $output): void
    {
        $input = new ArrayInput(['command' => 'app:book:validate-blueprint', '--file' => $masterPath]);
        $input->setInteractive(false);
        $exitCode = $app->run($input, $output);
        if (0 !== $exitCode) {
            throw new \RuntimeException('Validation blueprint échouée');
        }
    }

    private function stepGenerateRuntimes(string $masterPath, string $runtimeDir, \Symfony\Bundle\FrameworkBundle\Console\Application $app, BufferedOutput $output): void
    {
        if (!is_dir($runtimeDir)) {
            mkdir($runtimeDir, 0775, true);
        }
        $input = new ArrayInput(['command' => 'app:book:generate-blueprint', '--source' => $masterPath, '--output-dir' => $runtimeDir]);
        $input->setInteractive(false);
        $exitCode = $app->run($input, $output);
        if (0 !== $exitCode) {
            throw new \RuntimeException('Génération runtimes échouée');
        }
    }

    private function stepValidateRuntimes(string $runtimeDir, \Symfony\Bundle\FrameworkBundle\Console\Application $app, BufferedOutput $output): void
    {
        foreach (['fr', 'en', 'nl'] as $locale) {
            $runtimeFile = sprintf('%s/runtime.%s.json', $runtimeDir, $locale);
            $input = new ArrayInput(['command' => 'app:book:validate-blueprint', '--file' => $runtimeFile, '--runtime' => true]);
            $input->setInteractive(false);
            $app->run($input, $output);
        }
    }

    private function stepGenerateImages(string $slug, string $blueprintDir, \Symfony\Bundle\FrameworkBundle\Console\Application $app, BufferedOutput $output): void
    {
        $input = new ArrayInput(['command' => 'app:book:create-from-blueprint', 'slug' => $slug, '--base-url' => 'http://nginx', '--generate-images' => true]);
        $input->setInteractive(false);
        $exitCode = $app->run($input, $output);
        if (0 !== $exitCode) {
            throw new \RuntimeException('Génération images échouée');
        }
    }

    private function stepCheckAssets(string $blueprintDir, BufferedOutput $output): void
    {
        $input = new ArrayInput(['blueprint-dir' => $blueprintDir]);
        $input->setInteractive(false);
        $this->checkAssetCompletenessCommand->run($input, $output);
    }

    private function stepSyncCatalog(\Symfony\Bundle\FrameworkBundle\Console\Application $app, BufferedOutput $output): void
    {
        $input = new ArrayInput(['command' => 'app:sync-book-blueprints']);
        $input->setInteractive(false);
        $exitCode = $app->run($input, $output);
        if (0 !== $exitCode) {
            throw new \RuntimeException('Sync catalogue échoué');
        }
    }

    private function stepVerifyCatalog(string $slug, \Symfony\Bundle\FrameworkBundle\Console\Application $app, BufferedOutput $output): void
    {
        $input = new ArrayInput(['command' => 'app:book:verify-catalog', 'slug' => $slug, '--base-url' => 'http://nginx']);
        $input->setInteractive(false);
        $exitCode = $app->run($input, $output);
        if (0 !== $exitCode) {
            throw new \RuntimeException('Vérification catalogue échouée');
        }
    }
}
