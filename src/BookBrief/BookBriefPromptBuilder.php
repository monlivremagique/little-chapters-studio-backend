<?php

declare(strict_types=1);

namespace App\BookBrief;

final class BookBriefPromptBuilder
{
    public function build(array $brief): array
    {
        $slug = $this->requiredString($brief, 'slug');
        $title = $this->requiredString($brief, 'title');
        $storySubject = $this->requiredString($brief, 'story_subject');
        $mainEmotion = $this->requiredString($brief, 'main_emotion');
        $learningMessage = $this->requiredString($brief, 'learning_message');
        $age = $this->requiredString($brief, 'age');
        $visualStyle = $this->requiredString($brief, 'visual_style');
        $languages = $this->requiredStringList($brief, 'languages');
        $themes = $this->requiredStringList($brief, 'theme');
        $constraints = $this->optionalStringList($brief, 'constraints');
        $storyPageCount = max(1, (int) ($brief['story_page_count'] ?? 6));
        $arcType = $this->optionalString($brief, 'arc_type');
        $climaxPage = $this->optionalString($brief, 'climax_page');
        $culturalContext = $this->optionalString($brief, 'cultural_context');
        $setting = $this->optionalString($brief, 'setting');
        $parentEmotionGoal = $this->optionalString($brief, 'parent_emotion_goal');
        $secondaryCharacters = $this->optionalStringList($brief, 'secondary_characters');
        $scenes = $this->extractScenes($brief, $storyPageCount);
        $pageCount = $storyPageCount + 4;
        $assetKeyMap = [
            'cover' => 'cover-default',
            'dedication' => 'dedication-default',
            'hero_reference' => 'hero-reference-default',
            'summary' => 'summary-default',
            'backCover' => 'back-cover-default',
        ];

        $sceneIds = ['hero_reference', 'cover', 'dedication'];
        foreach (range(1, $storyPageCount) as $pageNumber) {
            $pageId = sprintf('page_%d', $pageNumber);
            $sceneIds[] = $pageId;
            $assetKeyMap[$pageId] = sprintf('page-%d-default', $pageNumber);
        }
        $sceneIds[] = 'summary';
        $sceneIds[] = 'backCover';
        $assetDefaults = [];
        foreach ($assetKeyMap as $pageId => $assetKey) {
            $assetDefaults[$assetKey] = sprintf('/uploads/books/%s/%s.svg', $slug, $assetKey);
        }

        $prompt = implode("\n", array_filter([
            'You are an award-winning Belgian children\'s book author and illustrator. Your stories are treasured family heirlooms, passed down through generations.',
            '',
            '=== WHAT MAKES A PREMIUM BOOK ===',
            '- Thick matte paper, 21x21cm square format, cloth-bound spine',
            '- Hand-painted watercolor illustrations, never digital-looking',
            '- A story that makes parents emotional when reading aloud',
            '- A magical "wow" moment on the climax page — a reveal that makes the child gasp',
            '- Belgian cultural authenticity — this is NOT a generic translated story',
            '- Each page could be framed as wall art',
            '',
            '=== OUTPUT FORMAT ===',
            'Return ONLY valid JSON. No markdown fences, no commentary, no explanation.',
            'Must parse directly with json_decode().',
            'Root must be a Book Blueprint V2 master object with the structure below.',
            '',
            '=== JSON STRUCTURE ===',
            '- schema: "book_blueprint_v2"',
            '- schemaVersion: 2',
            '- metadata: { bookId, slug, productCode, version: 2, status: "draft", sourceLocale: "fr", supportedLocales: ["fr","en","nl"], pageCount, generationPageCount, ageRange, theme: [...], promise, editorialPositioning }',
            '- locales: { fr: { book: { title_template }, pages: { <page_id>: { title_template, text_template } } }, en: { ... }, nl: { ... } }',
            '- visualBible: { style_rules: [...], palette: "...", lighting: "...", compositionRules: [...] }',
            '- heroBible: { identityRules: [...], characterDesign: "...", forbiddenDrift: [...] }',
            '- sceneDefinitions: [ { id, type, pageNumber, personalizable, assetKey, camera, composition, foreground, midground, background, lighting, emotion, must_show: [...], must_not_show: [...], promptTemplate, negativePrompt }, ... ]',
            '- assets: { basePublicPath, defaults: { <assetKey>: "<path>" } }',
            '- imageGeneration: { provider: "replicate", modelStrategy: { model: "black-forest-labs/flux-2-pro" }, negativePromptDefault: "...", resolution: "1 MP", outputFormat: "png", inputImages: { pageReference: true, childPhoto: true } }',
            '- qa: { requiredPageTypes: [...], requiredLocales: [...], placeholderPolicy: { allowed: ["{child_name}", "{child_pronoun_subject}", "{child_possessive_det}"], forbidden: [] }, rules: [...], scorecard: { editorialScore: { value: 0-100, rationale: "..." }, imageabilityScore: { ... }, heroConsistencyScore: { ... }, localeCompletenessScore: { ... } } }',
            '',
            '=== FIELD RULES ===',
            '- Use title_template and text_template (NOT title/text) in locale pages.',
            '- scene IDs order: '.implode(', ', $sceneIds),
            '- scene types: cover, dedication, story, summary, backCover, reference',
            '- Dedication + summary promptTemplate = thematic background WITHOUT hero (text overlay).',
            '- asset keys: '.implode(', ', array_keys($assetDefaults)),
            '- provider=replicate, model=black-forest-labs/flux-2-pro, format=png, resolution="1 MP"',
            '- visualBible.style_rules, heroBible.identityRules (not styleRules/identity_rules)',
            '- qa.scorecard: editorialScore, imageabilityScore, heroConsistencyScore, localeCompletenessScore',
            '- Allowed placeholders: {child_name}, {child_pronoun_subject}, {child_possessive_det}',
            '- Use {child_name} as primary reference. Use pronoun placeholders max 1 each per page.',
            '- No text inside generated images — text is external.',
            '- FR/NL/EN must share identical visual structure and scene order.',
            '- Page count: '.$pageCount.'. ProductCode: BOOK_'.strtoupper($slug),
            '',
            '=== BOOK BRIEF ===',
            sprintf('- slug: %s', $slug),
            sprintf('- title: %s', $title),
            sprintf('- age: %s', $age),
            sprintf('- theme: %s', implode(', ', $themes)),
            sprintf('- story_subject: %s', $storySubject),
            sprintf('- main_emotion: %s', $mainEmotion),
            sprintf('- learning_message: %s', $learningMessage),
            sprintf('- languages: %s', implode(', ', $languages)),
            sprintf('- visual_style: %s', $visualStyle),
            sprintf('- constraints: %s', [] !== $constraints ? implode(', ', $constraints) : 'none'),
            '' !== $arcType ? sprintf('- arc_type: %s', $arcType) : null,
            '' !== $climaxPage ? sprintf('- climax_page: %s (this page must deliver the emotional peak)', $climaxPage) : null,
            '',
            '=== HERO REFERENCE PORTRAIT ===',
            '- sceneDefinition "hero_reference": portrait, NOT a story scene.',
            '- Type: "reference", pageNumber: 1, personalizable: true, assetKey: "hero-reference-default".',
            '- Camera: "front-facing portrait, bust or medium shot, hero looking warmly at viewer"',
            '- Composition: "centered character portrait, warm neutral background"',
            '- Foreground/midground/background: hero ONLY — describe appearance, never setting.',
            '- PromptTemplate: describe hero appearance only (hair, face, clothing, expression).',
            '- Lighting: "soft, warm, even studio portrait lighting"',
            '- Emotion: "warm, approachable, gentle"',
            '- No background decoration, no story elements, no text.',
            '- This portrait locks hero consistency across ALL story pages.',
            '',
            '' !== $setting ? sprintf('- setting: %s', $setting) : null,
            '' !== $culturalContext ? sprintf('- cultural_context: %s', $culturalContext) : null,
            '' !== $parentEmotionGoal ? sprintf('- parent_emotion_goal: %s', $parentEmotionGoal) : null,
            [] !== $secondaryCharacters ? sprintf('- secondary_characters: %s', implode(', ', $secondaryCharacters)) : null,
            [] !== $scenes ? $this->buildScenesBlock($scenes) : null,
            '',
            '=== STORYTELLING REQUIREMENTS ===',
            '- Follow the arc type: each page advances one clear emotional beat.',
            '- The climax page MUST deliver a true "wow" moment: a magical reveal, an emotional peak, a visual transformation that makes the reader gasp. This is the page that sells the book.',
            '- Write for reading aloud: rhythmic, short sentences, natural pauses, comforting cadence for bedtime.',
            '- Every page must contain a visible action AND a clear emotion — the illustration prompt must capture both.',
            '- No generic AI children-book phrasing. Every sentence must feel handcrafted.',
            '- The story must feel deeply personal: the child\'s actions matter, their choices drive the plot.',
            '- Cultural context must be visible in the setting, the characters, and the emotional framing.',
            '- Secondary characters appear in at least one sceneDefinition.',
            '- heroBible.identityRules: concrete physical descriptions (NOT "generic" or "to be personalized").',
            '- heroBible.characterDesign: precise appearance string.',
            '- heroBible.forbiddenDrift: anti-drift rules preventing hero changes.',
            '- Image prompts: premium artistic vocabulary (watercolor wash, golden hour, cinematic composition).',
            '- No external file or URL references.',
            '',
            '=== GENDER PLACEHOLDERS ===',
            '- Allowed: {child_name}, {child_pronoun_subject}, {child_possessive_det}',
            '- Use {child_name} as primary reference. Pronoun placeholders max 1 per page.',
            '- {child_pronoun_subject} = il/elle (FR), he/she (EN), hij/zij (NL)',
            '- {child_possessive_det} = son/sa/ses (FR), his/her (EN), zijn/haar (NL)',
            '- Never stack multiple pronoun placeholders in one sentence.',
            '',
            '=== GENDER NEUTRALITY (HARD) ===',
            '- NL: NEVER hij/hem/zijn for child hero. Use {child_name}, {child_pronoun_subject}, {child_possessive_det}.',
            '- EN: NEVER he/she/him/her. Use {child_name}, {child_pronoun_subject}, {child_possessive_det}.',
            '- FR: NEVER il/elle. Use {child_name}, {child_pronoun_subject}, or "l\'enfant".',
            '- Violation of these rules = REJECTED at QA gate.',
            '',
            '=== EN LANGUAGE ===',
            '- Write EN as a native English-speaking children\'s author, not a translator.',
            '- Use natural English sentence structures, not French skeletons:',
            '  ✗ "It is a beautiful day" → ✓ "What a beautiful morning!"',
            '  ✗ "There is a dragon who cries" → ✓ "A dragon, crying all alone"',
            '  ✗ "For the courage" → ✓ "A little bravery goes a long way"',
            '- Example of excellent EN: "Every Saturday morning, {child_name} dances across Ghent\'s cobblestones, hand in hand with Grandma Rose."',
            '- The EN text must feel like an original English story, not a translated French one.',
            '',
            '=== PERSONALIZATION ===',
            '- The child\'s name ({child_name}) is the heart of this story. Make it matter.',
            '- Weave the name naturally into the narrative: the sound of it, its meaning, its rhythm.',
            '- Example: if the name means "star", the story might have celestial imagery.',
            '- The name should feel SPECIAL — the story would not work the same way for any other name.',
            '- Never force this: a subtle nod to the name\'s meaning or sound is enough.',
            '',
            '=== NL LANGUAGE (CRITICAL) ===',
            '- Write NL as if you are a Flemish author from Ghent or Antwerp, not a translator.',
            '- CRITICAL DUTCH GRAMMAR RULES:',
            '  • SOV in subordinate clauses: "dat hij gaat" NOT "dat hij gaat" (wait, this IS correct — example: "Ik zie dat {child_name} een framboos plukt")',
            '  • Separable verbs: "opendoen" → "doet open" in main clause, "opendoet" in subordinate',
            '  • Inversion after fronting: "Dan loopt {child_name} naar..." NOT "Dan {child_name} loopt naar..."',
            '  • Possessives: "{child_possessive_det} lievelingsplekje" NOT "de lievelingsplekje van {child_name}"',
            '- Use Flemish vocabulary: "patat" NOT "friet", "schoonmoeder" NOT "schoonma", "seffens" for "straks", "gelijkvloers" for "begane grond"',
            '- Avoid French sentence skeletons:',
            '  ✗ "C\'est un beau jour" → ✓ "Het is een mooie dag"',
            '  ✗ "Il y a un dragon" → ✓ "Er is een draak" / "Er ligt een draak"',
            '  ✗ "Pour le courage" → ✓ "Voor de moed"',
            '- Example of excellent NL for a children\'s book:',
            '  "Elke zaterdagochtend danst {child_name} over de keien van Gent. De lucht ruikt naar warm brood en kaneel. Timur, de rosse kater, rekt zich uit in een zonneplek."',
            '- This must sound like a native Flemish speaker wrote it. If it reads like a translation from French, it fails.',
            '',
            '=== NL vs FEW-SHOT ===',
            'Good NL: "Meneer Claudio komt terug, moe maar met een warme glimlach. {child_name} biedt het gouden gebakje aan. De bakker bijt, sluit de ogen... en zijn gezicht straalt als glas-in-lood."',
            'Bad NL: "Monsieur Claudio revient, fatigué mais avec un sourire chaleureux. {child_name} offre le petit gâteau doré." (This is FRENCH written with Dutch words — REJECTED.)',
        ], static fn (mixed $v): bool => null !== $v && '' !== $v));

        return [
            'slug' => $slug,
            'prompt' => $prompt,
            'input' => [
                'prompt' => $prompt,
                'max_tokens' => 24000,
                'temperature' => 0.7,
            ],
            'debug' => [
                'slug' => $slug,
                'title' => $title,
                'storyPageCount' => $storyPageCount,
                'pageCount' => $pageCount,
                'languages' => $languages,
                'theme' => $themes,
                'constraints' => $constraints,
                'sceneIds' => $sceneIds,
                'arcType' => $arcType ?: null,
                'climaxPage' => $climaxPage ?: null,
                'scenesCount' => count($scenes),
            ],
        ];
    }

    /** @param array<string, mixed> $brief */
    private function requiredString(array $brief, string $key): string
    {
        $value = trim((string) ($brief[$key] ?? ''));
        if ('' === $value) {
            throw new \RuntimeException(sprintf('Brief field "%s" is required.', $key));
        }
        return $value;
    }

    /** @param array<string, mixed> $brief @return list<string> */
    private function requiredStringList(array $brief, string $key): array
    {
        $values = $this->optionalStringList($brief, $key);
        if ([] === $values) {
            throw new \RuntimeException(sprintf('Brief field "%s" must contain at least one string.', $key));
        }
        return $values;
    }

    /** @param array<string, mixed> $brief @return list<string> */
    private function optionalStringList(array $brief, string $key): array
    {
        $values = $brief[$key] ?? [];
        if (!is_array($values)) {
            return [];
        }
        return array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $values,
        )));
    }

    /** @param array<string, mixed> $brief */
    private function optionalString(array $brief, string $key): string
    {
        return trim((string) ($brief[$key] ?? ''));
    }

    /**
     * @param array<string, mixed> $brief
     * @return list<array{id:string,moment:string}>
     */
    private function extractScenes(array $brief, int $storyPageCount): array
    {
        $scenes = [];
        if (isset($brief['scenes']) && is_array($brief['scenes'])) {
            foreach ($brief['scenes'] as $scene) {
                if (!is_array($scene)) continue;
                $id = trim((string) ($scene['id'] ?? ''));
                $moment = trim((string) ($scene['moment'] ?? ''));
                if ('' !== $id && '' !== $moment) $scenes[] = ['id' => $id, 'moment' => $moment];
            }
        }
        if ([] === $scenes) {
            for ($i = 1; $i <= $storyPageCount; ++$i) {
                $moment = trim((string) ($brief[sprintf('scene_%d_moment', $i)] ?? ''));
                if ('' !== $moment) $scenes[] = ['id' => sprintf('page_%d', $i), 'moment' => $moment];
            }
        }
        return $scenes;
    }

    /** @param list<array{id:string,moment:string}> $scenes */
    private function buildScenesBlock(array $scenes): string
    {
        $lines = ['- scene_scripts:'];
        foreach ($scenes as $scene) {
            $lines[] = sprintf('    %s: %s', $scene['id'], $scene['moment']);
        }
        return implode("\n", $lines);
    }
}
