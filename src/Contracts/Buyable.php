<?php

namespace Gloudemans\Shoppingcart\Contracts;

interface Buyable
{
    public function getBuyableIdentifier($options = null);

    public function getBuyableDescription($options = null);

    public function getBuyablePrice($options = null);

    public function getBuyableWeight($options = null);
}
