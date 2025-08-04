<?php

namespace Exceptions;

use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

class ServiceNotFound extends RuntimeException implements NotFoundExceptionInterface
{
    private string $serviceName;

    public function __construct(string $serviceName)
    {
        parent::__construct("Service `{$serviceName}` is not defined in the container.");
    }

    public function getServiceName(): string
    {
        return $this->serviceName;
    }
}