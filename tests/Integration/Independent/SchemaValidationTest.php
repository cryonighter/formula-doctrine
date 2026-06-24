<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration\Independent;

use Cryonighter\FormulaDoctrine\Tests\Integration\Independent\Fixture\Entity\Product;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Schema\AbstractAsset;

final class SchemaValidationTest extends IndependentOrmTestCase
{
    /**
     * Test that formula fields are not created as actual database columns
     *
     * @throws Exception
     */
    public function testFormulaFieldsAreNotInDatabase(): void
    {
        $tableName = $this->registry->getTableNameForClass(Product::class);

        $schemaManager = $this->em->getConnection()->createSchemaManager();
        $table = $schemaManager->introspectTableByUnquotedName($tableName);

        $columnNames = array_map(fn(AbstractAsset $column): string => $column->getName(), $table->getColumns());

        // Fields that should NOT exist in the database
        $forbiddenFields = [
            'orderCount',
            'order_count',
            'totalRevenue',
            'total_revenue',
            'total',
            'maxItemPrice',
            'max_item_price',
        ];

        foreach ($forbiddenFields as $fieldName) {
            self::assertNotContains(
                $fieldName,
                $columnNames,
                "Column '$fieldName' should not exist in 'products' table because it's a formula field"
            );
        }

        // Verify that actual columns DO exist
        self::assertContains('id', $columnNames);
        self::assertContains('name', $columnNames);
    }
}
