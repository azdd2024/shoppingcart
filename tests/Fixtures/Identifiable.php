<?php

namespace Gloudemans\Tests\Shoppingcart\Fixtures;

use Gloudemans\Shoppingcart\Contracts\InstanceIdentifier;

class Identifiable implements InstanceIdentifier
{
    public function __construct(
        private $identifier = 'identifier',
        private $discountRate = 0,
    ) {
    }

    public function getInstanceIdentifier($options = null)
    {
        return $this->identifier;
    }

    public function getInstanceGlobalDiscount($options = null)
    {
        return $this->discountRate;
    }
}
