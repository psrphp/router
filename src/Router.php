<?php

declare(strict_types=1);

namespace PsrPHP\Router;

use LogicException;

class Router
{
    protected $parser;
    protected $generator;

    protected $currentGroupPrefix = '';
    protected $currentParams = [];

    protected $staticRoutes = [];
    protected $methodToRegexToRoutesMap = [];

    const DEFAULT_DISPATCH_REGEX = '[^/]+';
    const VARIABLE_REGEX = <<<'REGEX'
\{
    \s* ([a-zA-Z_][a-zA-Z0-9_-]*) \s*
    (?:
        : \s* ([^{}]*(?:\{(?-1)\}[^{}]*)*)
    )?
\}
REGEX;

    public function __construct(string $prefix = '')
    {
        $this->currentGroupPrefix = $prefix;
    }

    public function addGroup(string $prefix, callable $callback, array $params = []): self
    {
        $previousGroupPrefix = $this->currentGroupPrefix;
        $previousParams = $this->currentParams;
        $this->currentGroupPrefix = $previousGroupPrefix . $prefix;
        $this->currentParams = array_merge($this->currentParams, $params);
        $callback($this);
        $this->currentGroupPrefix = $previousGroupPrefix;
        $this->currentParams = $previousParams;
        return $this;
    }

    public function addRoute(
        string $route,
        string $handler,
        string $name = null,
        array $methods = ['*'],
        array $params = [],
    ): self {
        $route = $this->currentGroupPrefix . $route;
        $routeDatas = $this->parse($route);
        $params = array_merge($params, $this->currentParams);
        foreach ($methods as $method) {
            foreach ($routeDatas as $routeData) {
                $this->addData($method, $routeData, $handler, $params, $name);
            }
        }
        return $this;
    }

    public function dispatch(string $httpMethod, string $uri): array
    {
        list($staticRouteMap, $varRouteMap) = $this->getData();

        if (isset($staticRouteMap[$httpMethod][$uri])) {
            $staticRouteData = $staticRouteMap[$httpMethod][$uri];
            return [true, true, $staticRouteData['handler'], $staticRouteData['params']];
        }

        if (isset($staticRouteMap['*'][$uri])) {
            $staticRouteData = $staticRouteMap['*'][$uri];
            return [true, true, $staticRouteData['handler'], $staticRouteData['params']];
        }

        if ($httpMethod === 'HEAD') {
            if (isset($staticRouteMap['GET'][$uri])) {
                $staticRouteData = $staticRouteMap['GET'][$uri];
                return [true, true, $staticRouteData['handler'], $staticRouteData['params']];
            }
        }

        if (isset($varRouteMap[$httpMethod])) {
            if ($result = $this->dispatchVariableRoute($varRouteMap[$httpMethod], $uri)) {
                return [true, true, ...$result];
            }
        }

        if (isset($varRouteMap['*'])) {
            if ($result = $this->dispatchVariableRoute($varRouteMap['*'], $uri)) {
                return [true, true, ...$result];
            }
        }

        if ($httpMethod === 'HEAD') {
            if (isset($varRouteMap['GET'])) {
                if ($result = $this->dispatchVariableRoute($varRouteMap['GET'], $uri)) {
                    return [true, true, ...$result];
                }
            }
        }

        if ($httpMethod === 'HEAD') {
            $methods = [$httpMethod, 'GET', '*'];
        } else {
            $methods = [$httpMethod, '*'];
        }

        foreach ($staticRouteMap as $method => $uriMap) {
            if (in_array($method, $methods)) {
                continue;
            }

            if (isset($uriMap[$uri])) {
                return [true, false, $uriMap[$uri]['handler'], $uriMap[$uri]['params']];
            }
        }

        foreach ($varRouteMap as $method => $routeData) {
            if (in_array($method, $methods)) {
                continue;
            }

            if ($result = $this->dispatchVariableRoute($routeData, $uri)) {
                return [true, false, ...$result];
            }
        }

        return [false];
    }

    protected function dispatchVariableRoute(array $routeData, string $uri): ?array
    {
        foreach ($routeData as $data) {
            if (!preg_match($data['regex'], $uri, $matches)) {
                continue;
            }
            $route = $data['routeMap'][count($matches)];
            $vars = [];
            $i = 0;
            foreach ($route['variables'] as $varName) {
                $vars[$varName] = $matches[++$i];
            }
            return [$route['handler'], array_merge($vars, $route['params'])];
        }
        return null;
    }

    public function build(string $name, array $querys = [], string $methods = 'GET'): string
    {
        list($staticRouteMap, $variableRouteData) = $this->getData();
        $methods = explode('|', strtoupper($methods));

        $check_querys = function (array $route_querys, array $build_querys): bool {
            foreach ($route_querys as $key => $value) {
                if (isset($build_querys[$key]) && ($build_querys[$key] != $value)) {
                    return false;
                }
            }
            return true;
        };

        foreach ($staticRouteMap as $method => $routes) {
            if ($method != '*' && !in_array($method, $methods)) {
                continue;
            }
            foreach ($routes as $route) {
                if ($route['name'] != $name) {
                    continue;
                }
                if (!$check_querys($route['params'], $querys)) {
                    continue;
                }
                $querys_diff = array_diff_key($querys, $route['params']);
                if ($querys_diff) {
                    return $route['routeStr'] . '?' . http_build_query($querys_diff);
                } else {
                    return $route['routeStr'];
                }
            }
        }

        $build = function (array $routeData, $params): ?array {
            $uri = '';
            foreach ($routeData as $part) {
                if (is_array($part)) {
                    if (
                        isset($params[$part[0]])
                        && preg_match('~^' . $part[1] . '$~', (string) $params[$part[0]])
                    ) {
                        $uri .= urlencode((string) $params[$part[0]]);
                        unset($params[$part[0]]);
                        continue;
                    } else {
                        return null;
                    }
                } else {
                    $uri .= $part;
                }
            }
            return [$uri, $params];
        };

        foreach ($variableRouteData as $method => $chunks) {
            if ($method != '*' && !in_array($method, $methods)) {
                continue;
            }
            foreach ($chunks as $chunk) {
                foreach ($chunk['routeMap'] as $route) {
                    if ($route['name'] != $name) {
                        continue;
                    }
                    if (!$check_querys($route['params'], $querys)) {
                        continue;
                    }
                    $tmp = $build($route['routeData'], array_diff_key($querys, $route['params']));
                    if (!is_array($tmp)) {
                        continue;
                    }
                    if ($tmp[1]) {
                        return $tmp[0] . '?' . http_build_query($tmp[1]);
                    } else {
                        return $tmp[0];
                    }
                }
            }
        }

        if ($querys) {
            return self::getSiteRoot() . $name . '?' . http_build_query($querys);
        } else {
            return self::getSiteRoot() . $name;
        }
    }

    public static function getSiteRoot(): string
    {
        $scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
        $host = $port = null;
        if (isset($_SERVER['HTTP_HOST'])) {
            $uri = 'http://' . $_SERVER['HTTP_HOST'];
            $parts = parse_url($uri);
            if (false !== $parts) {
                $host = isset($parts['host']) ? $parts['host'] : null;
                $port = isset($parts['port']) ? $parts['port'] : null;
            }
        } elseif (isset($_SERVER['SERVER_NAME'])) {
            $host = $_SERVER['SERVER_NAME'];
        } elseif (isset($_SERVER['SERVER_ADDR'])) {
            $host = $_SERVER['SERVER_ADDR'];
        }

        if (is_null($port) && isset($_SERVER['SERVER_PORT'])) {
            $port = $_SERVER['SERVER_PORT'];
        }

        $site_base = $scheme . '://' . $host . (in_array($port, [null, 80, 443]) ? '' : ':' . $port);
        if (strpos($_SERVER['REQUEST_URI'] ?? '', $_SERVER['SCRIPT_NAME']) === 0) {
            $site_path = $_SERVER['SCRIPT_NAME'];
        } else {
            $dir_script = dirname($_SERVER['SCRIPT_NAME']);
            $site_path = strlen($dir_script) > 1 ? $dir_script : '';
        }
        return $site_base . $site_path;
    }

    protected function addData(
        string $httpMethod,
        array $routeData,
        string $handler,
        array $params = [],
        string $name = null,
    ) {
        ksort($params);
        if ($this->isStaticRoute($routeData)) {
            $this->addStaticRoute(strtoupper($httpMethod), $routeData, $handler, $params, $name);
        } else {
            $this->addVariableRoute(strtoupper($httpMethod), $routeData, $handler, $params, $name);
        }
    }

    public function getData(): array
    {
        if (empty($this->methodToRegexToRoutesMap)) {
            return [$this->staticRoutes, []];
        }

        return [$this->staticRoutes, $this->generateVariableRouteData()];
    }

    protected function getApproxChunkSize(): int
    {
        return 10;
    }

    protected function processChunk(array $regexToRoutesMap): array
    {
        $routeMap = [];
        $regexes = [];
        $numGroups = 0;
        foreach ($regexToRoutesMap as $regex => $route) {
            $numVariables = count($route['variables']);
            $numGroups = max($numGroups, $numVariables);

            $regexes[] = $regex . str_repeat('()', $numGroups - $numVariables);
            $routeMap[$numGroups + 1] = $route;

            ++$numGroups;
        }

        $regex = '~^(?|' . implode('|', $regexes) . ')$~';
        return [
            'regex' => $regex,
            'routeMap' => $routeMap,
        ];
    }

    private function generateVariableRouteData(): array
    {
        $data = [];
        foreach ($this->methodToRegexToRoutesMap as $method => $regexToRoutesMap) {
            $chunkSize = $this->computeChunkSize(count($regexToRoutesMap));
            $chunks = array_chunk($regexToRoutesMap, $chunkSize, true);
            $data[$method] = array_map([$this, 'processChunk'], $chunks);
        }
        return $data;
    }

    private function computeChunkSize(int $count): int
    {
        $numParts = max(1, round($count / $this->getApproxChunkSize()));
        return (int) ceil($count / $numParts);
    }

    private function isStaticRoute(array $routeData): bool
    {
        return count($routeData) === 1 && is_string($routeData[0]);
    }

    private function addStaticRoute(
        string $httpMethod,
        array $routeData,
        string $handler,
        array $params = [],
        string $name = null,
    ) {
        $routeStr = $routeData[0];

        if (isset($this->staticRoutes[$httpMethod][$routeStr])) {
            return;
        }

        if (isset($this->methodToRegexToRoutesMap[$httpMethod])) {
            foreach ($this->methodToRegexToRoutesMap[$httpMethod] as $route) {
                if (preg_match('~^' . $route['regex'] . '$~', $routeStr)) {
                    throw new LogicException(sprintf(
                        'Static route "%s" is shadowed by previously defined variable route "%s" for method "%s"',
                        $routeStr,
                        $route['regex'],
                        $httpMethod
                    ));
                }
            }
        }

        $this->staticRoutes[$httpMethod][$routeStr] = [
            'handler' => $handler,
            'params' => $params,
            'name' => $name,
            'routeStr' => $routeStr,
            'routeData' => $routeData,
        ];
    }

    private function addVariableRoute(
        string $httpMethod,
        array $routeData,
        string $handler,
        array $params = [],
        string $name = null,
    ) {
        list($regex, $variables) = $this->buildRegexForRoute($routeData);

        if (isset($this->methodToRegexToRoutesMap[$httpMethod][$regex])) {
            return;
        }

        $this->methodToRegexToRoutesMap[$httpMethod][$regex] = [
            'handler' => $handler,
            'params' => $params,
            'name' => $name,
            'regex' => $regex,
            'routeData' => $routeData,
            'variables' => $variables,
        ];
    }

    private function buildRegexForRoute(array $routeData): array
    {
        $regex = '';
        $variables = [];
        foreach ($routeData as $part) {
            if (is_string($part)) {
                $regex .= preg_quote($part, '~');
                continue;
            }

            list($varName, $regexPart) = $part;

            if (isset($variables[$varName])) {
                throw new LogicException(sprintf(
                    'Cannot use the same placeholder "%s" twice',
                    $varName
                ));
            }

            if ($this->regexHasCapturingGroups($regexPart)) {
                throw new LogicException(sprintf(
                    'Regex "%s" for parameter "%s" contains a capturing group',
                    $regexPart,
                    $varName
                ));
            }

            $variables[$varName] = $varName;
            $regex .= '(' . $regexPart . ')';
        }

        return [$regex, $variables];
    }

    private function regexHasCapturingGroups(string $regex): bool
    {
        if (false === strpos($regex, '(')) {
            return false;
        }

        return (bool) preg_match(
            '~
                (?:
                    \(\?\(
                  | \[ [^\]\\\\]* (?: \\\\ . [^\]\\\\]* )* \]
                  | \\\\ .
                ) (*SKIP)(*FAIL) |
                \(
                (?!
                    \? (?! <(?![!=]) | P< | \' )
                  | \*
                )
            ~x',
            $regex
        );
    }

    protected function parse(string $route): array
    {
        $routeWithoutClosingOptionals = rtrim($route, ']');
        $numOptionals = strlen($route) - strlen($routeWithoutClosingOptionals);

        $segments = preg_split('~' . self::VARIABLE_REGEX . '(*SKIP)(*F) | \[~x', $routeWithoutClosingOptionals);
        if ($numOptionals !== count($segments) - 1) {
            if (preg_match('~' . self::VARIABLE_REGEX . '(*SKIP)(*F) | \]~x', $routeWithoutClosingOptionals)) {
                throw new LogicException('Optional segments can only occur at the end of a route');
            }
            throw new LogicException("Number of opening '[' and closing ']' does not match");
        }

        $currentRoute = '';
        $routeDatas = [];
        foreach ($segments as $n => $segment) {
            if ($segment === '' && $n !== 0) {
                throw new LogicException('Empty optional part');
            }

            $currentRoute .= $segment;
            $routeDatas[] = $this->parsePlaceholders($currentRoute);
        }
        return $routeDatas;
    }

    private function parsePlaceholders(string $route): array
    {
        if (!preg_match_all(
            '~' . self::VARIABLE_REGEX . '~x',
            $route,
            $matches,
            PREG_OFFSET_CAPTURE | PREG_SET_ORDER
        )) {
            return [$route];
        }

        $offset = 0;
        $routeData = [];
        foreach ($matches as $set) {
            if ($set[0][1] > $offset) {
                $routeData[] = substr($route, $offset, $set[0][1] - $offset);
            }
            $routeData[] = [
                $set[1][0],
                isset($set[2]) ? trim($set[2][0]) : self::DEFAULT_DISPATCH_REGEX,
            ];
            $offset = $set[0][1] + strlen($set[0][0]);
        }

        if ($offset !== strlen($route)) {
            $routeData[] = substr($route, $offset);
        }

        return $routeData;
    }
}
