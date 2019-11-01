<?php

/**
 * MIT License
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Spryker\Zed\ProductPackagingUnitDataImport\Business\Model\ProductPackagingUnit;

use Orm\Zed\Product\Persistence\SpyProduct;
use Orm\Zed\Product\Persistence\SpyProductQuery;
use Orm\Zed\ProductMeasurementUnit\Persistence\SpyProductMeasurementSalesUnitQuery;
use Orm\Zed\ProductPackagingUnit\Persistence\SpyProductPackagingUnitQuery;
use Orm\Zed\ProductPackagingUnit\Persistence\SpyProductPackagingUnitTypeQuery;
use Spryker\DecimalObject\Decimal;
use Spryker\Zed\DataImport\Business\Exception\EntityNotFoundException;
use Spryker\Zed\DataImport\Business\Model\DataImportStep\DataImportStepInterface;
use Spryker\Zed\DataImport\Business\Model\DataImportStep\PublishAwareStep;
use Spryker\Zed\DataImport\Business\Model\DataSet\DataSetInterface;
use Spryker\Zed\ProductPackagingUnit\Dependency\ProductPackagingUnitEvents;
use Spryker\Zed\ProductPackagingUnitDataImport\Business\Model\DataSet\ProductPackagingUnitDataSetInterface;

class ProductPackagingUnitWriterStep extends PublishAwareStep implements DataImportStepInterface
{
    protected const AMOUNT_INTERVAL_DEFAULT = 1;
    protected const HEAP_LIMIT = 500;
    protected const PRODUCT_CONCRETE_ID = 'PRODUCT_CONCRETE_ID';

    /**
     * @var int[] Keys are product packaging unit type names.
     */
    protected static $idProductPackagingUnitTypeHeap = [];

    /**
     * @var int
     */
    protected static $idProductPackagingUnitTypeHeapCurrentSize = 0;

    /**
     * @var array Keys are product SKUs, values are a set of product concrete ID in a `PRODUCT_CONCRETE_ID` key.
     */
    protected static $productHeap = [];

    /**
     * @var int
     */
    protected static $productHeapCurrentSize = 0;

    /**
     * @var bool[] Keys are product SKUs, values boolean representing if this product have a MeasurementSalesUnit or not.
     */
    protected static $productMeasurementSalesUnitHeap = [];

    /**
     * @var int
     */
    protected static $productMeasurementSalesUnitHeapCurrentSize = 0;

    /**
     * @module Product
     * @module ProductPackagingUnit
     *
     * @param \Spryker\Zed\DataImport\Business\Model\DataSet\DataSetInterface $dataSet
     *
     * @return void
     */
    public function execute(DataSetInterface $dataSet): void
    {
        $this->assertHaveProductMeasurementSalesUnit($dataSet[ProductPackagingUnitDataSetInterface::COLUMN_CONCRETE_SKU]);

        $dataSet = $this->normalizeDataSet($dataSet);

        $productPackagingUnitTypeId = $this->getIdProductPackagingUnitTypeByName($dataSet[ProductPackagingUnitDataSetInterface::COLUMN_TYPE_NAME]);
        $productConcreteId = $this->getIdProductBySku($dataSet[ProductPackagingUnitDataSetInterface::COLUMN_CONCRETE_SKU]);
        $leadProductConcreteId = $this->getIdProductBySku($dataSet[ProductPackagingUnitDataSetInterface::COLUMN_LEAD_PRODUCT_SKU]);

        $productPackagingUnitEntity = $this->getProductPackagingUnitQuery()
            ->filterByFkProduct($productConcreteId)
            ->findOneOrCreate();

        $productPackagingUnitEntity
            ->setFkLeadProduct($leadProductConcreteId)
            ->setIsAmountVariable($dataSet[ProductPackagingUnitDataSetInterface::COLUMN_IS_AMOUNT_VARIABLE])
            ->setDefaultAmount($dataSet[ProductPackagingUnitDataSetInterface::COLUMN_DEFAULT_AMOUNT])
            ->setAmountMin($dataSet[ProductPackagingUnitDataSetInterface::COLUMN_AMOUNT_MIN])
            ->setAmountMax($dataSet[ProductPackagingUnitDataSetInterface::COLUMN_AMOUNT_MAX])
            ->setAmountInterval($dataSet[ProductPackagingUnitDataSetInterface::COLUMN_AMOUNT_INTERVAL])
            ->setFkProductPackagingUnitType($productPackagingUnitTypeId);

        $productPackagingUnitEntity->save();

        $this->addPublishEvents(ProductPackagingUnitEvents::PRODUCT_PACKAGING_UNIT_PUBLISH, $this->getIdProductBySku($dataSet[ProductPackagingUnitDataSetInterface::COLUMN_CONCRETE_SKU]));
    }

    /**
     * @param \Spryker\Zed\DataImport\Business\Model\DataSet\DataSetInterface $dataSet
     *
     * @return \Spryker\Zed\DataImport\Business\Model\DataSet\DataSetInterface
     */
    protected function normalizeDataSet(DataSetInterface $dataSet): DataSetInterface
    {
        $dataSet[ProductPackagingUnitDataSetInterface::COLUMN_IS_AMOUNT_VARIABLE] = (bool)$dataSet[ProductPackagingUnitDataSetInterface::COLUMN_IS_AMOUNT_VARIABLE];

        $dataSet = $this->normalizeAmount($dataSet);

        return $dataSet;
    }

    /**
     * @param \Spryker\Zed\DataImport\Business\Model\DataSet\DataSetInterface $dataSet
     *
     * @return \Spryker\Zed\DataImport\Business\Model\DataSet\DataSetInterface
     */
    protected function normalizeAmount(DataSetInterface $dataSet): DataSetInterface
    {
        $isAmountVariable = $dataSet[ProductPackagingUnitDataSetInterface::COLUMN_IS_AMOUNT_VARIABLE];

        if ($isAmountVariable && (new Decimal($dataSet[ProductPackagingUnitDataSetInterface::COLUMN_AMOUNT_INTERVAL]))->isZero()) {
            $dataSet[ProductPackagingUnitDataSetInterface::COLUMN_AMOUNT_INTERVAL] = static::AMOUNT_INTERVAL_DEFAULT;
        }

        if ($isAmountVariable && (new Decimal($dataSet[ProductPackagingUnitDataSetInterface::COLUMN_AMOUNT_MIN]))->isZero()) {
            $dataSet[ProductPackagingUnitDataSetInterface::COLUMN_AMOUNT_MIN] = $dataSet[ProductPackagingUnitDataSetInterface::COLUMN_AMOUNT_INTERVAL];
        }

        if (!$isAmountVariable) {
            $dataSet[ProductPackagingUnitDataSetInterface::COLUMN_AMOUNT_MIN] = null;
            $dataSet[ProductPackagingUnitDataSetInterface::COLUMN_AMOUNT_MAX] = null;
            $dataSet[ProductPackagingUnitDataSetInterface::COLUMN_AMOUNT_INTERVAL] = null;
        }

        return $dataSet;
    }

    /**
     * @param string $name
     *
     * @return int
     */
    protected function getIdProductPackagingUnitTypeByName(string $name): int
    {
        $this->addProductPackagingUnitTypeToHeapByName($name);

        return static::$idProductPackagingUnitTypeHeap[$name];
    }

    /**
     * @param string $name
     *
     * @throws \Spryker\Zed\DataImport\Business\Exception\EntityNotFoundException
     *
     * @return void
     */
    protected function addProductPackagingUnitTypeToHeapByName(string $name): void
    {
        if (isset(static::$idProductPackagingUnitTypeHeap[$name])) {
            return;
        }

        if (static::$idProductPackagingUnitTypeHeapCurrentSize >= static::HEAP_LIMIT) {
            $this->clearProductPackagingUnitTypeHeap();
        }

        $productPackagingUnitType = $this->getProductPackagingUnitTypeQuery()->findOneByName($name);

        if ($productPackagingUnitType === null) {
            throw new EntityNotFoundException(sprintf("Product packaging unit type '%s' was not found", $name));
        }

        static::$idProductPackagingUnitTypeHeap[$name] = $productPackagingUnitType->getIdProductPackagingUnitType();
    }

    /**
     * @return void
     */
    protected function clearProductPackagingUnitTypeHeap(): void
    {
        static::$idProductPackagingUnitTypeHeapCurrentSize = 0;
        static::$idProductPackagingUnitTypeHeap = [];
    }

    /**
     * @param string $productSku
     *
     * @return int
     */
    protected function getIdProductBySku(string $productSku): int
    {
        $this->addProductToProductHeapBySku($productSku);

        return static::$productHeap[$productSku][static::PRODUCT_CONCRETE_ID];
    }

    /**
     * @param string $productSku
     *
     * @throws \Spryker\Zed\DataImport\Business\Exception\EntityNotFoundException
     *
     * @return void
     */
    protected function assertHaveProductMeasurementSalesUnit(string $productSku): void
    {
        $this->addProductMeasurementSalesUnitCheckToHeap($productSku);

        if (static::$productMeasurementSalesUnitHeap[$productSku]) {
            return;
        }

        throw new EntityNotFoundException(sprintf("Product measurement sales unit was not found for SKU '%s'", $productSku));
    }

    /**
     * @param string $productSku
     *
     * @return void
     */
    protected function addProductMeasurementSalesUnitCheckToHeap(string $productSku): void
    {
        if (isset(static::$productMeasurementSalesUnitHeap[$productSku])) {
            return;
        }

        if (static::$productMeasurementSalesUnitHeapCurrentSize >= static::HEAP_LIMIT) {
            $this->clearProductMeasurementSalesUnitHeap();
        }

        $productConcreteId = $this->getIdProductBySku($productSku);

        static::$productMeasurementSalesUnitHeapCurrentSize++;
        static::$productMeasurementSalesUnitHeap[$productSku] = $this->getProductMeasurementSalesUnitQuery()
            ->filterByFkProduct($productConcreteId)
            ->exists();
    }

    /**
     * @param string $productSku
     *
     * @throws \Spryker\Zed\DataImport\Business\Exception\EntityNotFoundException
     *
     * @return void
     */
    protected function addProductToProductHeapBySku(string $productSku): void
    {
        if (isset(static::$productHeap[$productSku])) {
            return;
        }

        $productEntity = $this->getProductQuery()
            ->filterBySku($productSku)
            ->findOne();

        if ($productEntity === null) {
            throw new EntityNotFoundException(sprintf("Product concrete with SKU '%s' was not found", $productSku));
        }

        $this->addProductToProductHeap($productEntity);
    }

    /**
     * @param \Orm\Zed\Product\Persistence\SpyProduct $productEntity
     *
     * @return void
     */
    protected function addProductToProductHeap(SpyProduct $productEntity): void
    {
        if (static::$productHeapCurrentSize >= static::HEAP_LIMIT) {
            $this->clearProductHeap();
        }

        static::$productHeapCurrentSize++;
        static::$productHeap[$productEntity->getSku()] = [
            static::PRODUCT_CONCRETE_ID => $productEntity->getIdProduct(),
        ];
    }

    /**
     * @return void
     */
    protected function clearProductHeap(): void
    {
        static::$productHeapCurrentSize = 0;
        static::$productHeap = [];
    }

    /**
     * @return void
     */
    protected function clearProductMeasurementSalesUnitHeap(): void
    {
        static::$productMeasurementSalesUnitHeapCurrentSize = 0;
        static::$productMeasurementSalesUnitHeap = [];
    }

    /**
     * @module Product
     *
     * @return \Orm\Zed\Product\Persistence\SpyProductQuery
     */
    protected function getProductQuery(): SpyProductQuery
    {
        return SpyProductQuery::create();
    }

    /**
     * @module ProductPackagingUnit
     *
     * @return \Orm\Zed\ProductPackagingUnit\Persistence\SpyProductPackagingUnitQuery
     */
    protected function getProductPackagingUnitQuery(): SpyProductPackagingUnitQuery
    {
        return SpyProductPackagingUnitQuery::create();
    }

    /**
     * @module ProductPackagingUnit
     *
     * @return \Orm\Zed\ProductPackagingUnit\Persistence\SpyProductPackagingUnitTypeQuery
     */
    protected function getProductPackagingUnitTypeQuery(): SpyProductPackagingUnitTypeQuery
    {
        return SpyProductPackagingUnitTypeQuery::create();
    }

    /**
     * @return \Orm\Zed\ProductMeasurementUnit\Persistence\SpyProductMeasurementSalesUnitQuery
     */
    protected function getProductMeasurementSalesUnitQuery(): SpyProductMeasurementSalesUnitQuery
    {
        return SpyProductMeasurementSalesUnitQuery::create();
    }
}
