<?php

namespace Gloudemans\Tests\Shoppingcart;

use Gloudemans\Shoppingcart\Cart;
use PHPUnit\Framework\Assert as PHPUnit;

trait CartAssertions
{
    public function assertItemsInCart($items, Cart $cart)
    {
        $actual = $cart->count();

        PHPUnit::assertEquals($items, $actual, "Expected the cart to contain {$items} items, but got {$actual}.");
    }

    public function assertRowsInCart($rows, Cart $cart)
    {
        $actual = $cart->content()->count();

        PHPUnit::assertCount($rows, $cart->content(), "Expected the cart to contain {$rows} rows, but got {$actual}.");
    }
}
