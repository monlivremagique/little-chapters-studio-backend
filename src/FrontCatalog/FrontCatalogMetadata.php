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
            'forest-of-lost-stars' => [
                'id' => 'b6',
                'rating' => 0,
                'reviewCount' => 0,
                'occasion' => ['cadeau', 'noel'],
                'isBestseller' => false,
                'isNew' => true,
                'description' => 'La Foret des Etoiles Perdues raconte une quete douce ou un enfant aide les etoiles tombees a retrouver leur ciel.',
                'longDescription' => 'Une aventure bedtime-safe dans une foret enchantee au clair de lune. Avec un renard curieux et une chouette sage comme guides, l\'enfant decouvre que meme les plus petits gestes peuvent illuminer le monde.',
                'emotionalPromise' => 'Une histoire qui endort le coeur plein d\'emerveillement.',
                'features' => [
                    '10 pages illustrees',
                    'Couverture rigide premium',
                    'Style watercolor premium',
                    'Hero-reference cross-pages',
                    'Localisation FR / EN / NL',
                ],
                'relatedBooks' => ['b7'],
                'reviews' => [],
            ],
            'ville-ecole' => [
                'id' => 'b7',
                'rating' => 0,
                'reviewCount' => 0,
                'occasion' => ['cadeau', 'quotidien'],
                'isBestseller' => false,
                'isNew' => true,
                'description' => 'Mon Grand Jour en Ville accompagne le tout-petit dans sa premiere aventure urbaine, pas a pas vers le courage.',
                'longDescription' => 'Un matin ensoleillee en ville belge : trams, marche, boulangerie et parc. A chaque etape, l\'enfant decouvre qu\'il est plus courageux qu\'il ne le pensait. Pour les 3-5 ans qui font leurs premiers grands pas.',
                'emotionalPromise' => 'Le livre qui transforme chaque sortie en victoire personnelle.',
                'features' => [
                    '10 pages illustrees',
                    'Couverture rigide premium',
                    'Style gouache urban premium',
                    'Age 3-5 ans',
                    'Localisation FR / EN / NL',
                ],
                'relatedBooks' => ['b6'],
                'reviews' => [],
            ],
            // espace-robot excluded: status=draft, manual-craft QA, NL gender violation in cover title.
            // Re-add when the book has been regenerated through the full AI pipeline and set status=published.
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
        return [];
    }

}
