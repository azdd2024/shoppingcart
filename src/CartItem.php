<?php

namespace Gloudemans\Shoppingcart;

use Gloudemans\Shoppingcart\Calculation\DefaultCalculator;
use Gloudemans\Shoppingcart\Contracts\Buyable;
use Gloudemans\Shoppingcart\Contracts\Calculator;
use Gloudemans\Shoppingcart\Exceptions\InvalidCalculatorException;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use ReflectionClass;

class CartItem implements Arrayable, Jsonable
{
    public $rowId;

    public $id;

    public $qty;

    public $name;

    public $price;

    public $weight;

    public $options;

    public $taxRate = 0;

    private $associatedModel = null;

    private $discountRate = 0;

    public $instance = null;

    public function __construct($id, $name, $price, $weight = 0, array $options = [])
    {
        if (empty($id)) {
            throw new InvalidArgumentException('Please supply a valid identifier.');
        }
        if (empty($name)) {
            throw new InvalidArgumentException('Please supply a valid name.');
        }
        if (!is_numeric($price)) {
            throw new InvalidArgumentException('Please supply a valid price.');
        }
        if (!is_numeric($weight)) {
            throw new InvalidArgumentException('Please supply a valid weight.');
        }

        $this->id = $id;
        $this->name = $name;
        $this->price = floatval($price);
        $this->weight = floatval($weight);
        $this->options = new CartItemOptions($options);
        $this->rowId = $this->generateRowId($id, $options);
    }

    public function weight($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        return $this->numberFormat($this->weight, $decimals, $decimalPoint, $thousandSeperator);
    }

    public function price($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        return $this->numberFormat($this->price, $decimals, $decimalPoint, $thousandSeperator);
    }

    public function priceTarget($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        return $this->numberFormat($this->priceTarget, $decimals, $decimalPoint, $thousandSeperator);
    }

    public function priceTax($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        return $this->numberFormat($this->priceTax, $decimals, $decimalPoint, $thousandSeperator);
    }

    public function subtotal($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        return $this->numberFormat($this->subtotal, $decimals, $decimalPoint, $thousandSeperator);
    }

    public function total($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        return $this->numberFormat($this->total, $decimals, $decimalPoint, $thousandSeperator);
    }

    public function tax($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        return $this->numberFormat($this->tax, $decimals, $decimalPoint, $thousandSeperator);
    }

    public function taxTotal($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        return $this->numberFormat($this->taxTotal, $decimals, $decimalPoint, $thousandSeperator);
    }

    public function discount($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        return $this->numberFormat($this->discount, $decimals, $decimalPoint, $thousandSeperator);
    }

    public function discountTotal($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        return $this->numberFormat($this->discountTotal, $decimals, $decimalPoint, $thousandSeperator);
    }

    public function priceTotal($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        return $this->numberFormat($this->priceTotal, $decimals, $decimalPoint, $thousandSeperator);
    }

    public function setQuantity($qty)
    {
        if (empty($qty) || !is_numeric($qty)) {
            throw new InvalidArgumentException('Please supply a valid quantity.');
        }

        $this->qty = $qty;
    }

    public function updateFromBuyable(Buyable $item)
    {
        $this->id = $item->getBuyableIdentifier($this->options);
        $this->name = $item->getBuyableDescription($this->options);
        $this->price = $item->getBuyablePrice($this->options);
    }

    public function updateFromArray(array $attributes)
    {
        $this->id = Arr::get($attributes, 'id', $this->id);
        $this->qty = Arr::get($attributes, 'qty', $this->qty);
        $this->name = Arr::get($attributes, 'name', $this->name);
        $this->price = Arr::get($attributes, 'price', $this->price);
        $this->weight = Arr::get($attributes, 'weight', $this->weight);
        $this->options = new CartItemOptions(Arr::get($attributes, 'options', $this->options));

        $this->rowId = $this->generateRowId($this->id, $this->options->all());
    }

    public function associate($model)
    {
        $this->associatedModel = is_string($model) ? $model : $model::class;

        return $this;
    }

    public function setTaxRate($taxRate)
    {
        $this->taxRate = $taxRate;

        return $this;
    }

    public function setDiscountRate($discountRate)
    {
        $this->discountRate = $discountRate;

        return $this;
    }

    public function setInstance($instance)
    {
        $this->instance = $instance;

        return $this;
    }

    public function __get($attribute)
    {
        if (property_exists($this, $attribute)) {
            return $this->{$attribute};
        }

        $hasModel = isset($this->associatedModel);

        if ($attribute === 'model' && $hasModel) {
            return (new $this->associatedModel())->find($this->id);
        }

        if ($attribute === 'modelFQCN' && $hasModel) {
            return $this->associatedModel;
        }

        if ($attribute === 'model' || $attribute === 'modelFQCN' || $attribute === 'weightTotal') {
            return round($this->weight * $this->qty, config('cart.format.decimals', 2));
        }

        $calculator = self::resolveCalculator(config('cart.calculator', DefaultCalculator::class));

        return $calculator::getAttribute($attribute, $this);
    }

    public static function fromBuyable(Buyable $item, array $options = [])
    {
        return new self($item->getBuyableIdentifier($options), $item->getBuyableDescription($options), $item->getBuyablePrice($options), $item->getBuyableWeight($options), $options);
    }

    public static function fromArray(array $attributes)
    {
        $options = Arr::get($attributes, 'options', []);

        return new self($attributes['id'], $attributes['name'], $attributes['price'], $attributes['weight'], $options);
    }

    public static function fromAttributes($id, $name, $price, $weight, array $options = [])
    {
        return new self($id, $name, $price, $weight, $options);
    }

    protected function generateRowId($id, array $options)
    {
        ksort($options);

        return md5($id.serialize($options));
    }

    public function toArray()
    {
        return [
            'rowId'    => $this->rowId,
            'id'       => $this->id,
            'name'     => $this->name,
            'qty'      => $this->qty,
            'price'    => $this->price,
            'weight'   => $this->weight,
            'options'  => is_object($this->options)
                ? $this->options->toArray()
                : $this->options,
            'discount' => $this->discount,
            'tax'      => $this->tax,
            'subtotal' => $this->subtotal,
        ];
    }

    public function toJson($options = 0)
    {
        return json_encode($this->toArray(), $options);
    }

    private function numberFormat($value, $decimals, $decimalPoint, $thousandSeperator): string
    {
        $decimals ??= config('cart.format.decimals', 2);
        $decimalPoint ??= config('cart.format.decimal_point', '.');
        $thousandSeperator ??= config('cart.format.thousand_separator', ',');

        return number_format($value, $decimals, $decimalPoint, $thousandSeperator);
    }

    public function getDiscountRate()
    {
        return $this->discountRate;
    }

    private static function resolveCalculator($calculator): string
    {
        static $validated = [];

        if (is_string($calculator) && isset($validated[$calculator])) {
            return $validated[$calculator];
        }

        $class = new ReflectionClass($calculator);

        if (!$class->implementsInterface(Calculator::class)) {
            throw new InvalidCalculatorException('The configured Calculator seems to be invalid. Calculators have to implement the Calculator Contract.');
        }

        if (is_string($calculator)) {
            $validated[$calculator] = $class->getName();
        }

        return $class->getName();
    }
}
