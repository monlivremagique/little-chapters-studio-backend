<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Admin\BookCreationProject;
use App\Form\Admin\BookBriefFormType;
use App\Message\PipelineStepMessage;
use App\Service\BookCreation\BookBriefWriter;
use App\Service\BookCreation\BookCreationStateManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/book-creation')]
final class BookCreationController extends AbstractController
{
    public function __construct(
        private readonly BookCreationStateManager $stateManager,
        private readonly BookBriefWriter $briefWriter,
        private readonly MessageBusInterface $bus,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    #[Route('/', name: 'app_admin_book_creation_index')]
    public function index(): Response
    {
        $projects = $this->stateManager->findAll();

        return $this->render('Admin/BookCreation/index.html.twig', [
            'projects' => $projects,
        ]);
    }

    #[Route('/new', name: 'app_admin_book_creation_new')]
    public function new(Request $request): Response
    {
        $project = new BookCreationProject();
        $briefData = $this->getDefaultBrief();

        $form = $this->createForm(BookBriefFormType::class, $briefData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            $project->setSlug($data['slug']);
            $project->setTitle($data['title']);
            $project->setBrief($data);

            // Write YAML brief file
            $briefDir = $this->projectDir.'/resources/book-briefs';
            if (!is_dir($briefDir)) {
                mkdir($briefDir, 0775, true);
            }
            $briefPath = sprintf('%s/%s.yaml', $briefDir, $data['slug']);
            $this->briefWriter->write($briefPath, $data);
            $project->setBlueprintPath(sprintf('resources/book-blueprints/%s', $data['slug']));

            $this->stateManager->save($project);

            // Dispatch step 1
            $this->bus->dispatch(new PipelineStepMessage($project->getId(), 1));

            $this->addFlash('success', sprintf('Livre "%s" créé. Le pipeline de génération est lancé.', $data['title']));

            return $this->redirectToRoute('app_admin_book_creation_show', ['id' => $project->getId()]);
        }

        return $this->render('Admin/BookCreation/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}', name: 'app_admin_book_creation_show')]
    public function show(int $id): Response
    {
        $project = $this->stateManager->findProject($id);
        if (null === $project) {
            throw $this->createNotFoundException('Projet introuvable');
        }

        return $this->render('Admin/BookCreation/show.html.twig', [
            'project' => $project,
        ]);
    }

    #[Route('/{id}/json', name: 'app_admin_book_creation_status')]
    public function status(int $id): JsonResponse
    {
        $project = $this->stateManager->findProject($id);
        if (null === $project) {
            return $this->json(['error' => 'Project not found'], 404);
        }

        $coverUrl = null;
        $bp = $project->getBlueprintPath();
        if (null !== $bp) {
            $coverPath = sprintf('%s/../public/uploads/books/%s/cover-generated.png', $this->projectDir, $project->getSlug());
            if (is_file($coverPath)) {
                $coverUrl = sprintf('/uploads/books/%s/cover-generated.png', $project->getSlug());
            }
        }

        return $this->json([
            'id' => $project->getId(),
            'slug' => $project->getSlug(),
            'status' => $project->getStatus(),
            'currentStep' => $project->getCurrentStep(),
            'progressPct' => $project->getProgressPct(),
            'qaScores' => $project->getQaScores(),
            'logs' => $project->getLogs(),
            'error' => $project->getError(),
            'coverUrl' => $coverUrl,
        ]);
    }

    #[Route('/{id}/validation', name: 'app_admin_book_creation_validation')]
    public function validation(int $id): Response
    {
        $project = $this->stateManager->findProject($id);
        if (null === $project) {
            throw $this->createNotFoundException('Projet introuvable');
        }

        $slug = $project->getSlug();
        $blueprintDir = $this->projectDir.'/resources/book-blueprints/'.$slug;
        $uploadDir = $this->projectDir.'/public/uploads/books/'.$slug;

        // Load master.json for content preview
        $master = null;
        $masterPath = $blueprintDir.'/master.json';
        if (is_file($masterPath)) {
            $master = json_decode((string) file_get_contents($masterPath), true);
        }

        // List generated assets
        $assets = [];
        if (is_dir($uploadDir)) {
            foreach (glob($uploadDir.'/*.png') as $file) {
                $assets[] = '/uploads/books/'.$slug.'/'.basename($file);
            }
        }

        return $this->render('Admin/BookCreation/validation.html.twig', [
            'project' => $project,
            'master' => $master,
            'assets' => $assets,
            'slug' => $slug,
        ]);
    }

    #[Route('/{id}/publish', name: 'app_admin_book_creation_publish', methods: ['POST'])]
    public function publish(int $id): Response
    {
        $project = $this->stateManager->findProject($id);
        if (null === $project) {
            throw $this->createNotFoundException('Projet introuvable');
        }

        $project->setStatus('published');
        $project->setCompletedAt(new \DateTimeImmutable());
        $project->addLog('success', 'Livre publié dans le catalogue', 'published');
        $this->stateManager->flush();

        $this->addFlash('success', 'Livre publié avec succès !');

        return $this->redirectToRoute('app_admin_book_creation_index');
    }

    #[Route('/{id}/reject', name: 'app_admin_book_creation_reject', methods: ['POST'])]
    public function reject(int $id, Request $request): Response
    {
        $project = $this->stateManager->findProject($id);
        if (null === $project) {
            throw $this->createNotFoundException('Projet introuvable');
        }

        $reason = $request->request->get('reason', 'Rejeté par l\'administrateur');
        $project->setStatus('failed');
        $project->setError($reason);
        $project->addLog('warning', sprintf('Rejeté: %s', $reason), 'rejected');
        $this->stateManager->flush();

        $this->addFlash('warning', 'Livre rejeté.');

        return $this->redirectToRoute('app_admin_book_creation_index');
    }

    #[Route('/{id}/regenerate', name: 'app_admin_book_creation_regenerate', methods: ['POST'])]
    public function regenerate(int $id): Response
    {
        $project = $this->stateManager->findProject($id);
        if (null === $project) {
            throw $this->createNotFoundException('Projet introuvable');
        }

        $project->setStatus('draft');
        $project->setCurrentStep(null);
        $project->setProgressPct(0);
        $project->setError(null);
        $project->addLog('info', 'Régénération demandée — redémarrage du pipeline', 'restart');
        $this->stateManager->flush();

        $this->bus->dispatch(new PipelineStepMessage($project->getId(), 1));

        return $this->redirectToRoute('app_admin_book_creation_show', ['id' => $project->getId()]);
    }

    private function getDefaultBrief(): array
    {
        return [
            'slug' => '',
            'title' => '',
            'age' => '4-7',
            'theme' => ['courage', 'friendship'],
            'languages' => ['fr', 'en', 'nl'],
            'story_subject' => '',
            'main_emotion' => '',
            'learning_message' => '',
            'arc_type' => '',
            'climax_page' => 'page_5',
            'story_page_count' => 6,
            'visual_style' => '',
            'setting' => '',
            'cultural_context' => '',
            'parent_emotion_goal' => '',
            'secondary_characters' => [],
            'constraints' => [
                'bedtime safe',
                'coherent hero design across all pages',
                'no text inside generated images',
                'Belgian premium quality',
            ],
        ];
    }
}
