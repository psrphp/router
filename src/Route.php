<?php

declare(strict_types=1);

namespace PsrPHP\Router;

class Route
{
    private $found = false;
    private $allowed = false;
    private $handler = null;
    private $middlewares = [];
    private $params = [];

    public function __construct(bool $found, bool $allowed = false, string $handler = '', array $middlewares = [], array $params = [])
    {
        $this->setFound($found);
        $this->setAllowed($allowed);
        $this->setHandler($handler);
        $this->setMiddlewares($middlewares);
        $this->setParams($params);
    }

    public function setFound(bool $found): self
    {
        $this->found = $found;
        return $this;
    }

    public function setAllowed(bool $allowed): self
    {
        $this->allowed = $allowed;
        return $this;
    }

    public function setHandler(string $handler): self
    {
        $this->handler = $handler;
        return $this;
    }

    public function setMiddlewares(array $middlewares): self
    {
        $this->middlewares = $middlewares;
        return $this;
    }

    public function setParams(array $params): self
    {
        $this->params = $params;
        return $this;
    }

    public function isFound(): bool
    {
        return $this->found;
    }

    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    public function getHandler(): string
    {
        return $this->handler;
    }

    public function getMiddleWares(): array
    {
        return $this->middlewares;
    }

    public function getParams(): array
    {
        return $this->params;
    }
}
