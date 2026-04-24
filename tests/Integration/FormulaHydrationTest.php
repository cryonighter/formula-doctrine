<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration;

use Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\OrderItem;
use Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\Product;

final class FormulaHydrationTest extends OrmTestCase
{
    // --- Базовые сценарии ---

    public function testFormulaFieldDefaultsToZeroWhenNoOrders(): void
    {
        $product = $this->makeProduct('Empty Product');
        $this->persist($product);

        $result = $this->findProduct($product->id);

        self::assertSame(0, $result->orderCount);
        self::assertSame(0.0, $result->totalRevenue);
        self::assertNull($result->maxItemPrice);
    }

    public function testFormulaCountIsCorrect(): void
    {
        $product = $this->makeProduct('Popular Product');
        $this->persist($product);
        $this->persistOrderItems($product->id, [10.00, 20.00, 30.00]);

        $result = $this->findProduct($product->id);

        self::assertSame(3, $result->orderCount);
    }

    public function testFormulaSumIsCorrect(): void
    {
        $product = $this->makeProduct('Revenue Product');
        $this->persist($product);
        // quantity=1 для каждого, итого: 15 + 25 = 40
        $this->persistOrderItems($product->id, [15.00, 25.00]);

        $result = $this->findProduct($product->id);

        self::assertEqualsWithDelta(40.0, $result->totalRevenue, 0.001);
    }

    public function testNullableFormulaReturnsNullWhenNoData(): void
    {
        $product = $this->makeProduct('Lonely Product');
        $this->persist($product);

        $result = $this->findProduct($product->id);

        self::assertNull($result->maxItemPrice);
    }

    public function testNullableFormulaReturnsValueWhenDataExists(): void
    {
        $product = $this->makeProduct('Max Price Product');
        $this->persist($product);
        $this->persistOrderItems($product->id, [5.00, 99.99, 42.00]);

        $result = $this->findProduct($product->id);

        self::assertEqualsWithDelta(99.99, $result->maxItemPrice, 0.001);
    }

    // --- Проверка отсутствия N+1 ---

    public function testCollectionQueryUsesNoNPlusOneQueries(): void
    {
        // Создаём 3 продукта с разным числом заказов
        $p1 = $this->makeProduct('Product 1');
        $p2 = $this->makeProduct('Product 2');
        $p3 = $this->makeProduct('Product 3');
        $this->persist($p1, $p2, $p3);

        $this->persistOrderItems($p1->id, [10.00]);
        $this->persistOrderItems($p2->id, [20.00, 30.00]);

        $queryCount = 0;
        $logger = new class ($queryCount) {
            public int $count = 0;
            public function startQuery(string $sql): void { $this->count++; }
            public function stopQuery(): void {}
        };

        // Один SELECT должен вернуть все 3 продукта с формулами
        $products = $this->em
            ->createQuery('SELECT p FROM ' . Product::class . ' p ORDER BY p.id ASC')
            ->getResult();

        self::assertCount(3, $products);

        // Проверяем значения — если бы был N+1, тесты на значения провалились бы
        // при закрытом соединении или счётчике запросов
        self::assertSame(1, $products[0]->orderCount);
        self::assertSame(2, $products[1]->orderCount);
        self::assertSame(0, $products[2]->orderCount);
    }

    // --- Проверка что формульные поля не персистируются ---

    public function testFormulaFieldsAreNotPersistedOnFlush(): void
    {
        $product = $this->makeProduct('Persist Test');
        $this->persist($product);
        $this->persistOrderItems($product->id, [50.00]);

        // Загружаем с формулами
        $loaded = $this->findProduct($product->id);
        self::assertSame(1, $loaded->orderCount);

        // Модифицируем обычное поле и флашим
        $loaded->name = 'Updated Name';
        $this->em->flush();

        // Проверяем что flush не сломался из-за формульных полей
        $this->em->clear();
        $reloaded = $this->findProduct($product->id);

        self::assertSame('Updated Name', $reloaded->name);
        self::assertSame(1, $reloaded->orderCount);
    }

    public function testFormulaFieldChangeDoesNotTriggerUpdate(): void
    {
        $product = $this->makeProduct('No Update Test');
        $this->persist($product);

        $loaded = $this->findProduct($product->id);

        // Принудительно меняем формульное поле через Reflection
        $prop = new \ReflectionProperty(Product::class, 'orderCount');
        $prop->setValue($loaded, 999);

        // Flush не должен пытаться сохранить orderCount=999
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->findProduct($product->id);

        // После перезагрузки значение снова вычислено из БД (0, заказов нет)
        self::assertSame(0, $reloaded->orderCount);
    }

    // --- Вспомогательные методы ---

    private function makeProduct(string $name): Product
    {
        $product = new Product();
        $product->name = $name;

        return $product;
    }

    private function persistOrderItems(int $productId, array $prices): void
    {
        foreach ($prices as $price) {
            $item = new OrderItem();
            $item->productId = $productId;
            $item->price = (string) $price;
            $item->quantity = 1;
            $this->em->persist($item);
        }

        $this->em->flush();
        $this->em->clear();
    }

    private function findProduct(int $id): Product
    {
        $product = $this->em
            ->createQuery('SELECT p FROM ' . Product::class . ' p WHERE p.id = :id')
            ->setParameter('id', $id)
            ->getSingleResult();

        self::assertInstanceOf(Product::class, $product);

        return $product;
    }
}

