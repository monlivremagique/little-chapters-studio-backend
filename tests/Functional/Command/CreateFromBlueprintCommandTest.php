<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Command\CreateFromBlueprintCommand;
use App\Command\SyncBookBlueprintsCommand;
use App\BookBlueprint\BlueprintProjector;
use App\BookBlueprint\BlueprintValidator;
use App\BookBlueprint\CoverPromptBuilder;
use App\BookBlueprint\CoverGenerationService;
use App\BookBlueprint\PagePromptBuilder;
use App\BookBlueprint\PageGenerationService;
use App\Command\GenerateBlueprintCommand;
use App\Command\GenerateCoverCommand;
use App\Command\GeneratePagesCommand;
use App\Command\ValidateBlueprintCommand;
use App\Tests\Double\Replicate\FakeReplicatePredictionClient;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class CreateFromBlueprintCommandTest extends WebTestCase
{
    public function testFailsFastWhenMasterBlueprintIsInvalid(): void
    {
        self::bootKernel();
        $command = $this->createCommand();
        $commandTester = new CommandTester($command);

        $statusCode = $commandTester->execute([
            '--source' => dirname(__DIR__, 2).'/Fixtures/book-blueprints/master-invalid-missing-nl.json',
            '--base-url' => 'http://nginx',
        ]);

        self::assertSame(1, $statusCode);
        self::assertStringContainsString('Validate master blueprint failed.', $commandTester->getDisplay());
    }

    public function testDoesNotCallReplicateWithoutGenerateImagesFlag(): void
    {
        self::bootKernel();
        /** @var FakeReplicatePredictionClient $fakeReplicate */
        $fakeReplicate = static::getContainer()->get(FakeReplicatePredictionClient::class);
        $fakeReplicate->reset();
        $command = $this->createCommand();
        $commandTester = new CommandTester($command);

        $statusCode = $commandTester->execute([
            '--source' => dirname(__DIR__, 3).'/resources/book-blueprints/forest-of-lost-stars/master.json',
            '--base-url' => 'http://nginx',
        ]);

        // forest-of-lost-stars has metadata.status=draft → after sync the Sylius product is
        // disabled → verifyLocalBookState() catalog check fails (book not in GET /api/books).
        // The important invariant: Replicate is NEVER called regardless of early failure.
        self::assertSame(1, $statusCode);
        self::assertSame([], $fakeReplicate->getCreateInputs(), 'Replicate must never be called without --generate-images flag.');
        self::assertStringContainsString('does not appear in GET /api/books', $commandTester->getDisplay());
    }

    private function createCommand(): CreateFromBlueprintCommand
    {
        $container = static::getContainer();
        $blueprintValidator = new BlueprintValidator();

        return new CreateFromBlueprintCommand(
            new ValidateBlueprintCommand($blueprintValidator),
            new GenerateBlueprintCommand(
                $blueprintValidator,
                new BlueprintProjector(),
            ),
            new GenerateCoverCommand(
                new CoverGenerationService($blueprintValidator, new CoverPromptBuilder()),
                $container->get(FakeReplicatePredictionClient::class),
            ),
            new GeneratePagesCommand(
                new PageGenerationService($blueprintValidator, new PagePromptBuilder()),
                $container->get(FakeReplicatePredictionClient::class),
            ),
            $container->get(SyncBookBlueprintsCommand::class),
            $container->get(HttpClientInterface::class),
            (string) $container->getParameter('kernel.project_dir'),
        );
    }
}
