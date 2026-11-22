<?php

declare(strict_types=1);

namespace Roave\BetterReflection\Reflection\Deprecated;

use Deprecated;
use Roave\BetterReflection\Reflection\Annotation\AnnotationHelper;
use Roave\BetterReflection\Reflection\Attribute\ReflectionAttributeHelper;
use Roave\BetterReflection\Reflection\ReflectionClass;
use Roave\BetterReflection\Reflection\ReflectionClassConstant;
use Roave\BetterReflection\Reflection\ReflectionConstant;
use Roave\BetterReflection\Reflection\ReflectionEnumCase;
use Roave\BetterReflection\Reflection\ReflectionFunction;
use Roave\BetterReflection\Reflection\ReflectionMethod;
use Roave\BetterReflection\Reflection\ReflectionProperty;

/** @internal */
final class DeprecatedHelper
{
    /** @psalm-pure */
    public static function isDeprecated(ReflectionClass|ReflectionMethod|ReflectionFunction|ReflectionConstant|ReflectionClassConstant|ReflectionEnumCase|ReflectionProperty $reflection): bool
    {
        if (ReflectionAttributeHelper::filterAttributesByName($reflection->getAttributes(), Deprecated::class) !== []) {
            return true;
        }

        return AnnotationHelper::isDeprecated($reflection->getDocComment());
    }
}
