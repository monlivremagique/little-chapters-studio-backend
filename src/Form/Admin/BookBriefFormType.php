<?php

declare(strict_types=1);

namespace App\Form\Admin;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;

class BookBriefFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // Section 1: Identité
            ->add('slug', TextType::class, [
                'label' => 'Slug (identifiant unique)',
                'help' => 'Généré automatiquement depuis le titre. Ex: le-petit-ami-du-dragon',
                'attr' => ['placeholder' => 'mon-super-livre'],
            ])
            ->add('title', TextType::class, [
                'label' => 'Titre du livre (FR)',
                'help' => 'Le titre qui apparaîtra sur la couverture et dans le catalogue',
            ])
            ->add('age', ChoiceType::class, [
                'label' => 'Tranche d\'âge',
                'choices' => [
                    '3-5 ans (tout-petits)' => '3-5',
                    '4-7 ans (maternelle)' => '4-7',
                    '6-8 ans (primaire)' => '6-8',
                    '8-10 ans (primaire+)' => '8-10',
                ],
            ])
            ->add('theme', ChoiceType::class, [
                'label' => 'Thèmes',
                'choices' => [
                    'Amitié' => 'friendship',
                    'Aventure' => 'adventure',
                    'Courage' => 'courage',
                    'Découverte' => 'discovery',
                    'Émerveillement' => 'wonder',
                    'Famille' => 'family',
                    'Gentillesse' => 'kindness',
                    'Imagination' => 'imagination',
                    'Nature' => 'nature',
                    'Confiance' => 'confidence',
                ],
                'multiple' => true,
                'expanded' => true,
            ])
            ->add('languages', ChoiceType::class, [
                'label' => 'Langues',
                'choices' => ['Français' => 'fr', 'Néerlandais' => 'nl', 'Anglais' => 'en'],
                'multiple' => true,
                'expanded' => true,
                'data' => ['fr', 'en', 'nl'],
            ])

            // Section 2: Histoire
            ->add('story_subject', TextareaType::class, [
                'label' => 'Sujet de l\'histoire',
                'attr' => ['rows' => 4],
            ])
            ->add('main_emotion', TextType::class, [
                'label' => 'Émotion principale',
                'help' => 'Ex: "courage tendre", "émerveillement et amitié"',
            ])
            ->add('learning_message', TextareaType::class, [
                'label' => 'Message à retenir',
                'attr' => ['rows' => 3],
            ])
            ->add('arc_type', ChoiceType::class, [
                'label' => 'Type d\'arc narratif',
                'choices' => [
                    'Du courage à la confiance' => 'comfort-to-courage',
                    'De l\'ordinaire à l\'extraordinaire' => 'ordinary-to-extraordinary',
                    'Une quête révélatrice' => 'quest-with-revelation',
                    'De la perte à la guérison' => 'loss-and-healing',
                    'Une amitié forgée' => 'friendship-forged',
                ],
                'placeholder' => 'Choisissez un arc narratif',
            ])
            ->add('climax_page', ChoiceType::class, [
                'label' => 'Page du climax',
                'choices' => [
                    'Page 1' => 'page_1', 'Page 2' => 'page_2', 'Page 3' => 'page_3',
                    'Page 4' => 'page_4', 'Page 5' => 'page_5', 'Page 6' => 'page_6',
                    'Page 7' => 'page_7', 'Page 8' => 'page_8',
                ],
            ])
            ->add('story_page_count', ChoiceType::class, [
                'label' => 'Nombre de pages d\'histoire',
                'choices' => ['4 pages' => 4, '6 pages' => 6, '8 pages' => 8],
                'data' => 6,
            ])

            // Section 3: Univers visuel
            ->add('visual_style', TextareaType::class, [
                'label' => 'Style visuel',
                'attr' => ['rows' => 3],
            ])
            ->add('setting', TextareaType::class, [
                'label' => 'Cadre de l\'histoire',
                'attr' => ['rows' => 3],
                'required' => false,
            ])
            ->add('cultural_context', TextareaType::class, [
                'label' => 'Contexte culturel belge',
                'attr' => ['rows' => 3],
                'required' => false,
            ])

            // Section 4: Personnages
            ->add('parent_emotion_goal', TextareaType::class, [
                'label' => 'Objectif émotionnel pour le parent',
                'attr' => ['rows' => 3],
                'required' => false,
            ])
            ->add('secondary_characters', CollectionType::class, [
                'label' => 'Personnages secondaires',
                'entry_type' => TextType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'prototype' => true,
                'required' => false,
            ])
        ;

        // Section 5: Scènes dynamiques basées sur story_page_count
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $data = $event->getData();
            $form = $event->getForm();
            $pageCount = max(1, (int) ($data['story_page_count'] ?? 6));

            for ($i = 1; $i <= $pageCount; ++$i) {
                $form->add(sprintf('scene_%d_moment', $i), TextareaType::class, [
                    'label' => sprintf('Page %d — que se passe-t-il ?', $i),
                    'attr' => ['rows' => 3],
                    'required' => false,
                ]);
            }
        });

        // Aussi après soumission (pour gérer le changement de story_page_count)
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) {
            $data = $event->getData();
            $form = $event->getForm();
            $pageCount = max(1, (int) ($data['story_page_count'] ?? 6));

            for ($i = 1; $i <= $pageCount; ++$i) {
                if (!$form->has(sprintf('scene_%d_moment', $i))) {
                    $form->add(sprintf('scene_%d_moment', $i), TextareaType::class, [
                        'label' => sprintf('Page %d — que se passe-t-il ?', $i),
                        'attr' => ['rows' => 3],
                        'required' => false,
                    ]);
                }
            }
        });
    }
}
