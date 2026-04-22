<?php

declare(strict_types=1);

namespace App\FrontCatalog;

final class FrontCatalogMetadata
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function books(): array
    {
        return [
            'aventure-enchantee' => [
                'id' => 'b1',
                'rating' => 4.9,
                'reviewCount' => 127,
                'occasion' => ['cadeau', 'anniversaire'],
                'isBestseller' => true,
                'isNew' => false,
                'description' => "L'Aventure Enchantee emmene votre enfant dans un monde magique ou il devient le heros d'une quete extraordinaire.",
                'longDescription' => "Plongez votre enfant dans une aventure epique personnalisee. Chaque page fait de lui le coeur du recit et transforme la lecture du soir en souvenir marquant.",
                'emotionalPromise' => "Un cadeau qui fait briller les yeux de l'enfant des la couverture.",
                'features' => [
                    '28 pages illustrees',
                    'Couverture rigide premium',
                    'Personnalisation du visage',
                    'Dedicace personnalisee',
                    'Impression haute qualite',
                ],
                'printQuality' => 'Impression offset premium',
                'relatedBooks' => ['b2', 'b4', 'b5'],
                'reviews' => ['r1', 'r4', 'r5'],
            ],
            'voyage-des-etoiles' => [
                'id' => 'b2',
                'rating' => 4.8,
                'reviewCount' => 89,
                'occasion' => ['cadeau', 'noel'],
                'isBestseller' => false,
                'isNew' => true,
                'description' => 'Le Voyage des Etoiles ouvre une odyssee douce et lumineuse pour les enfants qui aiment rever grand.',
                'longDescription' => "Ce livre personnalise entraine l'enfant dans une aventure spatiale rassurante, faite de decouvertes, d'etoiles et de courage tranquille.",
                'emotionalPromise' => "Une histoire qui donne envie d'imaginer l'impossible, soir apres soir.",
                'features' => [
                    '28 pages illustrees',
                    'Couverture rigide premium',
                    'Univers celeste poetique',
                    'Dedicace personnalisee',
                    'Fabrication soignee',
                ],
                'printQuality' => 'Impression offset premium',
                'relatedBooks' => ['b1', 'b3', 'b5'],
                'reviews' => ['r2', 'r5', 'r6'],
            ],
            'foret-des-merveilles' => [
                'id' => 'b3',
                'rating' => 4.7,
                'reviewCount' => 65,
                'occasion' => ['cadeau', 'quotidien'],
                'isBestseller' => false,
                'isNew' => false,
                'description' => 'La Foret des Merveilles propose une promenade tendre entre animaux attachants et magie douce.',
                'longDescription' => "Pense pour les plus jeunes, ce livre personnalise melange nature, tendresse et repetition rassurante dans un format ideal pour le rituel de lecture.",
                'emotionalPromise' => "Une bulle de douceur qui rassure et apaise a chaque lecture.",
                'features' => [
                    '24 pages illustrees',
                    'Couverture rigide premium',
                    'Animaux et nature',
                    'Personnalisation enfant',
                    'Impression haute qualite',
                ],
                'printQuality' => 'Impression offset premium',
                'relatedBooks' => ['b5', 'b1', 'b2'],
                'reviews' => ['r3', 'r6', 'r4'],
            ],
            'super-heros-du-quotidien' => [
                'id' => 'b4',
                'rating' => 4.9,
                'reviewCount' => 104,
                'occasion' => ['anniversaire', 'education'],
                'isBestseller' => false,
                'isNew' => false,
                'description' => "Super-Heros du Quotidien aide l'enfant a voir sa force, ses qualites et ses petits exploits du quotidien.",
                'longDescription' => "Le recit place l'enfant au centre d'une histoire de confiance en soi, de courage et d'autonomie, sans quitter un ton doux et bienveillant.",
                'emotionalPromise' => "Un livre qui nourrit la fierte, la confiance et l'envie d'oser.",
                'features' => [
                    '28 pages illustrees',
                    'Couverture rigide premium',
                    'Theme confiance en soi',
                    'Personnalisation enfant',
                    'Impression haute qualite',
                ],
                'printQuality' => 'Impression offset premium',
                'relatedBooks' => ['b1', 'b2', 'b3'],
                'reviews' => ['r4', 'r5', 'r1'],
            ],
            'douce-nuit-etoilee' => [
                'id' => 'b5',
                'rating' => 4.8,
                'reviewCount' => 78,
                'occasion' => ['naissance', 'quotidien'],
                'isBestseller' => false,
                'isNew' => false,
                'description' => "Douce Nuit Etoilee accompagne le coucher avec une histoire paisible ou l'enfant retrouve son propre univers.",
                'longDescription' => "Ce livre personnalise est concu pour les routines du soir: rythme calme, promesse affective forte et repères visuels simples pour apaiser avant le sommeil.",
                'emotionalPromise' => "Le livre du soir qui transforme le coucher en moment privilegie.",
                'features' => [
                    '20 pages illustrees',
                    'Couverture souple premium',
                    'Rituel du coucher',
                    'Personnalisation enfant',
                    'Impression haute qualite',
                ],
                'printQuality' => 'Impression offset premium',
                'relatedBooks' => ['b3', 'b1', 'b2'],
                'reviews' => ['r6', 'r3', 'r2'],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function collections(): array
    {
        return [
            'aventures-magiques' => [
                'id' => 'c1',
                'subtitle' => 'Des quetes epique pour petits explorateurs',
                'description' => 'Plongez dans des univers fantastiques ou votre enfant devient le heros de son aventure.',
                'theme' => 'aventure',
            ],
            'histoires-du-soir' => [
                'id' => 'c2',
                'subtitle' => "Des recits tendres pour s'endormir en douceur",
                'description' => 'Des histoires apaisantes parfaites pour le rituel du coucher.',
                'theme' => 'douceur',
            ],
            'amis-animaux' => [
                'id' => 'c3',
                'subtitle' => 'Des aventures avec les plus adorables compagnons',
                'description' => "Votre enfant part a la rencontre d'animaux merveilleux et rassurants.",
                'theme' => 'animaux',
            ],
            'fetes-et-celebrations' => [
                'id' => 'c4',
                'subtitle' => 'Les moments magiques meritent des histoires magiques',
                'description' => 'Une collection reservee aux moments a celebrer et aux cadeaux memorables.',
                'theme' => 'anniversaire',
            ],
            'heros-du-quotidien' => [
                'id' => 'c5',
                'subtitle' => 'Parce que chaque enfant est extraordinaire',
                'description' => 'Des histoires qui renforcent la confiance en soi et la fierte du quotidien.',
                'theme' => 'heros',
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function reviews(): array
    {
        return [
            'r1' => [
                'id' => 'r1',
                'author' => 'Marie L.',
                'rating' => 5,
                'date' => '2024-12-15',
                'text' => "Mon fils etait tres emu de se voir dans l'histoire. Le livre est magnifique.",
                'childAge' => 4,
                'bookTitle' => "L'Aventure Enchantee",
                'verified' => true,
            ],
            'r2' => [
                'id' => 'r2',
                'author' => 'Sophie D.',
                'rating' => 5,
                'date' => '2024-11-28',
                'text' => "Offert pour Noel, c'est le cadeau qui a eu le plus de succes.",
                'childAge' => 6,
                'bookTitle' => 'Le Voyage des Etoiles',
                'verified' => true,
            ],
            'r3' => [
                'id' => 'r3',
                'author' => 'Thomas R.',
                'rating' => 4,
                'date' => '2024-11-10',
                'text' => "Tres belle qualite d'impression. Ma fille veut le lire tous les soirs.",
                'childAge' => 3,
                'bookTitle' => 'La Foret des Merveilles',
                'verified' => true,
            ],
            'r4' => [
                'id' => 'r4',
                'author' => 'Camille B.',
                'rating' => 5,
                'date' => '2024-10-22',
                'text' => "Un cadeau unique et memorable. L'enfant se reconnait tout de suite.",
                'childAge' => 5,
                'verified' => true,
            ],
            'r5' => [
                'id' => 'r5',
                'author' => 'Nicolas M.',
                'rating' => 5,
                'date' => '2024-10-05',
                'text' => "La qualite premium est au rendez-vous et mon fils adore etre le heros.",
                'childAge' => 7,
                'verified' => true,
            ],
            'r6' => [
                'id' => 'r6',
                'author' => 'Julie P.',
                'rating' => 4,
                'date' => '2024-09-18',
                'text' => "Magnifique cadeau. Les couleurs sont douces et le rendu est tres beau.",
                'childAge' => 4,
                'verified' => true,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function faq(): array
    {
        return [
            [
                'question' => 'Quelle photo utiliser pour la personnalisation ?',
                'answer' => "Choisissez une photo nette, de face, bien eclairee. Le visage de l'enfant doit etre bien visible.",
            ],
            [
                'question' => 'Puis-je voir un apercu avant d acheter ?',
                'answer' => "Le contrat catalogue expose deja les informations produit. L'apercu personnalise sera traite dans une phase ulterieure.",
            ],
            [
                'question' => "Quelle est la qualite d'impression ?",
                'answer' => "Nos livres sont penses pour un rendu premium, avec une fabrication soignee et une impression haute qualite.",
            ],
            [
                'question' => 'Combien de temps pour recevoir le livre ?',
                'answer' => 'Le flux de production et de livraison sera branche dans une phase checkout ulterieure.',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function personalizationFields(): array
    {
        return [
            [
                'id' => 'childName',
                'label' => "Prenom de l'enfant",
                'type' => 'text',
                'placeholder' => 'Ex : Emma',
                'helperText' => "Le prenom apparaitra dans l'histoire",
                'required' => true,
                'maxLength' => 20,
            ],
            [
                'id' => 'photo',
                'label' => "Photo de l'enfant",
                'type' => 'photo',
                'helperText' => 'Photo de face, bien eclairee',
                'required' => true,
            ],
            [
                'id' => 'dedication',
                'label' => 'Dedicace personnalisee',
                'type' => 'textarea',
                'placeholder' => 'Pour toi, mon petit tresor...',
                'helperText' => 'Maximum 150 caracteres',
                'required' => false,
                'maxLength' => 150,
            ],
        ];
    }

    /**
     * @return list<array{id: string, pageNumber: int, label: string, isPersonalized: bool}>
     */
    public function previewTemplates(): array
    {
        return [
            ['id' => 'p1', 'pageNumber' => 1, 'label' => 'Couverture', 'isPersonalized' => false],
            ['id' => 'p2', 'pageNumber' => 2, 'label' => 'Page dedicace', 'isPersonalized' => true],
            ['id' => 'p3', 'pageNumber' => 3, 'label' => "L'aventure commence", 'isPersonalized' => true],
            ['id' => 'p4', 'pageNumber' => 4, 'label' => 'La scene principale', 'isPersonalized' => false],
            ['id' => 'p5', 'pageNumber' => 5, 'label' => 'Le heros decouvre', 'isPersonalized' => true],
            ['id' => 'p6', 'pageNumber' => 6, 'label' => 'Les compagnons', 'isPersonalized' => false],
            ['id' => 'p7', 'pageNumber' => 7, 'label' => 'Le grand defi', 'isPersonalized' => true],
            ['id' => 'p8', 'pageNumber' => 8, 'label' => 'La fin heureuse', 'isPersonalized' => false],
        ];
    }
}
