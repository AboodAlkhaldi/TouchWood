<?php

declare(strict_types=1);

namespace Modules\Feedback\Presentation\Storefront;

use DateTimeInterface;
use Modules\Catalog\Public\Contracts\ShopProductSection;
use Modules\Catalog\Public\Dto\ShopProductVisitDto;
use Modules\Feedback\Application\Query\AuthorName;
use Modules\Feedback\Application\Query\VariantValueRow;
use Modules\Feedback\Application\Shop\ShopFeedback;
use Modules\Feedback\Presentation\Http\Resource\ShopAuthorData;
use Modules\Feedback\Presentation\Http\Resource\ShopReviewData;
use Modules\Feedback\Presentation\Http\Resource\ShopReviewsData;
use Modules\Feedback\Presentation\Http\Resource\ShopStarData;
use Modules\Feedback\Presentation\Http\Resource\ShopVariantValueData;

/** The product's rating and approved reviews (feedback.md §1.1, §1.2), `feedback.reviews`. */
final readonly class ReviewsSection implements ShopProductSection
{
    public function __construct(
        private ShopFeedback $feedback,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function read(ShopProductVisitDto $visit): array
    {
        $read = $this->feedback->of($visit);

        return (new ShopReviewsData(
            $read->average,
            $read->count,
            array_map(static fn (array $row): ShopReviewData => new ShopReviewData(
                $row['review']->id,
                self::author($row['author']),
                $row['review']->rating,
                $row['review']->body,
                $row['review']->locale,
                $row['review']->mark->value,
                array_map(static fn (VariantValueRow $value): ShopVariantValueData => new ShopVariantValueData($value->attribute, $value->value), $row['variant']),
                $row['review']->writtenAt->format(DateTimeInterface::ATOM),
            ), $read->reviews),
            $read->nextReview,
            $read->signedIn && ! $read->reviewed,
            array_map(static fn (int $star, int $count): ShopStarData => new ShopStarData($star, $count), array_keys($read->stars), $read->stars),
            $read->star,
            $read->signedIn && $read->reviewed,
        ))->toArray();
    }

    public static function author(AuthorName $author): ShopAuthorData
    {
        return new ShopAuthorData($author->firstName, $author->lastInitial, $author->deleted);
    }
}
