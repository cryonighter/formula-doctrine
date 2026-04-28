<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration;

use Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\OrderItem;
use Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\Product;

final class FormulaHydrationTest extends OrmTestCase
{
    public function testFormulaFieldDefaultsWhenNoOrders(): void
    {
        $product = $this->makeProduct('Empty Product');
        $this->persist($product);

        $result = $this->getProduct($product->id);

        self::assertSame(0, $result->orderCount);
        self::assertSame(0.0, $result->totalRevenue);
        self::assertNull($result->maxItemPrice);
    }

    public function testFormulaCountIsCorrect(): void
    {
        $product = $this->makeProduct('Popular Product');
        $this->persist($product);
        $this->persistOrderItems($product->id, [10.00, 20.00, 30.00]);

        $result = $this->getProduct($product->id);

        self::assertSame(3, $result->orderCount);
    }

    public function testFormulaSumIsCorrect(): void
    {
        $product = $this->makeProduct('Revenue Product');
        $this->persist($product);
        // quantity=1 для каждого, итого: 15 + 25 = 40
        $this->persistOrderItems($product->id, [15.00, 25.00]);

        $result = $this->getProduct($product->id);

        self::assertEqualsWithDelta(40.0, $result->totalRevenue, 0.001);
    }

    public function testNullableFormulaReturnsNullWhenNoData(): void
    {
        $product = $this->makeProduct('Lonely Product');
        $this->persist($product);

        $result = $this->getProduct($product->id);

        self::assertNull($result->maxItemPrice);
    }

    public function testNullableFormulaReturnsValueWhenDataExists(): void
    {
        $product = $this->makeProduct('Max Price Product');
        $this->persist($product);
        $this->persistOrderItems($product->id, [5.00, 99.99, 42.00]);

        $result = $this->getProduct($product->id);

        self::assertEqualsWithDelta(99.99, $result->maxItemPrice, 0.001);
    }

    // --- Механизм загрузки через DQL: Walker + Hydrator (без N+1) ---

    public function testDqlUsesOneQueryWithSubqueries(): void
    {
        // Создаём 3 продукта с разным числом заказов
        $p1 = $this->makeProduct('Product 1');
        $p2 = $this->makeProduct('Product 2');
        $p3 = $this->makeProduct('Product 3');
        $this->persist($p1, $p2, $p3);

        $this->persistOrderItems($p1->id, [10.00]);
        $this->persistOrderItems($p2->id, [20.00, 30.00]);

        $this->queryLogger->reset();

        // Один SELECT должен вернуть все 3 продукта с формулами
        $products = $this->em
            ->createQuery('SELECT p FROM ' . Product::class . ' p ORDER BY p.id ASC')
            ->getResult();

        self::assertCount(3, $products);

        // Ровно 1 запрос — все формулы в одном SELECT
        self::assertCount(1, $this->queryLogger->getQueries());

        // SQL содержит подзапросы формул
        $sql = $this->queryLogger->getQueries()[0];
        self::assertStringContainsString('SELECT COUNT', $sql);
        self::assertStringContainsString('SELECT COALESCE', $sql);

        // Значения корректны
        self::assertSame(1, $products[0]->orderCount);
        self::assertSame(2, $products[1]->orderCount);
        self::assertSame(0, $products[2]->orderCount);
    }

    public function testDqlSingleEntityUsesOneQuery(): void
    {
        $product = $this->makeProduct('Single Product');
        $this->persist($product);
        $this->persistOrderItems($product->id, [50.00]);

        $this->queryLogger->reset();

        $result = $this->getProduct($product->id);

        // Ровно 1 запрос через DQL
        self::assertSame(1, $result->orderCount);
        self::assertCount(1, $this->queryLogger->getQueries());
    }

    // --- Механизм загрузки через find(): PostLoadListener (fallback) ---

    public function testFindUsesTwoQueriesViaPostLoad(): void
    {
        $product = $this->makeProduct('Find Product');
        $this->persist($product);
        $this->persistOrderItems($product->id, [10.00, 20.00]);

        $this->em->clear();
        $this->queryLogger->reset();

        $found = $this->em->find(Product::class, $product->id);

        // Ровно 1 запрос через find()
        self::assertCount(1, $this->queryLogger->getQueries());
        self::assertSame(2, $found->orderCount);
    }

    public function testFindAfterDqlUsesIdentityMapNoExtraQuery(): void
    {
        $product = $this->makeProduct('Identity Map Product');
        $this->persist($product);
        $this->persistOrderItems($product->id, [5.00]);

        // Сначала загружаем через DQL — Walker отрабатывает
        $viaQuery = $this->getProduct($product->id);
        self::assertSame(1, $viaQuery->orderCount);

        $this->queryLogger->reset();

        // find() должен вернуть объект из Identity Map без лишних запросов
        $viaFind = $this->em->find(Product::class, $product->id);

        self::assertSame($viaQuery, $viaFind);

        // 0 запросов — Identity Map, PostLoadListener видит флаг isHydrated
        self::assertCount(0, $this->queryLogger->getQueries());
        self::assertSame(1, $viaFind->orderCount);
    }

    // --- flush не персистирует формульные поля ---

    public function testFormulaFieldsAreNotPersistedOnFlush(): void
    {
        $product = $this->makeProduct('Persist Test');
        $this->persist($product);
        $this->persistOrderItems($product->id, [50.00]);

        // Загружаем с формулами
        $loaded = $this->getProduct($product->id);
        self::assertSame(1, $loaded->orderCount);

        // Модифицируем обычное поле и флашим
        $loaded->name = 'Updated Name';
        $this->em->flush();

        // Проверяем что flush не сломался из-за формульных полей
        $this->em->clear();

        $reloaded = $this->getProduct($product->id);

        self::assertSame('Updated Name', $reloaded->name);
        self::assertSame(1, $reloaded->orderCount);
    }

    public function testFormulaFieldChangeDoesNotTriggerUpdate(): void
    {
        $product = $this->makeProduct('No Update Test');
        $this->persist($product);

        $loaded = $this->getProduct($product->id);

        // Меняем формульное поле
        $loaded->orderCount = 999;

        // Flush не должен пытаться сохранить orderCount=999
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->getProduct($product->id);

        // После перезагрузки значение снова вычислено из БД (0, заказов нет)
        self::assertSame(0, $reloaded->orderCount);
    }

    public function testDqlFormulasWorkOnRepeatedQueryExecution(): void
    {
        $product = $this->makeProduct('RSM Cache Test');
        $this->persist($product);
        $this->persistOrderItems($product->id, [10.00, 20.00]);

        for ($i = 0; $i < 5; $i++) {
            $result = $this->getProduct($product->id);

            self::assertSame(2, $result->orderCount);

            $this->em->clear();
        }
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

        $this->queryLogger->reset();
    }

    private function getProduct(int $id): Product
    {
        $product = $this->em
            ->createQuery('SELECT p FROM ' . Product::class . ' p WHERE p.id = :id')
            ->setParameter('id', $id)
            ->getSingleResult();

        self::assertInstanceOf(Product::class, $product);

        return $product;
    }
}
