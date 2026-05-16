UPDATE sylius_taxon_translation
SET description = CASE translatable_id
    WHEN (SELECT id FROM sylius_taxon WHERE code = 'AVENTURES_MAGIQUES') THEN 'Livres personnalises d''aventure et de quetes douces pour enfants de 3 a 8 ans.'
    WHEN (SELECT id FROM sylius_taxon WHERE code = 'HISTOIRES_DU_SOIR') THEN 'Livres personnalises de coucher et de rituel du soir, axes sur le calme et l''imaginaire.'
    WHEN (SELECT id FROM sylius_taxon WHERE code = 'AMIS_ANIMAUX') THEN 'Histoires personnalisees avec animaux attachants, nature et exploration tendre.'
    WHEN (SELECT id FROM sylius_taxon WHERE code = 'FETES_CELEBRATIONS') THEN 'Livres personnalises pour anniversaires, naissances et moments a celebrer.'
    WHEN (SELECT id FROM sylius_taxon WHERE code = 'HEROS_DU_QUOTIDIEN') THEN 'Histoires personnalisees qui valorisent la confiance, l''autonomie et les petits exploits du quotidien.'
    ELSE description
END
WHERE translatable_id IN (
    SELECT id
    FROM sylius_taxon
    WHERE code IN (
        'AVENTURES_MAGIQUES',
        'HISTOIRES_DU_SOIR',
        'AMIS_ANIMAUX',
        'FETES_CELEBRATIONS',
        'HEROS_DU_QUOTIDIEN'
    )
);

UPDATE sylius_product_variant_translation
SET name = 'Edition standard'
WHERE translatable_id IN (
    SELECT id
    FROM sylius_product_variant
    WHERE code IN (
        'BOOK_AVENTURE_ENCHANTEE-variant-0',
        'BOOK_VOYAGE_DES_ETOILES-variant-0',
        'BOOK_FORET_DES_MERVEILLES-variant-0',
        'BOOK_SUPER_HEROS_DU_QUOTIDIEN-variant-0',
        'BOOK_DOUCE_NUIT_ETOILEE-variant-0'
    )
);

UPDATE sylius_channel_pricing cp
SET price = CASE p.code
    WHEN 'BOOK_AVENTURE_ENCHANTEE' THEN 4990
    WHEN 'BOOK_VOYAGE_DES_ETOILES' THEN 4590
    WHEN 'BOOK_FORET_DES_MERVEILLES' THEN 4290
    WHEN 'BOOK_SUPER_HEROS_DU_QUOTIDIEN' THEN 4390
    WHEN 'BOOK_DOUCE_NUIT_ETOILEE' THEN 3990
    ELSE cp.price
END,
original_price = CASE p.code
    WHEN 'BOOK_AVENTURE_ENCHANTEE' THEN 5490
    WHEN 'BOOK_VOYAGE_DES_ETOILES' THEN 4990
    WHEN 'BOOK_FORET_DES_MERVEILLES' THEN 4590
    WHEN 'BOOK_SUPER_HEROS_DU_QUOTIDIEN' THEN 4690
    WHEN 'BOOK_DOUCE_NUIT_ETOILEE' THEN 4290
    ELSE cp.original_price
END
FROM sylius_product_variant v
JOIN sylius_product p ON p.id = v.product_id
WHERE cp.product_variant_id = v.id
  AND cp.channel_code = 'LITTLE_CHAPTERS_BE_FR'
  AND p.code IN (
      'BOOK_AVENTURE_ENCHANTEE',
      'BOOK_VOYAGE_DES_ETOILES',
      'BOOK_FORET_DES_MERVEILLES',
      'BOOK_SUPER_HEROS_DU_QUOTIDIEN',
      'BOOK_DOUCE_NUIT_ETOILEE'
  );
