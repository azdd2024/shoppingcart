<?php

return [

    'calculator' => \Gloudemans\Shoppingcart\Calculation\DefaultCalculator::class,

    'tax' => 21,

    'database' => [

        'connection' => null,

        'table' => 'shoppingcart',

    ],

    'destroy_on_logout' => false,

    'format' => [

        'decimals' => 2,

        'decimal_point' => '.',

        'thousand_separator' => ',',

    ],

];
