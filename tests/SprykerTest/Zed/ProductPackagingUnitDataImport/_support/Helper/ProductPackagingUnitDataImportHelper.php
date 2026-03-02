<?php

/**
 * MIT License
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

namespace SprykerTest\Zed\ProductPackagingUnitDataImport\Helper;

use Codeception\Module;
use Orm\Zed\ProductPackagingUnit\Persistence\Map\SpyProductPackagingUnitTableMap;
use Orm\Zed\ProductPackagingUnit\Persistence\SpyProductPackagingUnitQuery;

class ProductPackagingUnitDataImportHelper extends Module
{
    /**
     * @var string
     */
    protected const ERROR_MESSAGE_FOUND = 'Found at least one entry in the database table but database table `%s` was expected to be empty.';

    /**
     * @var string
     */
    protected const ERROR_MESSAGE_EXPECTED_COUNT = 'Expected exactly %d entries in the database table `%s`, but found %d.';

    public function truncateProductPackagingUnits(): void
    {
        $this->getProductPackagingUnitQuery()
            ->deleteAll();
    }

    public function assertProductPackagingUnitTableIsEmtpy(): void
    {
        $this->assertFalse($this->getProductPackagingUnitQuery()->exists(), sprintf(static::ERROR_MESSAGE_FOUND, SpyProductPackagingUnitTableMap::TABLE_NAME));
    }

    public function assertProductPackagingUnitTableHasRecords(int $expectedCount): void
    {
        $foundCount = $this->getProductPackagingUnitQuery()->count();
        $this->assertSame($expectedCount, $foundCount, sprintf(static::ERROR_MESSAGE_EXPECTED_COUNT, $expectedCount, SpyProductPackagingUnitTableMap::TABLE_NAME, $foundCount));
    }

    public function cleanupProductPackagingUnitProduct(int $productConcreteId): void
    {
        $this->debug(sprintf('Deleting product packaging unit for Product: %s', $productConcreteId));

        $this->getProductPackagingUnitQuery()
            ->findByFkProduct($productConcreteId)
            ->delete();
    }

    protected function getProductPackagingUnitQuery(): SpyProductPackagingUnitQuery
    {
        return SpyProductPackagingUnitQuery::create();
    }
}
