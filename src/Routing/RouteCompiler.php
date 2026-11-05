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

use Nette\InvalidStateException;

/**
 * Turns a route path into what the Router needs, when the DI container compiles.
 *
 * A parameter is `{name}`, `{?name}` when optional, and `{name:token:token}` with
 * a type (int, long, float, decimal, double, bool, alpha, guid, intOrGuid, string,
 * all, regex) and constraints (see ParamChecker). Everything else is literal text,
 * which can't contain the characters of a regular expression. A mistake in a
 * definition fails the compilation instead of quietly changing what the route accepts.
 *
 * @phpstan-import-type Check from ParamChecker
 * @phpstan-type CompiledPath array{literal: ?string, regex: ?string, checks: array<string, list<Check>>, shape: string, rank: string}
 */
final class RouteCompiler
{
    private const Guid = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';
    private const Float = '-?[0-9]+(?:\.[0-9]+)?';
    private const Int = '-?[0-9]+';
    private const Plain = '[^/]+';
    private const All = '.*';

    // Refused in literal text, where they would silently never match what was meant
    private const RegexCharacters = '()[]|+*?^$\\';

    private const Types = [
        'string' => self::Plain,
        'alpha' => '[a-zA-Z]+',
        'bool' => 'true|false|0|1',
        'int' => self::Int,
        'long' => self::Int,
        'float' => self::Float,
        'decimal' => self::Float,
        'double' => self::Float,
        'guid' => self::Guid,
        'intOrGuid' => self::Int . '|' . self::Guid,
        'all' => self::All,
    ];

    // Segment kinds, in the order routes are tried
    private const RankLiteral = '0';
    private const RankConstrained = '1';
    private const RankPlain = '2';
    private const RankAll = '3';

    /**
     * @param array<string, string> $params Regular expressions of `regex` parameters
     * @return CompiledPath
     * @throws InvalidStateException
     */
    public static function compile(string $path, array $params = []): array
    {
        $path = trim($path, '/');
        $regex = '';
        $shape = '';
        $rank = '';
        $checks = [];

        foreach ($path === '' ? [] : explode('/', $path) as $segment) {
            [$segmentRegex, $segmentShape, $segmentRank, $optional, $segmentChecks] = self::compileSegment($segment, $params, $path);

            // Every segment carries its own slash, so an optional one takes it along
            $regex .= $optional ? "(?:/{$segmentRegex})?" : "/{$segmentRegex}";
            $shape .= '/' . $segmentShape;
            $rank .= $segmentRank;
            $checks += $segmentChecks;
        }

        if (!str_contains($path, '{')) {
            return ['literal' => $path, 'regex' => null, 'checks' => [], 'shape' => $path, 'rank' => $rank];
        }

        $regex = '#^' . $regex . '$#Du';
        if (@preg_match($regex, '') === false) {
            throw self::invalid($path, 'it does not compile to a valid regular expression: ' . preg_last_error_msg());
        }

        return ['literal' => null, 'regex' => $regex, 'checks' => $checks, 'shape' => $shape, 'rank' => $rank];
    }

    /**
     * Literal text and parameters of one segment of a path.
     *
     * @return list<string>
     */
    public static function splitSegment(string $segment): array
    {
        return preg_split('/(\{[^{}]*\})/', $segment, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * @param array<string, string> $params
     * @return array{string, string, string, bool, array<string, list<Check>>} Regex, shape, rank, optional, checks
     */
    private static function compileSegment(string $segment, array $params, string $path): array
    {
        $parts = self::splitSegment($segment);

        $regex = '';
        $shape = '';
        $rank = null;
        $checks = [];
        $optional = false;

        foreach ($parts as $part) {
            if (!str_starts_with($part, '{')) {
                if (strpbrk($part, '{}') !== false) {
                    throw self::invalid($path, "unbalanced braces in '{$part}'");
                }
                if (strpbrk($part, self::RegexCharacters) !== false) {
                    throw self::invalid($path, "'{$part}' looks like a regular expression, but text outside {...} is literal. Use {name:regex} and put the expression into #[Route]");
                }
                $regex .= preg_quote($part, '#');
                $shape .= $part;
                continue;
            }

            $parameter = self::parseParameter($part, $params, $path);
            $group = "(?P<{$parameter['name']}>{$parameter['regex']})";
            $alone = count($parts) === 1;

            $optional = $alone && $parameter['optional'];
            $regex .= $parameter['optional'] && !$alone ? $group . '?' : $group;
            $shape .= '{' . ($parameter['optional'] ? '?' : '') . $parameter['regex'] . json_encode($parameter['checks']) . '}';

            $partRank = match (true) {
                $parameter['regex'] === self::All => self::RankAll,
                $parameter['regex'] === self::Plain && $parameter['checks'] === [] => self::RankPlain,
                default => self::RankConstrained,
            };
            $rank = $rank === null ? $partRank : max($rank, $partRank);

            if ($parameter['checks'] !== []) {
                $checks[$parameter['name']] = $parameter['checks'];
            }
        }

        // Literal text around a parameter makes the segment more specific
        if ($rank === self::RankPlain && count($parts) > 1) {
            $rank = self::RankConstrained;
        }

        return [$regex, $shape, $rank ?? self::RankLiteral, $optional, $checks];
    }

    /**
     * @param array<string, string> $params
     * @return array{name: string, optional: bool, regex: string, checks: list<Check>}
     */
    private static function parseParameter(string $definition, array $params, string $path): array
    {
        if (preg_match('/^\{(\??)([a-zA-Z_][a-zA-Z0-9_]*)(?::(.*))?\}$/D', $definition, $matches) !== 1) {
            throw self::invalid($path, "parameter '{$definition}' must be {name}, {?name} or {name:token}");
        }

        $name = $matches[2];
        $regex = self::Plain;
        $checks = [];

        foreach (isset($matches[3]) ? explode(':', $matches[3]) : [] as $token) {
            if ($token === 'regex') {
                $regex = $params[$name] ?? throw self::invalid($path, "'{$name}' is a regex parameter, but #[Route] has no expression for it");
                $regex = str_replace('#', '\#', $regex);
            } elseif (isset(self::Types[$token])) {
                $regex = self::Types[$token];
            } else {
                $checks[] = ParamChecker::parse($token)
                    ?? throw self::invalid($path, "'{$token}' of parameter '{$name}' is neither a type nor a constraint");
            }
        }

        return ['name' => $name, 'optional' => $matches[1] === '?', 'regex' => $regex, 'checks' => $checks];
    }

    private static function invalid(string $path, string $reason): InvalidStateException
    {
        return new InvalidStateException("Invalid route '{$path}': {$reason}.");
    }
}
