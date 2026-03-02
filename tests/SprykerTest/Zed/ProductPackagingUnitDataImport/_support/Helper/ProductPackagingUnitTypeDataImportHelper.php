<?php

/**
 * MIT License
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

namespace SprykerTest\Zed\ProductPackagingUnitDataImport\Helper;

use Codeception\Module;
use Orm\Zed\ProductPackagingUnit\Persistence\Map\SpyProductPackagingUnitTypeTableMap;
use Orm\Zed\ProductPackagingUnit\Persistence\SpyProductPackagingUnitTypeQuery;
use SprykerTest\Shared\Testify\Helper\DataCleanupHelperTrait;

class ProductPackagingUnitTypeDataImportHelper extends Module
{
    use DataCleanupHelperTrait;

    /**
     * @var string
     */
    protected const ERROR_MESSAGE_FOUND = 'Found at least one entry in the database table but database table `%s` was expected to be empty.';

    /**
     * @var string
     */
    protected const ERROR_MESSAGE_EXPECTED = 'Expected at least one entry in the database table `%s` but table is empty.';

    public function truncateProductPackagingUnitTypes(): void
    {
        $this->getProductPackagingUnitTypeQuery()
            ->deleteAll();
    }

    public function assertProductPackagingUnitTypeTableIsEmtpy(): void
    {
        $this->assertFalse($this->getProductPackagingUnitTypeQuery()->exists(), sprintf(static::ERROR_MESSAGE_FOUND, SpyProductPackagingUnitTypeTableMap::TABLE_NAME));
    }

    public function assertProductPackagingUnitTypeTableHasRecords(): void
    {
        $this->assertTrue($this->getProductPackagingUnitTypeQuery()->exists(), sprintf(static::ERROR_MESSAGE_EXPECTED, SpyProductPackagingUnitTypeTableMap::TABLE_NAME));
    }

    protected function getProductPackagingUnitTypeQuery(): SpyProductPackagingUnitTypeQuery
    {
        return SpyProductPackagingUnitTypeQuery::create();
    }
}
