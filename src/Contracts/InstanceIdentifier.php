<?php

namespace Gloudemans\Shoppingcart\Contracts;

interface InstanceIdentifier
{
    public function getInstanceIdentifier($options = null);

    public function getInstanceGlobalDiscount($options = null);
}
