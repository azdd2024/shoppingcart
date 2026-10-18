<?php

namespace Gloudemans\Shoppingcart;

use Carbon\Carbon;
use Closure;
use Gloudemans\Shoppingcart\Contracts\Buyable;
use Gloudemans\Shoppingcart\Contracts\InstanceIdentifier;
use Gloudemans\Shoppingcart\Exceptions\CartAlreadyStoredException;
use Gloudemans\Shoppingcart\Exceptions\InvalidRowIDException;
use Gloudemans\Shoppingcart\Exceptions\UnknownModelException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Traits\Macroable;

class Cart
{
    use Macroable;

    const DEFAULT_INSTANCE = 'default';

    private string $instance;

    private ?Carbon $createdAt = null;

    private ?Carbon $updatedAt = null;

    private $discount = 0;

    private $taxRate = 0;

    public function __construct(
        private readonly SessionManager $session,
        private readonly Dispatcher $events,
    ) {
        $this->taxRate = config('cart.tax');

        $this->instance(self::DEFAULT_INSTANCE);
    }

    public function instance($instance = null)
    {
        $instance = $instance ?: self::DEFAULT_INSTANCE;

        if ($instance instanceof InstanceIdentifier) {
            $this->discount = $instance->getInstanceGlobalDiscount();
            $instance = $instance->getInstanceIdentifier();
        }

        $this->instance = 'cart.'.$instance;

        return $this;
    }

    public function currentInstance()
    {
        return str_replace('cart.', '', $this->instance);
    }

    public function add($id, $name = null, $qty = null, $price = null, $weight = 0, array $options = [])
    {
        if ($this->isMulti($id)) {
            return array_map(fn ($item) => $this->add($item), $id);
        }

        $cartItem = $this->createCartItem($id, $name, $qty, $price, $weight, $options);

        return $this->addCartItem($cartItem);
    }

    public function addCartItem($item, $keepDiscount = false, $keepTax = false, $dispatchEvent = true)
    {
        if (!$keepDiscount) {
            $item->setDiscountRate($this->discount);
        }

        if (!$keepTax) {
            $item->setTaxRate($this->taxRate);
        }

        $content = $this->getContent();

        if ($content->has($item->rowId)) {
            $item->qty += $content->get($item->rowId)->qty;
        }

        $content->put($item->rowId, $item);

        if ($dispatchEvent) {
            $this->events->dispatch('cart.adding', $item);
        }

        $this->session->put($this->instance, $content);

        if ($dispatchEvent) {
            $this->events->dispatch('cart.added', $item);
        }

        return $item;
    }

    public function update($rowId, $qty)
    {
        $cartItem = $this->get($rowId);

        if ($qty instanceof Buyable) {
            $cartItem->updateFromBuyable($qty);
        } elseif (is_array($qty)) {
            $cartItem->updateFromArray($qty);
        } else {
            $cartItem->qty = $qty;
        }

        $content = $this->getContent();

        $itemOldIndex = null;

        if ($rowId !== $cartItem->rowId) {
            $itemOldIndex = $content->keys()->search($rowId);

            $content->pull($rowId);

            if ($content->has($cartItem->rowId)) {
                $existingCartItem = $this->get($cartItem->rowId);
                $cartItem->setQuantity($existingCartItem->qty + $cartItem->qty);
            }
        }

        if ($cartItem->qty <= 0) {
            $this->remove($cartItem->rowId);

            return;
        }

        if ($itemOldIndex !== null) {
            $content = $content->slice(0, $itemOldIndex)
                ->merge([$cartItem->rowId => $cartItem])
                ->merge($content->slice($itemOldIndex));
        } else {
            $content->put($cartItem->rowId, $cartItem);
        }

        $this->events->dispatch('cart.updating', $cartItem);

        $this->session->put($this->instance, $content);

        $this->events->dispatch('cart.updated', $cartItem);

        return $cartItem;
    }

    public function remove($rowId)
    {
        $cartItem = $this->get($rowId);

        $content = $this->getContent();

        $content->pull($cartItem->rowId);

        $this->events->dispatch('cart.removing', $cartItem);

        $this->session->put($this->instance, $content);

        $this->events->dispatch('cart.removed', $cartItem);
    }

    public function get($rowId)
    {
        $content = $this->getContent();

        if (!$content->has($rowId)) {
            throw new InvalidRowIDException("The cart does not contain rowId {$rowId}.");
        }

        return $content->get($rowId);
    }

    public function destroy()
    {
        $this->session->remove($this->instance);
    }

    public function content()
    {
        return $this->session->get($this->instance) ?? new Collection([]);
    }

    public function count()
    {
        return $this->getContent()->sum('qty');
    }

    public function countItems()
    {
        return $this->getContent()->count();
    }

    public function totalFloat()
    {
        return $this->sumItems(static fn (CartItem $cartItem) => $cartItem->total);
    }

    public function total($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        return $this->numberFormat($this->totalFloat(), $decimals, $decimalPoint, $thousandSeperator);
    }

    public function taxFloat()
    {
        return $this->sumItems(static fn (CartItem $cartItem) => $cartItem->taxTotal);
    }

    public function tax($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        return $this->numberFormat($this->taxFloat(), $decimals, $decimalPoint, $thousandSeperator);
    }

    public function subtotalFloat()
    {
        return $this->sumItems(static fn (CartItem $cartItem) => $cartItem->subtotal);
    }

    public function subtotal($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        return $this->numberFormat($this->subtotalFloat(), $decimals, $decimalPoint, $thousandSeperator);
    }

    public function discountFloat()
    {
        return $this->sumItems(static fn (CartItem $cartItem) => $cartItem->discountTotal);
    }

    public function discount($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        return $this->numberFormat($this->discountFloat(), $decimals, $decimalPoint, $thousandSeperator);
    }

    public function initialFloat()
    {
        return $this->sumItems(static fn (CartItem $cartItem) => $cartItem->qty * $cartItem->price);
    }

    public function initial($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        return $this->numberFormat($this->initialFloat(), $decimals, $decimalPoint, $thousandSeperator);
    }

    public function priceTotalFloat()
    {
        return $this->sumItems(static fn (CartItem $cartItem) => $cartItem->priceTotal);
    }

    public function priceTotal($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        return $this->numberFormat($this->priceTotalFloat(), $decimals, $decimalPoint, $thousandSeperator);
    }

    public function weightFloat()
    {
        return $this->sumItems(static fn (CartItem $cartItem) => $cartItem->qty * $cartItem->weight);
    }

    public function weight($decimals = null, $decimalPoint = null, $thousandSeperator = null)
    {
        return $this->numberFormat($this->weightFloat(), $decimals, $decimalPoint, $thousandSeperator);
    }

    public function search(Closure $search)
    {
        return $this->getContent()->filter($search);
    }

    public function associate($rowId, $model)
    {
        if (is_string($model) && !class_exists($model)) {
            throw new UnknownModelException("The supplied model {$model} does not exist.");
        }

        $cartItem = $this->get($rowId);

        $cartItem->associate($model);

        $this->putItem($cartItem);
    }

    public function setTax($rowId, $taxRate)
    {
        $cartItem = $this->get($rowId);

        $cartItem->setTaxRate($taxRate);

        $this->putItem($cartItem);
    }

    public function setGlobalTax($taxRate)
    {
        $this->taxRate = $taxRate;

        foreach ($this->getContent() as $item) {
            $item->setTaxRate($this->taxRate);
        }
    }

    public function setDiscount($rowId, $discount)
    {
        $cartItem = $this->get($rowId);

        $cartItem->setDiscountRate($discount);

        $this->putItem($cartItem);
    }

    public function setGlobalDiscount($discount)
    {
        $this->discount = $discount;

        foreach ($this->getContent() as $item) {
            $item->setDiscountRate($this->discount);
        }
    }

    public function store($identifier)
    {
        $content = $this->getContent();

        if ($identifier instanceof InstanceIdentifier) {
            $identifier = $identifier->getInstanceIdentifier();
        }

        $instance = $this->currentInstance();
        $connection = $this->getConnection();

        if ($this->storedCartQuery($connection, $instance, $identifier)->exists()) {
            throw new CartAlreadyStoredException("A cart with identifier {$identifier} was already stored.");
        }

        $connection->table($this->getTableName())->insert([
            'identifier' => $identifier,
            'instance'   => $instance,
            'content'    => $this->serializeContent($connection, $content),
            'created_at' => $this->createdAt ?: Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $this->events->dispatch('cart.stored');
    }

    public function restore($identifier)
    {
        if ($identifier instanceof InstanceIdentifier) {
            $identifier = $identifier->getInstanceIdentifier();
        }

        $currentInstance = $this->currentInstance();
        $connection = $this->getConnection();

        $stored = $this->storedCartQuery($connection, $currentInstance, $identifier)->first();

        if ($stored === null) {
            return;
        }

        $storedContent = $this->unserializeContent($connection, data_get($stored, 'content'));

        $this->instance(data_get($stored, 'instance'));

        $content = $this->getContent();

        foreach ($storedContent as $cartItem) {
            $content->put($cartItem->rowId, $cartItem);
        }

        $this->events->dispatch('cart.restored');

        $this->session->put($this->instance, $content);

        $this->instance($currentInstance);

        $this->createdAt = Carbon::parse(data_get($stored, 'created_at'));
        $this->updatedAt = Carbon::parse(data_get($stored, 'updated_at'));

        $this->storedCartQuery($connection, $currentInstance, $identifier)->delete();
    }

    public function erase($identifier)
    {
        if ($identifier instanceof InstanceIdentifier) {
            $identifier = $identifier->getInstanceIdentifier();
        }

        $deleted = $this->storedCartQuery($this->getConnection(), $this->currentInstance(), $identifier)->delete();

        if ($deleted > 0) {
            $this->events->dispatch('cart.erased');
        }
    }

    public function merge($identifier, $keepDiscount = false, $keepTax = false, $dispatchAdd = true, $instance = self::DEFAULT_INSTANCE)
    {
        $connection = $this->getConnection();

        $stored = $this->storedCartQuery($connection, $instance, $identifier)->first();

        if ($stored === null) {
            return false;
        }

        $storedContent = $this->unserializeContent($connection, $stored->content);

        foreach ($storedContent as $cartItem) {
            $this->addCartItem($cartItem, $keepDiscount, $keepTax, $dispatchAdd);
        }

        $this->events->dispatch('cart.merged');

        return true;
    }

    public function __get($attribute)
    {
        return match ($attribute) {
            'total'    => $this->total(),
            'tax'      => $this->tax(),
            'subtotal' => $this->subtotal(),
            default    => null,
        };
    }

    protected function getContent()
    {
        return $this->session->get($this->instance) ?? new Collection();
    }

    private function createCartItem($id, $name, $qty, $price, $weight, array $options): CartItem
    {
        if ($id instanceof Buyable) {
            $cartItem = CartItem::fromBuyable($id, $qty ?: []);
            $cartItem->setQuantity($name ?: 1);
            $cartItem->associate($id);
        } elseif (is_array($id)) {
            $cartItem = CartItem::fromArray($id);
            $cartItem->setQuantity($id['qty']);
        } else {
            $cartItem = CartItem::fromAttributes($id, $name, $price, $weight, $options);
            $cartItem->setQuantity($qty);
        }

        $cartItem->setInstance($this->currentInstance());

        return $cartItem;
    }

    private function isMulti($item): bool
    {
        if (!is_array($item)) {
            return false;
        }

        $first = head($item);

        return is_array($first) || $first instanceof Buyable;
    }

    private function putItem(CartItem $cartItem): void
    {
        $content = $this->getContent();

        $content->put($cartItem->rowId, $cartItem);

        $this->session->put($this->instance, $content);
    }

    private function sumItems(Closure $value)
    {
        $total = 0;

        foreach ($this->getContent() as $cartItem) {
            $total += $value($cartItem);
        }

        return $total;
    }

    private function storedCartQuery(Connection $connection, $instance, $identifier)
    {
        return $connection->table($this->getTableName())
            ->where(['identifier' => $identifier, 'instance' => $instance]);
    }

    private function serializeContent(Connection $connection, $content): string
    {
        $serialized = serialize($content);

        return $connection->getDriverName() === 'pgsql' ? base64_encode($serialized) : $serialized;
    }

    private function unserializeContent(Connection $connection, $content)
    {
        if ($connection->getDriverName() === 'pgsql') {
            $content = base64_decode($content);
        }

        return unserialize($content);
    }

    private function getConnection(): Connection
    {
        return app(DatabaseManager::class)->connection($this->getConnectionName());
    }

    private function getTableName()
    {
        return config('cart.database.table', 'shoppingcart');
    }

    private function getConnectionName()
    {
        return config('cart.database.connection') ?? config('database.default');
    }

    private function numberFormat($value, $decimals, $decimalPoint, $thousandSeperator): string
    {
        $decimals ??= config('cart.format.decimals', 2);
        $decimalPoint ??= config('cart.format.decimal_point', '.');
        $thousandSeperator ??= config('cart.format.thousand_separator', ',');

        return number_format($value, $decimals, $decimalPoint, $thousandSeperator);
    }

    public function createdAt()
    {
        return $this->createdAt;
    }

    public function updatedAt()
    {
        return $this->updatedAt;
    }
}
