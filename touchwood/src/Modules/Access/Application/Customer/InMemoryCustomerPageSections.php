<?php

declare(strict_types=1);

namespace Modules\Access\Application\Customer;

use Illuminate\Contracts\Container\Container;
use LogicException;
use Modules\Access\Public\Contracts\CustomerPageSection;
use Modules\Access\Public\Contracts\CustomerPageSections;
use Modules\Access\Public\Dto\CustomerPageSectionDto;

/**
 * The staff customer page's sections from other modules, collected from their service providers at
 * boot (access.md amendment 72(a)), as the account's pages are.
 *
 * It keeps class names, not objects, and resolves each section when it is asked: this list lives for
 * the whole application, and a section depends on the staff member looking.
 */
final class InMemoryCustomerPageSections implements CustomerPageSections
{
    /** @var list<CustomerPageSectionDto> */
    private array $sections = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    public function register(CustomerPageSectionDto ...$sections): void
    {
        foreach ($sections as $section) {
            if (! is_subclass_of($section->section, CustomerPageSection::class)) {
                throw new LogicException("\"{$section->section}\" does not implement ".CustomerPageSection::class.'.');
            }

            foreach ($this->sections as $registered) {
                if ($registered->module === $section->module && $registered->key === $section->key) {
                    throw new LogicException("The customer page section \"{$section->module}.{$section->key}\" is registered twice.");
                }
            }

            $this->sections[] = $section;
        }
    }

    public function all(): array
    {
        $sections = $this->sections;
        usort($sections, static fn (CustomerPageSectionDto $a, CustomerPageSectionDto $b): int => [$a->position, $a->module, $a->key] <=> [$b->position, $b->module, $b->key]);

        return $sections;
    }

    /**
     * Each section's answer for this customer and the staff member looking, lowest position first;
     * a section that answers null is left out.
     *
     * @return list<array{module: string, key: string, data: array<string, mixed>}>
     */
    public function answersFor(string $customerId): array
    {
        $answers = [];

        foreach ($this->all() as $section) {
            $reader = $this->container->make($section->section);

            // Checked when it was registered; resolved from the container, so asked again.
            if (! $reader instanceof CustomerPageSection) {
                throw new LogicException("\"{$section->section}\" does not implement ".CustomerPageSection::class.'.');
            }

            $data = $reader->sectionFor($customerId);

            if ($data !== null) {
                $answers[] = ['module' => $section->module, 'key' => $section->key, 'data' => $data];
            }
        }

        return $answers;
    }

    /**
     * Every word file the sections say things from, once each.
     *
     * @return list<string>
     */
    public function words(): array
    {
        return array_values(array_unique(array_merge(...array_map(
            static fn (CustomerPageSectionDto $section): array => $section->words,
            $this->sections,
        ))));
    }
}
