<?php
/**
 * This file is part of phloxcz/rest-api.
 *
 * (c) Phlox <https://github.com/phloxcz>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Phlox\RestApi\Routing;

/**
 * Constraints of route parameters: min(1), max(9), range(1,9), length(3),
 * length(1,3), minlength(1), maxlength(3).
 *
 * A value breaking a constraint means the route doesn't match, so the next route
 * gets its chance.
 *
 * @phpstan-type Check array{name: string, value: list<int|float>}
 */
final class ParamChecker
{
    private const Number = '(-?[0-9]+(?:\.[0-9]+)?)';
    private const Count = '([0-9]+)';

    private const Patterns = [
        'min' => '/^min\(' . self::Number . '\)$/Di',
        'max' => '/^max\(' . self::Number . '\)$/Di',
        'range' => '/^range\(' . self::Number . ',' . self::Number . '\)$/Di',
        'length' => '/^length\(' . self::Count . '(?:,' . self::Count . ')?\)$/Di',
        'minlength' => '/^minlength\(' . self::Count . '\)$/Di',
        'maxlength' => '/^maxlength\(' . self::Count . '\)$/Di',
    ];

    /**
     * @return Check|null Null when the definition isn't a constraint
     */
    public static function parse(string $definition): ?array
    {
        foreach (self::Patterns as $name => $pattern) {
            if (preg_match($pattern, $definition, $matches) === 1) {
                $values = array_map(
                    static fn(string $number): int|float => str_contains($number, '.') ? (float) $number : (int) $number,
                    array_slice($matches, 1),
                );
                return ['name' => $name, 'value' => $values];
            }
        }

        return null;
    }

    /**
     * @param Check $check
     */
    public static function check(string $value, array $check): bool
    {
        $arguments = $check['value'];

        return match ($check['name']) {
            'min' => is_numeric($value) && $value >= $arguments[0],
            'max' => is_numeric($value) && $value <= $arguments[0],
            'range' => is_numeric($value) && $value >= $arguments[0] && $value <= $arguments[1],
            'length' => count($arguments) === 2
                ? mb_strlen($value) >= $arguments[0] && mb_strlen($value) <= $arguments[1]
                : mb_strlen($value) === $arguments[0],
            'minlength' => mb_strlen($value) >= $arguments[0],
            'maxlength' => mb_strlen($value) <= $arguments[0],
            default => throw new \LogicException("Unknown route constraint '{$check['name']}'."),
        };
    }
}
