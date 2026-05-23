<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Dto;

enum Priority: int
{
    case Low = 1;
    case Normal = 5;
    case High = 10;
}
