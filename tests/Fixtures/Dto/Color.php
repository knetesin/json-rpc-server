<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Dto;

enum Color: string
{
    case Red = 'red';
    case Green = 'green';
    case Blue = 'blue';
}
