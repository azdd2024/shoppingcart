<?php

namespace Gloudemans\Tests\Shoppingcart;

use Gloudemans\Shoppingcart\Calculation\DefaultCalculator;
use Gloudemans\Shoppingcart\Calculation\GrossPrice;
use Gloudemans\Shoppingcart\Cart;
use Gloudemans\Shoppingcart\CartItem;
use Gloudemans\Shoppingcart\CartItemOptions;
use Gloudemans\Shoppingcart\Exceptions\InvalidCalculatorException;
use Gloudemans\Shoppingcart\Exceptions\InvalidRowIDException;
use Gloudemans\Shoppingcart\Facades\Cart as CartFacade;
use Gloudemans\Shoppingcart\ShoppingcartServiceProvider;
use Gloudemans\Tests\Shoppingcart\Fixtures\BuyableProduct;
use Gloudemans\Tests\Shoppingcart\Fixtures\Identifiable;
use Gloudemans\Tests\Shoppingcart\Fixtures\ProductModel;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mockery;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionException;
use ReflectionMethod;
use stdClass;

class CompatibilityTest extends TestCase
{
    use CartAssertions;

    private const LEGACY_PAYLOAD = 'TzoyOToiSWxsdW1pbmF0ZVxTdXBwb3J0XENvbGxlY3Rpb24iOjI6e3M6ODoiACoAaXRlbXMiO2E6Mjp7czozMjoiMDdkNWRhNTU1MDQ5NGM2MmRhZjk5OTNjZjk1NDMwM2YiO086MzI6Ikdsb3VkZW1hbnNcU2hvcHBpbmdjYXJ0XENhcnRJdGVtIjoxMTp7czo1OiJyb3dJZCI7czozMjoiMDdkNWRhNTU1MDQ5NGM2MmRhZjk5OTNjZjk1NDMwM2YiO3M6MjoiaWQiO2k6MTtzOjM6InF0eSI7aTozO3M6NDoibmFtZSI7czoxMToiTGVnYWN5IGl0ZW0iO3M6NToicHJpY2UiO2Q6MTAuNTtzOjY6IndlaWdodCI7ZDoyO3M6Nzoib3B0aW9ucyI7TzozOToiR2xvdWRlbWFuc1xTaG9wcGluZ2NhcnRcQ2FydEl0ZW1PcHRpb25zIjoyOntzOjg6IgAqAGl0ZW1zIjthOjI6e3M6NDoic2l6ZSI7czoyOiJYTCI7czo1OiJjb2xvciI7czozOiJyZWQiO31zOjI4OiIAKgBlc2NhcGVXaGVuQ2FzdGluZ1RvU3RyaW5nIjtiOjA7fXM6NzoidGF4UmF0ZSI7aToyMTtzOjQ5OiIAR2xvdWRlbWFuc1xTaG9wcGluZ2NhcnRcQ2FydEl0ZW0AYXNzb2NpYXRlZE1vZGVsIjtzOjUxOiJHbG91ZGVtYW5zXFRlc3RzXFNob3BwaW5nY2FydFxGaXh0dXJlc1xQcm9kdWN0TW9kZWwiO3M6NDY6IgBHbG91ZGVtYW5zXFNob3BwaW5nY2FydFxDYXJ0SXRlbQBkaXNjb3VudFJhdGUiO2k6MTA7czo4OiJpbnN0YW5jZSI7czo3OiJkZWZhdWx0Ijt9czozMjoiZGQ4Yzg5ZGZlNzI4ZTM4OWEzNzA2NDc1MTVkODdlNWYiO086MzI6Ikdsb3VkZW1hbnNcU2hvcHBpbmdjYXJ0XENhcnRJdGVtIjoxMTp7czo1OiJyb3dJZCI7czozMjoiZGQ4Yzg5ZGZlNzI4ZTM4OWEzNzA2NDc1MTVkODdlNWYiO3M6MjoiaWQiO3M6MzoiYWJjIjtzOjM6InF0eSI7ZDoxLjU7czo0OiJuYW1lIjtzOjY6IlNlY29uZCI7czo1OiJwcmljZSI7ZDo0LjI1O3M6Njoid2VpZ2h0IjtkOjA7czo3OiJvcHRpb25zIjtPOjM5OiJHbG91ZGVtYW5zXFNob3BwaW5nY2FydFxDYXJ0SXRlbU9wdGlvbnMiOjI6e3M6ODoiACoAaXRlbXMiO2E6MDp7fXM6Mjg6IgAqAGVzY2FwZVdoZW5DYXN0aW5nVG9TdHJpbmciO2I6MDt9czo3OiJ0YXhSYXRlIjtpOjA7czo0OToiAEdsb3VkZW1hbnNcU2hvcHBpbmdjYXJ0XENhcnRJdGVtAGFzc29jaWF0ZWRNb2RlbCI7TjtzOjQ2OiIAR2xvdWRlbWFuc1xTaG9wcGluZ2NhcnRcQ2FydEl0ZW0AZGlzY291bnRSYXRlIjtpOjA7czo4OiJpbnN0YW5jZSI7czo3OiJkZWZhdWx0Ijt9fXM6Mjg6IgAqAGVzY2FwZVdoZW5DYXN0aW5nVG9TdHJpbmciO2I6MDt9';

    protected function getPackageProviders($app)
    {
        return [ShoppingcartServiceProvider::class];
    }

    protected function getPackageAliases($app)
    {
        return ['Cart' => CartFacade::class];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('cart.database.connection', 'testing');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->afterResolving('migrator', function ($migrator) {
            $migrator->path(realpath(__DIR__.'/../src/Database/migrations'));
        });
    }

    #[Test]
    public function the_service_container_binds_a_new_cart_on_every_resolution()
    {
        $first = $this->app->make('cart');
        $second = $this->app->make('cart');

        $this->assertInstanceOf(Cart::class, $first);
        $this->assertInstanceOf(Cart::class, $second);
        $this->assertNotSame($first, $second);
    }

    #[Test]
    public function the_facade_resolves_the_cart_and_proxies_calls()
    {
        $this->assertInstanceOf(Cart::class, CartFacade::getFacadeRoot());

        CartFacade::add(1, 'Facade item', 2, 5.00);

        $this->assertSame(2, CartFacade::count());
        $this->assertSame('10.00', CartFacade::subtotal());
    }

    #[Test]
    public function the_package_configuration_is_merged_with_its_defaults()
    {
        $config = $this->app['config'];

        $this->assertSame(DefaultCalculator::class, $config->get('cart.calculator'));
        $this->assertSame(21, $config->get('cart.tax'));
        $this->assertSame('shoppingcart', $config->get('cart.database.table'));
        $this->assertFalse($config->get('cart.destroy_on_logout'));
        $this->assertSame(['decimals' => 2, 'decimal_point' => '.', 'thousand_separator' => ','], $config->get('cart.format'));
    }

    #[Test]
    public function the_package_publishes_its_config_and_migrations()
    {
        $config = ShoppingcartServiceProvider::pathsToPublish(ShoppingcartServiceProvider::class, 'config');
        $migrations = ShoppingcartServiceProvider::pathsToPublish(ShoppingcartServiceProvider::class, 'migrations');

        $this->assertCount(1, $config);
        $this->assertStringEndsWith('cart.php', array_values($config)[0]);
        $this->assertCount(1, $migrations);
        $this->assertStringEndsWith('migrations', array_values($migrations)[0]);
    }

    #[Test]
    public function it_does_not_touch_the_session_on_logout_when_the_option_is_disabled()
    {
        $this->app['config']->set('cart.destroy_on_logout', false);

        $this->app->instance(SessionManager::class, Mockery::mock(SessionManager::class, function ($mock) {
            $mock->shouldNotReceive('forget');
        }));

        Auth::guard('web')->logout();

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function it_stores_the_content_in_the_session_under_the_instance_key()
    {
        $cart = $this->getCart();

        $cart->add(1, 'Item', 1, 10.00);
        $cart->instance('wishlist')->add(2, 'Other', 1, 5.00);

        $session = $this->app->make('session');

        $this->assertInstanceOf(Collection::class, $session->get('cart.default'));
        $this->assertInstanceOf(Collection::class, $session->get('cart.wishlist'));
        $this->assertSame(['default', 'wishlist'], array_keys($session->get('cart')));
        $this->assertSame($session->get('cart.wishlist'), $cart->content());
    }

    #[Test]
    public function instances_are_isolated_from_each_other()
    {
        $cart = $this->getCart();

        $cart->instance('a')->add(1, 'Item', 3, 10.00);
        $cart->instance('b')->add(1, 'Item', 1, 10.00);

        $this->assertSame(3, $cart->instance('a')->count());
        $this->assertSame(1, $cart->instance('b')->count());
        $this->assertSame('b', $cart->currentInstance());

        $cart->instance('a')->destroy();

        $this->assertSame(0, $cart->instance('a')->count());
        $this->assertSame(1, $cart->instance('b')->count());
    }

    #[Test]
    public function an_empty_or_null_instance_falls_back_to_the_default_instance()
    {
        $cart = $this->getCart();

        $this->assertSame(Cart::DEFAULT_INSTANCE, $cart->instance(null)->currentInstance());
        $this->assertSame(Cart::DEFAULT_INSTANCE, $cart->instance('')->currentInstance());
        $this->assertSame($cart, $cart->instance('x'));
    }

    #[Test]
    public function an_instance_identifier_sets_the_global_discount()
    {
        $cart = $this->getCart();

        $cart->instance(new Identifiable('vip', 25));
        $item = $cart->add(1, 'Item', 1, 100.00);

        $this->assertSame('vip', $cart->currentInstance());
        $this->assertSame(25, $item->getDiscountRate());
        $this->assertEquals(25.00, $item->discountTotal);
    }

    #[Test]
    public function the_row_id_does_not_depend_on_the_order_of_the_options()
    {
        $first = new CartItem(1, 'Item', 10.00, 0, ['size' => 'XL', 'color' => 'red']);
        $second = new CartItem(1, 'Item', 10.00, 0, ['color' => 'red', 'size' => 'XL']);
        $third = new CartItem('1', 'Item', 10.00, 0, ['color' => 'red', 'size' => 'XL']);
        $other = new CartItem(1, 'Item', 10.00, 0, ['color' => 'blue', 'size' => 'XL']);

        $this->assertSame('07d5da5550494c62daf9993cf954303f', $first->rowId);
        $this->assertSame($first->rowId, $second->rowId);
        $this->assertSame($first->rowId, $third->rowId);
        $this->assertNotSame($first->rowId, $other->rowId);
        $this->assertSame('027c91341fd5cf4d2579b49c4b6a90da', (new CartItem(1, 'Item', 10.00))->rowId);
    }

    #[Test]
    public function the_cart_item_exposes_the_same_serialized_properties()
    {
        $item = new CartItem(1, 'Item', 10.00);

        $this->assertSame([
            'rowId',
            'id',
            'qty',
            'name',
            'price',
            'weight',
            'options',
            'taxRate',
            "\0".CartItem::class."\0associatedModel",
            "\0".CartItem::class."\0discountRate",
            'instance',
        ], array_keys((array) $item));
    }

    #[Test]
    public function it_restores_a_cart_that_was_stored_by_the_previous_version()
    {
        $this->artisan('migrate', ['--database' => 'testing']);

        DB::connection('testing')->table('shoppingcart')->insert([
            'identifier' => 'legacy',
            'instance'   => 'default',
            'content'    => base64_decode(self::LEGACY_PAYLOAD),
            'created_at' => '2024-01-01 10:00:00',
            'updated_at' => '2024-01-02 11:00:00',
        ]);

        $cart = $this->getCart();
        $cart->restore('legacy');

        $this->assertRowsInCart(2, $cart);
        $this->assertSame(['07d5da5550494c62daf9993cf954303f', 'dd8c89dfe728e389a370647515d87e5f'], $cart->content()->keys()->all());

        $first = $cart->get('07d5da5550494c62daf9993cf954303f');
        $this->assertSame('Legacy item', $first->name);
        $this->assertSame(3, $first->qty);
        $this->assertSame(21, $first->taxRate);
        $this->assertSame(10, $first->getDiscountRate());
        $this->assertSame('XL', $first->options->size);
        $this->assertSame(ProductModel::class, $first->modelFQCN);
        $this->assertInstanceOf(CartItemOptions::class, $first->options);

        $this->assertSame('31.50', $first->subtotal());
        $this->assertSame('3.15', $first->discountTotal());
        $this->assertSame('9.45', $first->priceTarget());
        $this->assertSame('1.98', $first->tax());
        $this->assertSame('6.62', $first->taxTotal());
        $this->assertSame('38.12', $first->total());

        $second = $cart->get('dd8c89dfe728e389a370647515d87e5f');
        $this->assertSame(1.5, $second->qty);
        $this->assertSame('6.38', $second->total());

        $this->assertSame('44.50', $cart->total());
        $this->assertSame('2024-01-01 10:00:00', $cart->createdAt()->format('Y-m-d H:i:s'));
        $this->assertSame('2024-01-02 11:00:00', $cart->updatedAt()->format('Y-m-d H:i:s'));
        $this->assertDatabaseMissing('shoppingcart', ['identifier' => 'legacy']);
    }

    #[Test]
    public function stored_content_is_a_plain_serialized_collection_on_non_pgsql_drivers()
    {
        $this->artisan('migrate', ['--database' => 'testing']);

        $cart = $this->getCart();
        $cart->add(1, 'Item', 2, 10.00, 0, ['size' => 'L']);
        $cart->store('plain');

        $content = DB::connection('testing')->table('shoppingcart')->where('identifier', 'plain')->value('content');
        $restored = unserialize($content);

        $this->assertInstanceOf(Collection::class, $restored);
        $this->assertEquals($cart->content()->toArray(), $restored->toArray());
    }

    #[Test]
    public function restore_keeps_the_items_already_in_the_current_instance()
    {
        $this->artisan('migrate', ['--database' => 'testing']);

        $cart = $this->getCart();
        $cart->add(1, 'Stored', 1, 10.00);
        $cart->store('mix');
        $cart->destroy();

        $cart->add(2, 'Current', 1, 5.00);
        $cart->restore('mix');

        $this->assertRowsInCart(2, $cart);
        $this->assertSame(['Current', 'Stored'], $cart->content()->pluck('name')->all());
    }

    #[Test]
    public function erase_and_restore_do_not_dispatch_events_for_unknown_identifiers()
    {
        $this->artisan('migrate', ['--database' => 'testing']);

        Event::fake();

        $cart = $this->getCart();
        $cart->erase('missing');
        $cart->restore('missing');

        Event::assertNotDispatched('cart.erased');
        Event::assertNotDispatched('cart.restored');
        $this->assertNull($cart->createdAt());
        $this->assertNull($cart->updatedAt());
    }

    #[Test]
    public function erase_only_removes_the_current_instance()
    {
        $this->artisan('migrate', ['--database' => 'testing']);

        $cart = $this->getCart();
        $cart->add(1, 'Item', 1, 10.00);
        $cart->store('user');
        $cart->instance('wishlist')->add(1, 'Item', 1, 10.00);
        $cart->store('user');

        $cart->instance('wishlist')->erase('user');

        $this->assertDatabaseHas('shoppingcart', ['identifier' => 'user', 'instance' => 'default']);
        $this->assertDatabaseMissing('shoppingcart', ['identifier' => 'user', 'instance' => 'wishlist']);
    }

    #[Test]
    public function merge_reads_the_requested_instance_and_keeps_the_stored_copy()
    {
        $this->artisan('migrate', ['--database' => 'testing']);

        $cart = $this->getCart();
        $cart->instance('wishlist')->add(1, 'Item', 2, 10.00);
        $cart->store('user');

        $target = $this->getCart();

        $this->assertFalse($target->merge('user'));
        $this->assertTrue($target->merge('user', false, false, true, 'wishlist'));
        $this->assertSame(2, $target->count());
        $this->assertSame(Cart::DEFAULT_INSTANCE, $target->currentInstance());
        $this->assertDatabaseHas('shoppingcart', ['identifier' => 'user', 'instance' => 'wishlist']);
    }

    #[Test]
    public function store_accepts_an_instance_identifier()
    {
        $this->artisan('migrate', ['--database' => 'testing']);

        $cart = $this->getCart();
        $cart->add(1, 'Item', 1, 10.00);
        $cart->store(new Identifiable('user-7'));

        $this->assertDatabaseHas('shoppingcart', ['identifier' => 'user-7', 'instance' => 'default']);
    }

    #[Test]
    public function events_are_dispatched_in_the_expected_order()
    {
        $dispatched = [];

        foreach (['cart.adding', 'cart.added', 'cart.updating', 'cart.updated', 'cart.removing', 'cart.removed'] as $name) {
            $this->app['events']->listen($name, function ($item) use ($name, &$dispatched) {
                $dispatched[] = [$name, $item->rowId];
            });
        }

        $cart = $this->getCart();
        $item = $cart->add(1, 'Item', 1, 10.00);
        $cart->update($item->rowId, 3);
        $cart->remove($item->rowId);

        $this->assertSame([
            ['cart.adding', $item->rowId],
            ['cart.added', $item->rowId],
            ['cart.updating', $item->rowId],
            ['cart.updated', $item->rowId],
            ['cart.removing', $item->rowId],
            ['cart.removed', $item->rowId],
        ], $dispatched);
    }

    #[Test]
    public function update_returns_the_item_or_null_when_it_was_removed()
    {
        $cart = $this->getCart();
        $item = $cart->add(1, 'Item', 1, 10.00);

        $this->assertSame($item, $cart->update($item->rowId, 2.5));
        $this->assertSame(2.5, $cart->count());
        $this->assertNull($cart->update($item->rowId, 0));
        $this->assertSame(0, $cart->countItems());
    }

    #[Test]
    public function update_from_an_array_preserves_the_position_and_merges_into_existing_rows()
    {
        $cart = $this->getCart();
        $cart->add(1, 'A', 1, 10.00, 0, ['color' => 'red']);
        $cart->add(2, 'B', 1, 10.00);
        $cart->add(3, 'C', 1, 10.00);

        $rowIdB = $cart->content()->values()[1]->rowId;
        $updated = $cart->update($rowIdB, ['id' => 9, 'qty' => 4]);

        $this->assertSame(['A', 'B', 'C'], $cart->content()->pluck('name')->all());
        $this->assertSame($updated->rowId, $cart->content()->keys()[1]);
        $this->assertSame(9, $updated->id);
        $this->assertSame(6, $cart->count());
    }

    #[Test]
    public function getting_or_removing_an_unknown_row_throws()
    {
        $cart = $this->getCart();

        try {
            $cart->get('nope');
            $this->fail('Expected exception');
        } catch (InvalidRowIDException $e) {
            $this->assertSame('The cart does not contain rowId nope.', $e->getMessage());
        }

        $this->expectException(InvalidRowIDException::class);
        $cart->remove('nope');
    }

    #[Test]
    public function an_empty_cart_reports_zero_values()
    {
        $cart = $this->getCart();

        $this->assertSame(0, $cart->count());
        $this->assertSame(0, $cart->countItems());
        $this->assertSame(0, $cart->totalFloat());
        $this->assertSame(0, $cart->subtotalFloat());
        $this->assertSame(0, $cart->taxFloat());
        $this->assertSame(0, $cart->discountFloat());
        $this->assertSame(0, $cart->initialFloat());
        $this->assertSame(0, $cart->priceTotalFloat());
        $this->assertSame(0, $cart->weightFloat());
        $this->assertSame('0.00', $cart->total());
        $this->assertSame('0.00', $cart->total);
        $this->assertSame('0.00', $cart->tax);
        $this->assertSame('0.00', $cart->subtotal);
        $this->assertNull($cart->unknown);

        $cart->setGlobalTax(10);
        $cart->setGlobalDiscount(10);

        $this->assertSame(0, $cart->count());
    }

    #[Test]
    public function global_tax_and_discount_apply_to_existing_and_future_items()
    {
        $cart = $this->getCart();
        $first = $cart->add(1, 'Item', 1, 10.00);

        $cart->setGlobalTax(10);
        $cart->setGlobalDiscount(20);

        $second = $cart->add(2, 'Item', 1, 10.00);

        $this->assertSame(10, $first->taxRate);
        $this->assertSame(10, $second->taxRate);
        $this->assertSame(20, $first->getDiscountRate());
        $this->assertSame(20, $second->getDiscountRate());
    }

    #[Test]
    public function add_cart_item_can_keep_the_item_rates()
    {
        $cart = $this->getCart();

        $item = (new CartItem(1, 'Item', 10.00))->setTaxRate(5)->setDiscountRate(15);
        $item->setQuantity(1);

        $cart->addCartItem($item, true, true, false);

        $this->assertSame(5, $item->taxRate);
        $this->assertSame(15, $item->getDiscountRate());
    }

    #[Test]
    public function totals_are_formatted_with_explicit_arguments_and_config_defaults()
    {
        $cart = $this->getCart();
        $cart->add(1, 'Item', 3, 1234.5, 1.25);

        $this->assertSame('3,703.50', $cart->subtotal());
        $this->assertSame('3.703,500', $cart->subtotal(3, ',', '.'));
        $this->assertSame('3704', $cart->subtotal(0, '.', ''));
        $this->assertSame('3.75', $cart->weight());
        $this->assertSame(3.75, $cart->weightFloat());
    }

    #[Test]
    public function the_cart_supports_macros()
    {
        Cart::macro('doubleCount', function () {
            return $this->count() * 2;
        });

        $cart = $this->getCart();
        $cart->add(1, 'Item', 2, 10.00);

        $this->assertSame(4, $cart->doubleCount());
    }

    #[Test]
    public function search_returns_matching_items_keyed_by_row_id()
    {
        $cart = $this->getCart();
        $a = $cart->add(1, 'A', 1, 10.00);
        $cart->add(2, 'B', 1, 20.00);

        $result = $cart->search(fn ($item, $rowId) => $item->price < 15);

        $this->assertSame([$a->rowId], $result->keys()->all());
    }

    #[Test]
    public function adding_multiple_items_returns_them_in_order()
    {
        $cart = $this->getCart();

        $items = $cart->add([
            ['id' => 1, 'name' => 'A', 'qty' => 1, 'price' => 10.00, 'weight' => 0],
            ['id' => 2, 'name' => 'B', 'qty' => 2, 'price' => 5.00, 'weight' => 0, 'options' => ['x' => 1]],
        ]);

        $this->assertSame(['A', 'B'], array_map(fn ($item) => $item->name, $items));
        $this->assertSame(1, $items[1]->options->x);
        $this->assertSame(3, $cart->count());
    }

    #[Test]
    public function the_constructor_casts_price_and_weight_to_float()
    {
        $item = new CartItem('sku', 'Item', '10.5', '2');

        $this->assertSame(10.5, $item->price);
        $this->assertSame(2.0, $item->weight);
        $this->assertInstanceOf(CartItemOptions::class, $item->options);
    }

    public static function invalidConstructorArguments(): array
    {
        return [
            'empty id'       => [0, 'Item', 10, 0, 'Please supply a valid identifier.'],
            'empty name'     => [1, '', 10, 0, 'Please supply a valid name.'],
            'null price'     => [1, 'Item', null, 0, 'Please supply a valid price.'],
            'string price'   => [1, 'Item', 'abc', 0, 'Please supply a valid price.'],
            'null weight'    => [1, 'Item', 10, null, 'Please supply a valid weight.'],
            'string weight'  => [1, 'Item', 10, 'heavy', 'Please supply a valid weight.'],
        ];
    }

    #[Test]
    #[DataProvider('invalidConstructorArguments')]
    public function the_constructor_rejects_invalid_arguments($id, $name, $price, $weight, $message)
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new CartItem($id, $name, $price, $weight);
    }

    public static function invalidQuantities(): array
    {
        return [[0], ['0'], [null], [''], ['abc'], [false]];
    }

    #[Test]
    #[DataProvider('invalidQuantities')]
    public function set_quantity_rejects_invalid_values($qty)
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Please supply a valid quantity.');

        (new CartItem(1, 'Item', 10))->setQuantity($qty);
    }

    #[Test]
    public function set_quantity_keeps_the_given_value_type()
    {
        $item = new CartItem(1, 'Item', 10);

        $item->setQuantity('3');
        $this->assertSame('3', $item->qty);

        $item->setQuantity(-2);
        $this->assertSame(-2, $item->qty);
    }

    #[Test]
    public function model_attributes_fall_back_to_the_total_weight_when_no_model_is_associated()
    {
        $item = new CartItem(1, 'Item', 10, 1.333);
        $item->setQuantity(3);

        $this->assertSame(4.0, $item->model);
        $this->assertSame(4.0, $item->modelFQCN);
        $this->assertSame(4.0, $item->weightTotal);

        $item->associate(ProductModel::class);

        $this->assertSame(ProductModel::class, $item->modelFQCN);
        $this->assertInstanceOf(ProductModel::class, $item->model);
        $this->assertSame(4.0, $item->weightTotal);
    }

    #[Test]
    public function associating_an_object_stores_its_class_name()
    {
        $item = new CartItem(1, 'Item', 10);

        $this->assertSame($item, $item->associate(new ProductModel()));
        $this->assertSame(ProductModel::class, $item->modelFQCN);
    }

    #[Test]
    public function properties_and_unknown_attributes_are_readable()
    {
        $item = new CartItem(1, 'Item', 10);
        $item->setQuantity(1);

        $this->assertSame(0, $item->discountRate);
        $this->assertNull($item->associatedModel);
        $this->assertNull($item->somethingElse);
    }

    #[Test]
    public function the_calculator_can_be_switched_at_runtime()
    {
        $item = new CartItem(1, 'Item', 122);
        $item->setQuantity(1);
        $item->setTaxRate(22);

        $this->assertEquals(122.0, $item->subtotal);

        config(['cart.calculator' => GrossPrice::class]);
        $this->assertEquals(100.0, $item->subtotal);

        config(['cart.calculator' => DefaultCalculator::class]);
        $this->assertEquals(122.0, $item->subtotal);
    }

    #[Test]
    public function an_invalid_calculator_is_rejected_every_time()
    {
        config(['cart.calculator' => stdClass::class]);

        $item = new CartItem(1, 'Item', 10);
        $item->setQuantity(1);

        foreach ([1, 2] as $attempt) {
            try {
                $item->total;
                $this->fail('Expected exception');
            } catch (InvalidCalculatorException $e) {
                $this->assertSame('The configured Calculator seems to be invalid. Calculators have to implement the Calculator Contract.', $e->getMessage());
            }
        }
    }

    #[Test]
    public function a_missing_calculator_class_throws_a_reflection_exception()
    {
        config(['cart.calculator' => 'App\\DoesNotExist\\Calculator']);

        $item = new CartItem(1, 'Item', 10);
        $item->setQuantity(1);

        $this->expectException(ReflectionException::class);

        $item->total;
    }

    #[Test]
    public function the_gross_price_calculator_values_are_unchanged()
    {
        config(['cart.calculator' => GrossPrice::class]);

        $item = new CartItem(1, 'Item', 122);
        $item->setQuantity(2);
        $item->setTaxRate(22);
        $item->setDiscountRate(10);

        $this->assertEquals(100.0, $item->priceNet);
        $this->assertEquals(10.0, $item->discount);
        $this->assertEquals(20.0, $item->discountTotal);
        $this->assertEquals(200.0, $item->priceTotal);
        $this->assertEquals(180.0, $item->subtotal);
        $this->assertEquals(90.0, $item->priceTarget);
        $this->assertEquals(19.8, $item->tax);
        $this->assertEquals(109.8, $item->priceTax);
        $this->assertEquals(39.6, $item->taxTotal);
        $this->assertEquals(219.6, $item->total);
        $this->assertNull($item->unknownValue);
    }

    #[Test]
    public function the_default_calculator_does_not_subtract_the_discount_from_the_subtotal()
    {
        $item = new CartItem(1, 'Item', 100);
        $item->setQuantity(2);
        $item->setTaxRate(10);
        $item->setDiscountRate(10);

        $this->assertEquals(10.0, $item->discount);
        $this->assertEquals(20.0, $item->discountTotal);
        $this->assertEquals(200.0, $item->priceTotal);
        $this->assertEquals(200.0, $item->subtotal);
        $this->assertEquals(90.0, $item->priceTarget);
        $this->assertEquals(9.0, $item->tax);
        $this->assertEquals(99.0, $item->priceTax);
        $this->assertEquals(20.0, $item->taxTotal);
        $this->assertEquals(220.0, $item->total);
    }

    #[Test]
    public function public_method_signatures_are_unchanged()
    {
        $expected = [
            Cart::class => [
                'instance'          => ['instance=null'],
                'add'               => ['id', 'name=null', 'qty=null', 'price=null', 'weight=0', 'options=[]'],
                'addCartItem'       => ['item', 'keepDiscount=false', 'keepTax=false', 'dispatchEvent=true'],
                'update'            => ['rowId', 'qty'],
                'remove'            => ['rowId'],
                'get'               => ['rowId'],
                'total'             => ['decimals=null', 'decimalPoint=null', 'thousandSeperator=null'],
                'search'            => ['search'],
                'associate'         => ['rowId', 'model'],
                'setTax'            => ['rowId', 'taxRate'],
                'setGlobalTax'      => ['taxRate'],
                'setDiscount'       => ['rowId', 'discount'],
                'setGlobalDiscount' => ['discount'],
                'store'             => ['identifier'],
                'restore'           => ['identifier'],
                'erase'             => ['identifier'],
                'merge'             => ['identifier', 'keepDiscount=false', 'keepTax=false', 'dispatchAdd=true', "instance='default'"],
            ],
            CartItem::class => [
                '__construct'       => ['id', 'name', 'price', 'weight=0', 'options=[]'],
                'setQuantity'       => ['qty'],
                'updateFromBuyable' => ['item'],
                'updateFromArray'   => ['attributes'],
                'associate'         => ['model'],
                'setTaxRate'        => ['taxRate'],
                'setDiscountRate'   => ['discountRate'],
                'setInstance'       => ['instance'],
                'fromBuyable'       => ['item', 'options=[]'],
                'fromArray'         => ['attributes'],
                'fromAttributes'    => ['id', 'name', 'price', 'weight', 'options=[]'],
                'toJson'            => ['options=0'],
                'getDiscountRate'   => [],
            ],
        ];

        foreach ($expected as $class => $methods) {
            foreach ($methods as $method => $parameters) {
                $reflection = new ReflectionMethod($class, $method);

                $actual = array_map(function ($parameter) {
                    if (!$parameter->isDefaultValueAvailable()) {
                        return $parameter->getName();
                    }

                    $default = $parameter->getDefaultValue();
                    $export = match (true) {
                        $default === null => 'null',
                        $default === [] => '[]',
                        default => var_export($default, true),
                    };

                    return $parameter->getName().'='.$export;
                }, $reflection->getParameters());

                $this->assertSame($parameters, $actual, "{$class}::{$method}");
                $this->assertTrue($reflection->isPublic(), "{$class}::{$method}");
                $this->assertFalse($reflection->hasReturnType() && $method !== '__construct', "{$class}::{$method} return type");
            }
        }
    }

    #[Test]
    public function add_with_a_buyable_uses_the_qty_argument_as_options()
    {
        $cart = $this->getCart();

        $item = $cart->add(new BuyableProduct(['id' => 5, 'name' => 'Model', 'price' => 7.5, 'weight' => 2]), 4, ['size' => 'S']);

        $this->assertSame(4, $item->qty);
        $this->assertSame('S', $item->options->size);
        $this->assertSame(BuyableProduct::class, $item->modelFQCN);
        $this->assertSame('default', $item->instance);
        $this->assertSame(2.0, $item->weight);
    }

    private function getCart()
    {
        return new Cart($this->app->make('session'), $this->app->make('events'));
    }
}
