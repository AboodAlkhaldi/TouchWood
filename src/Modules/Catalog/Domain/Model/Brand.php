<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Model;

use Modules\Catalog\Domain\Exception\DefaultBrandRequired;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\ValueObject\ListPosition;
use Modules\Catalog\Domain\ValueObject\LocalizedName;
use Modules\Catalog\Domain\ValueObject\Slugs;
use Modules\Catalog\Domain\ValueObject\StructuredText;
use Modules\Catalog\Public\Enums\AgencyType;

/**
 * A brand (handoff §9.4, catalog.md §1.6): global, one row per brand — never a table per brand.
 * Every product carries exactly one.
 *
 * - **Exactly one brand is the default**, pre-selected on the product form; making another the
 *   default un-marks this one (the repository does both under the brands' lock). A mark on the row,
 *   never a brand's name in code (handoff §2 rule 2).
 * - **The default cannot be deactivated** — another is made the default first (§9.3 #12) — nor
 *   deleted (the handler asks).
 * - A description is optional, but **in both languages or in neither**: a page half in one language
 *   is worse than none (handoff §5.2).
 * - The origin country is optional (amendment 1(j)): when given, two capital letters.
 */
final class Brand
{
    public const int NAME_MAX = 100;

    public const int DESCRIPTION_MAX = 5000;

    private ChangeLog $changes;

    private function __construct(
        private readonly string $id,
        private LocalizedName $name,
        private Slugs $slugs,
        private ?StructuredText $descriptionAr,
        private ?StructuredText $descriptionEn,
        private ?string $logoMediaId,
        private ?string $originCountry,
        private AgencyType $agencyType,
        private bool $isDefault,
        private bool $showInDefaultListings,
        private int $position,
        private bool $isActive,
    ) {
        $this->changes = new ChangeLog;
    }

    /**
     * @throws InvalidCatalogAttribute
     */
    public static function add(
        string $id,
        LocalizedName $name,
        Slugs $slugs,
        ?StructuredText $descriptionAr,
        ?StructuredText $descriptionEn,
        ?string $logoMediaId,
        ?string $originCountry,
        AgencyType $agencyType,
        bool $showInDefaultListings,
        int $position,
    ): self {
        self::checkDescription($descriptionAr, $descriptionEn);

        return new self($id, $name, $slugs, $descriptionAr, $descriptionEn, $logoMediaId, self::country($originCountry), $agencyType, false, $showInDefaultListings, ListPosition::check($position), true);
    }

    public static function reconstitute(
        string $id,
        LocalizedName $name,
        Slugs $slugs,
        ?StructuredText $descriptionAr,
        ?StructuredText $descriptionEn,
        ?string $logoMediaId,
        ?string $originCountry,
        AgencyType $agencyType,
        bool $isDefault,
        bool $showInDefaultListings,
        int $position,
        bool $isActive,
    ): self {
        return new self($id, $name, $slugs, $descriptionAr, $descriptionEn, $logoMediaId, $originCountry, $agencyType, $isDefault, $showInDefaultListings, $position, $isActive);
    }

    /**
     * Everything staff edit in one form; a field that did not change records nothing.
     *
     * @throws InvalidCatalogAttribute
     */
    public function edit(
        LocalizedName $name,
        Slugs $slugs,
        ?StructuredText $descriptionAr,
        ?StructuredText $descriptionEn,
        ?string $logoMediaId,
        ?string $originCountry,
        AgencyType $agencyType,
        bool $showInDefaultListings,
        int $position,
    ): void {
        self::checkDescription($descriptionAr, $descriptionEn);
        $before = $this->snapshot();

        $this->name = $name;
        $this->slugs = $slugs;
        $this->descriptionAr = $descriptionAr;
        $this->descriptionEn = $descriptionEn;
        $this->logoMediaId = $logoMediaId;
        $this->originCountry = self::country($originCountry);
        $this->agencyType = $agencyType;
        $this->showInDefaultListings = $showInDefaultListings;
        $this->position = ListPosition::check($position);

        $this->recordAgainst($before);
    }

    public function makeDefault(): void
    {
        $this->changes->record('is_default', $this->isDefault, true);
        $this->isDefault = true;
    }

    /**
     * Called on the old default when another brand becomes the default.
     */
    public function unmarkDefault(): void
    {
        $this->changes->record('is_default', $this->isDefault, false);
        $this->isDefault = false;
    }

    /**
     * @throws DefaultBrandRequired
     */
    public function deactivate(): void
    {
        if ($this->isDefault) {
            throw new DefaultBrandRequired;
        }

        $this->changes->record('is_active', $this->isActive, false);
        $this->isActive = false;
    }

    public function activate(): void
    {
        $this->changes->record('is_active', $this->isActive, true);
        $this->isActive = true;
    }

    /**
     * The media library deleted the logo (Platform's "detach", handoff §5.5).
     */
    public function dropLogo(): void
    {
        $this->changes->record('logo_media_id', $this->logoMediaId, null);
        $this->logoMediaId = null;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): LocalizedName
    {
        return $this->name;
    }

    public function slugs(): Slugs
    {
        return $this->slugs;
    }

    public function descriptionAr(): ?StructuredText
    {
        return $this->descriptionAr;
    }

    public function descriptionEn(): ?StructuredText
    {
        return $this->descriptionEn;
    }

    public function logoMediaId(): ?string
    {
        return $this->logoMediaId;
    }

    public function originCountry(): ?string
    {
        return $this->originCountry;
    }

    public function agencyType(): AgencyType
    {
        return $this->agencyType;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function showInDefaultListings(): bool
    {
        return $this->showInDefaultListings;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    /**
     * Its columns' values now, for the audit log and for telling what changed — the descriptions as
     * their exact structure, so making one word bold is a change too.
     *
     * @return array<string, string|int|bool|null>
     */
    public function snapshot(): array
    {
        return [
            'name_ar' => $this->name->ar,
            'name_en' => $this->name->en,
            'slug_ar' => $this->slugs->ar->value,
            'slug_en' => $this->slugs->en->value,
            'description_ar' => $this->descriptionAr?->toJson(),
            'description_en' => $this->descriptionEn?->toJson(),
            'logo_media_id' => $this->logoMediaId,
            'origin_country' => $this->originCountry,
            'agency_type' => $this->agencyType->value,
            'is_default' => $this->isDefault,
            'show_in_default_listings' => $this->showInDefaultListings,
            'position' => $this->position,
            'is_active' => $this->isActive,
        ];
    }

    /**
     * @return array<string, string|int|bool|null> column => its value before, since this was read
     */
    public function pullChanges(): array
    {
        return $this->changes->pull();
    }

    /**
     * @param  array<string, string|int|bool|null>  $before
     */
    private function recordAgainst(array $before): void
    {
        foreach ($this->snapshot() as $column => $now) {
            $this->changes->record($column, $before[$column], $now);
        }
    }

    /**
     * @throws InvalidCatalogAttribute
     */
    private static function checkDescription(?StructuredText $ar, ?StructuredText $en): void
    {
        if (($ar === null) !== ($en === null)) {
            throw new InvalidCatalogAttribute($ar === null ? 'description_ar' : 'description_en', 'in both languages, or in neither');
        }
    }

    /**
     * @throws InvalidCatalogAttribute
     */
    private static function country(?string $code): ?string
    {
        if ($code === null || trim($code) === '') {
            return null;
        }

        $code = strtoupper(trim($code));

        if (preg_match('/\A[A-Z]{2}\z/', $code) !== 1) {
            throw new InvalidCatalogAttribute('origin_country', 'a two-letter country code');
        }

        return $code;
    }
}
