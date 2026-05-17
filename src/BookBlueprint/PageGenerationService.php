<?php

declare(strict_types=1);

namespace App\BookBlueprint;

final class PageGenerationService
{
    public function __construct(
        private readonly BlueprintValidator $blueprintValidator,
        private readonly PagePromptBuilder $pagePromptBuilder,
    ) {
    }

    /**
     * @param array<string, mixed> $masterBlueprint
     * @return list<array<string, mixed>>
     */
    public function validateAndExtractGeneratableScenes(array $masterBlueprint, ?string $pageId = null): array
    {
        $validation = $this->blueprintValidator->validateMasterBlueprint($masterBlueprint);

        if (!$validation->isValid()) {
            throw new \RuntimeException(implode("\n", $validation->errors));
        }

        $sceneDefinitions = is_array($masterBlueprint['sceneDefinitions'] ?? null) ? $masterBlueprint['sceneDefinitions'] : [];
        $scenes = [];

        foreach ($sceneDefinitions as $scene) {
            if (!is_array($scene)) {
                continue;
            }

            $type = (string) ($scene['type'] ?? '');
            $id = (string) ($scene['id'] ?? '');

            // Exclude reference (hero portrait generated separately by generate-hero-reference)
            // and cover (generated separately by generate-cover)
            if (!in_array($type, ['story', 'summary', 'backCover', 'dedication'], true)) {
                continue;
            }

            if (null !== $pageId && $id !== $pageId) {
                continue;
            }

            $scenes[] = $scene;
        }

        usort($scenes, static function (array $left, array $right): int {
            return ((int) ($left['pageNumber'] ?? 0)) <=> ((int) ($right['pageNumber'] ?? 0));
        });

        if ([] === $scenes) {
            throw new \RuntimeException(null !== $pageId
                ? sprintf('The master blueprint does not contain a generatable scene with id "%s".', $pageId)
                : 'The master blueprint does not contain any generatable interior pages.');
        }

        return $scenes;
    }

    /**
     * @param array<string, mixed> $masterBlueprint
     * @param array<string, mixed> $scene
     * @return array{prompt:string,negativePrompt:string,input:array<string,mixed>,debug:array<string,mixed>}
     */
    public function buildPageGenerationPayload(array $masterBlueprint, array $scene, ?string $coverPath = null, ?string $photoPath = null, ?string $heroReferencePath = null): array
    {
        if (null !== $coverPath && (!is_file($coverPath) || !is_readable($coverPath))) {
            throw new \RuntimeException(sprintf('The approved cover reference "%s" is not readable.', $coverPath));
        }

        if (null !== $heroReferencePath && (!is_file($heroReferencePath) || !is_readable($heroReferencePath))) {
            // File doesn't exist yet (dry-run or first generation). Silently skip — the file will
            // be generated before the page generation call uses it at runtime.
            $heroReferencePath = null;
        }

        $builtPrompt = $this->pagePromptBuilder->build($masterBlueprint, $scene, null !== $photoPath, null !== $heroReferencePath);
        $imageGeneration = is_array($masterBlueprint['imageGeneration'] ?? null) ? $masterBlueprint['imageGeneration'] : [];
        $inputImages = null !== $coverPath ? [$this->toDataUri($coverPath)] : [];

        if (null !== $heroReferencePath) {
            $inputImages[] = $this->toDataUri($heroReferencePath);
        }

        $inputImages[] = $this->toDataUriFromBinary('image/png', $this->renderPageReferencePng($scene));

        if (null !== $photoPath) {
            $inputImages[] = $this->toDataUri($photoPath);
        }

        $input = [
            'prompt' => $builtPrompt['prompt'],
            'input_images' => $inputImages,
            'aspect_ratio' => trim((string) ($scene['aspectRatio'] ?? '3:4')) ?: '3:4',
            'resolution' => trim((string) ($imageGeneration['resolution'] ?? '1 MP')) ?: '1 MP',
            'output_format' => trim((string) ($imageGeneration['outputFormat'] ?? 'png')) ?: 'png',
        ];

        if ('' !== $builtPrompt['negativePrompt']) {
            $input['negative_prompt'] = $builtPrompt['negativePrompt'];
        }

        return [
            'prompt' => $builtPrompt['prompt'],
            'negativePrompt' => $builtPrompt['negativePrompt'],
            'input' => $input,
            'debug' => [
                'sceneId' => (string) ($scene['id'] ?? ''),
                'sceneType' => (string) ($scene['type'] ?? ''),
                'coverPath' => $coverPath,
                'heroReferencePath' => $heroReferencePath,
                'photoProvided' => null !== $photoPath,
                'promptBreakdown' => $builtPrompt['debug'],
            ],
        ];
    }

    /** @param array<string, mixed> $scene */
    private function renderPageReferencePng(array $scene): string
    {
        $image = imagecreatetruecolor(768, 1024);

        if (false === $image) {
            throw new \RuntimeException('The page reference image could not be prepared.');
        }

        $background = imagecolorallocate($image, 250, 245, 239);
        $accent = imagecolorallocate($image, 122, 62, 43);
        $soft = imagecolorallocate($image, 238, 225, 211);
        $muted = imagecolorallocate($image, 86, 86, 86);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefilledrectangle($image, 0, 0, 768, 1024, $background);
        imagefilledrectangle($image, 56, 72, 712, 442, $white);
        imagefilledrectangle($image, 56, 490, 712, 952, $white);
        imagerectangle($image, 56, 72, 712, 442, $accent);
        imagerectangle($image, 56, 490, 712, 952, $accent);
        imagefilledrectangle($image, 56, 72, 712, 130, $soft);
        imagefilledrectangle($image, 56, 490, 712, 548, $soft);

        $title = strtoupper((string) ($scene['id'] ?? 'page'));
        $composition = trim((string) ($scene['composition'] ?? 'Page composition reference'));
        $emotion = trim((string) ($scene['emotion'] ?? ''));
        $mustShow = is_array($scene['must_show'] ?? null) ? implode(', ', array_values($scene['must_show'])) : '';

        imagestring($image, 5, 80, 92, 'Mon Livre Magique - Page guide', $accent);
        imagestring($image, 5, 80, 156, substr($title, 0, 54), $accent);
        imagestring($image, 3, 80, 230, substr($composition, 0, 92), $muted);
        imagestring($image, 4, 80, 512, 'Reference image for framing and scene rhythm', $accent);
        imagestring($image, 3, 80, 584, substr($emotion, 0, 92), $muted);
        imagestring($image, 2, 80, 658, substr($mustShow, 0, 104), $muted);

        ob_start();
        imagepng($image);
        $contents = (string) ob_get_clean();
        imagedestroy($image);

        if ('' === $contents) {
            throw new \RuntimeException('The page reference image could not be created.');
        }

        return $contents;
    }

    private function toDataUri(string $path): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException(sprintf('Image file "%s" is not readable.', $path));
        }

        $contents = (string) file_get_contents($path);
        $mimeType = mime_content_type($path) ?: 'application/octet-stream';

        return $this->toDataUriFromBinary($mimeType, $contents);
    }

    private function toDataUriFromBinary(string $mimeType, string $contents): string
    {
        return sprintf('data:%s;base64,%s', $mimeType, base64_encode($contents));
    }
}
