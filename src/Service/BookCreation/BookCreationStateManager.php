<?php

declare(strict_types=1);

namespace App\Service\BookCreation;

use App\Entity\Admin\BookCreationProject;
use Doctrine\ORM\EntityManagerInterface;

final class BookCreationStateManager
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return BookCreationProject[] */
    public function findAll(): array
    {
        return $this->em->getRepository(BookCreationProject::class)->findBy([], ['createdAt' => 'DESC']);
    }

    public function findProject(int $id): ?BookCreationProject
    {
        return $this->em->getRepository(BookCreationProject::class)->find($id);
    }

    public function save(BookCreationProject $project): void
    {
        $this->em->persist($project);
        $this->em->flush();
    }

    public function flush(): void
    {
        $this->em->flush();
    }

    public function delete(BookCreationProject $project): void
    {
        $this->em->remove($project);
        $this->em->flush();
    }
}
