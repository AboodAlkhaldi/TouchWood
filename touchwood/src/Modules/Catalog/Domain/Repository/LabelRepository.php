<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Repository;

use Modules\Catalog\Domain\Model\Label;

interface LabelRepository
{
    public function nextId(): string;

    public function find(string $labelId): ?Label;

    public function byId(string $labelId): ?Label;

    public function add(Label $label): void;

    public function update(Label $label): void;

    public function delete(string $labelId): void;

    /**
     * @return list<Label> by position and then name: the order labels take on a card
     */
    public function all(): array;
}
