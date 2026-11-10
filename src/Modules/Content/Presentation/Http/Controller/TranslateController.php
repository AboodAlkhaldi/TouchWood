<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Content\Application\Command\TranslateText\TranslateText;
use Modules\Content\Application\Command\TranslateText\TranslateTextHandler;
use Modules\Content\Domain\Exception\InvalidContentInput;

/**
 * **Translate**, as the editors call it (content.md §1.3): `POST /admin/content/translate` with the kind of
 * item, its store and each box's Arabic, answered with each box's English and how many of its blocks lost
 * bold or links - `{"texts": [...], "lostMarks": [...]}`. Nothing is saved. A refusal is the problem
 * document every JSON call gets (ProblemDetails), in the reason's words.
 *
 * The handler checks the kind's permission in the store; this checks nothing (handoff §19), and reads the
 * request only as typed.
 */
final readonly class TranslateController
{
    /** Boxes one press may hold: Translate All on the longest form. The build's pick, far above any form. */
    public const int MAX_BOXES = 50;

    public function __invoke(Request $request, TranslateTextHandler $handler): JsonResponse
    {
        $kind = $request->input('kind');
        $store = $request->input('store');

        $translated = $handler->handle(new TranslateText(
            is_string($kind) ? $kind : '',
            is_string($store) && $store !== '' ? $store : null,
            self::boxes($request->input('boxes')),
        ));

        return new JsonResponse(['texts' => $translated->texts, 'lostMarks' => $translated->lostMarks]);
    }

    /**
     * @return list<array{text: string, rich: bool}>
     *
     * @throws InvalidContentInput
     */
    private static function boxes(mixed $boxes): array
    {
        if (! is_array($boxes) || ! array_is_list($boxes) || count($boxes) > self::MAX_BOXES) {
            throw new InvalidContentInput('boxes', InvalidContentInput::FORMAT);
        }

        return array_map(static function (mixed $box): array {
            // `rich` as JSON sends it, or as a form does ("1", "0", "true", "false"); missing, a plain box.
            $rich = is_array($box) ? filter_var($box['rich'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;

            if (! is_array($box) || ! is_string($box['text'] ?? null) || $rich === null) {
                throw new InvalidContentInput('boxes', InvalidContentInput::FORMAT);
            }

            return ['text' => $box['text'], 'rich' => $rich];
        }, $boxes);
    }
}
