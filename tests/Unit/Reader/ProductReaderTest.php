<?php

declare(strict_types=1);

namespace Tests\Agent001\SyliusAiAssistantPlugin\Unit\Reader;

use Agent001\SyliusAiAssistantPlugin\Reader\ProductReader;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Liip\ImagineBundle\Exception\Imagine\Filter\NonExistingFilterException;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use PHPUnit\Framework\TestCase;
use Sylius\Bundle\CoreBundle\Doctrine\ORM\ProductRepository;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ChannelPricingInterface;
use Sylius\Component\Core\Model\ProductImageInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Locale\Model\LocaleInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class ProductReaderTest extends TestCase
{
    public function testCountProductsWithoutTaxons(): void
    {
        $productRepository = $this->createMock(ProductRepository::class);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);

        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getId')->willReturn(1);

        $query = $this->createMock(Query::class);
        $query->method('getSingleScalarResult')->willReturn('15');

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->with('COUNT(DISTINCT p.id)')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('innerJoin')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $productRepository->method('createQueryBuilder')->with('p')->willReturn($qb);

        $reader = new ProductReader($productRepository, $urlGenerator, $this->createMock(CacheManager::class));
        $count = $reader->count($channel);

        $this->assertSame(15, $count);
    }

    public function testCountProductsWithTaxonsUsesProductTaxons(): void
    {
        $productRepository = $this->createMock(ProductRepository::class);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);

        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getId')->willReturn(1);

        $query = $this->createMock(Query::class);
        $query->method('getSingleScalarResult')->willReturn('5');

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('innerJoin')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $joinedAssociations = [];
        $qb->method('leftJoin')->willReturnCallback(function (string $join, string $alias) use (
            $qb,
            &$joinedAssociations,
        ) {
            $joinedAssociations[] = $join;

            return $qb;
        });

        $productRepository->method('createQueryBuilder')->with('p')->willReturn($qb);

        $reader = new ProductReader($productRepository, $urlGenerator, $this->createMock(CacheManager::class));
        $count = $reader->count($channel, ['t_shirts', 'mens_t_shirts']);

        $this->assertSame(5, $count);
        $this->assertContains('p.productTaxons', $joinedAssociations);
        $this->assertContains('productTaxon.taxon', $joinedAssociations);
        $this->assertContains('p.mainTaxon', $joinedAssociations);
    }

    public function testReadOneSerializesProductCorrectly(): void
    {
        $productRepository = $this->createMock(ProductRepository::class);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);

        $locale = $this->createMock(LocaleInterface::class);
        $locale->method('getCode')->willReturn('en_US');

        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getDefaultLocale')->willReturn($locale);

        $taxon = $this->createMock(TaxonInterface::class);
        $taxon->method('getName')->willReturn('T-shirts');
        $taxon->method('getCode')->willReturn('t_shirts');

        $pricing = $this->createMock(ChannelPricingInterface::class);
        $pricing->method('getPrice')->willReturn(1999);
        $pricing->method('getOriginalPrice')->willReturn(2499);

        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getId')->willReturn(10);
        $variant->method('getCode')->willReturn('VAR-001');
        $variant->method('getName')->willReturn('Variant M');
        $variant->method('getChannelPricingForChannel')->with($channel)->willReturn($pricing);
        $variant->method('isTracked')->willReturn(true);
        $variant->method('getOnHand')->willReturn(20);
        $variant->method('getOnHold')->willReturn(5);
        $variant->method('isEnabled')->willReturn(true);
        $variant->method('isInStock')->willReturn(true);

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);
        $product->method('isEnabled')->willReturn(true);
        $product->method('hasChannel')->with($channel)->willReturn(true);
        $product->method('getName')->willReturn('Super T-Shirt');
        $product->method('getDescription')->willReturn('<p>Nice t-shirt</p>');
        $product->method('getSlug')->willReturn('super-t-shirt');
        $product->method('getTaxons')->willReturn(new ArrayCollection([$taxon]));
        $product->method('getMainTaxon')->willReturn(null);
        $product->method('getImages')->willReturn(new ArrayCollection([]));
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant]));
        $product->method('isSimple')->willReturn(false);

        $productRepository->method('find')->with(1)->willReturn($product);

        $urlGenerator->method('generate')->with(
            'sylius_shop_product_show',
            ['_locale' => 'en_US', 'slug' => 'super-t-shirt'],
            UrlGeneratorInterface::ABSOLUTE_PATH,
        )->willReturn('/en_US/products/super-t-shirt');

        $reader = new ProductReader($productRepository, $urlGenerator, $this->createMock(CacheManager::class));
        $data = $reader->readOne(1, $channel, 'https://shop.test');

        $this->assertNotNull($data);
        $this->assertSame(1, $data['id']);
        $this->assertSame('Super T-Shirt', $data['title']);
        $this->assertSame('Nice t-shirt', $data['description']);
        $this->assertSame('VAR-001', $data['sku']);
        $this->assertSame('19.99', $data['price']);
        $this->assertSame('24.99', $data['regular_price']);
        $this->assertSame('15', $data['stock']);
        $this->assertSame('instock', $data['stock_status']);
        $this->assertSame(['T-shirts'], $data['categories']);
        $this->assertSame('https://shop.test/en_US/products/super-t-shirt', $data['url']);
        $this->assertCount(1, $data['variations']);
        $this->assertSame('VAR-001', $data['variations'][0]['sku']);
    }

    public function testReadOneJudgesStockLikeTheCatalogQuery(): void
    {
        $channel = $this->createMock(ChannelInterface::class);
        $read = function (bool $tracked, int $onHand, int $onHold) use ($channel): ?array {
            $variant = $this->createMock(ProductVariantInterface::class);
            $variant->method('isEnabled')->willReturn(true);
            $variant->method('isTracked')->willReturn($tracked);
            $variant->method('getOnHand')->willReturn($onHand);
            $variant->method('getOnHold')->willReturn($onHold);
            $variant->method('isInStock')->willReturn($onHand > 0);

            $product = $this->createMock(ProductInterface::class);
            $product->method('getId')->willReturn(1);
            $product->method('isEnabled')->willReturn(true);
            $product->method('hasChannel')->willReturn(true);
            $product->method('isSimple')->willReturn(false);
            $product->method('getTaxons')->willReturn(new ArrayCollection([]));
            $product->method('getImages')->willReturn(new ArrayCollection([]));
            $product->method('getVariants')->willReturn(new ArrayCollection([$variant]));

            $productRepository = $this->createMock(ProductRepository::class);
            $productRepository->method('find')->willReturn($product);

            return $this->reader($productRepository)->readOne(1, $channel, 'https://shop.test');
        };

        $untracked = $read(false, 0, 0);
        $this->assertNotNull($untracked);
        $this->assertSame('instock', $untracked['variations'][0]['stock_status']);
        $this->assertNull($untracked['variations'][0]['stock']);

        $this->assertNull($read(true, 5, 5));
        $this->assertNotNull($read(true, 5, 4));
    }

    public function testReadOneSkipsProductOutsideTheChannel(): void
    {
        $productRepository = $this->createMock(ProductRepository::class);
        $channel = $this->createMock(ChannelInterface::class);

        $product = $this->createMock(ProductInterface::class);
        $product->method('isEnabled')->willReturn(true);
        $product->method('hasChannel')->with($channel)->willReturn(false);
        $productRepository->method('find')->with(1)->willReturn($product);

        $reader = $this->reader($productRepository);

        $this->assertNull($reader->readOne(1, $channel, 'https://shop.test'));
    }

    public function testImagesUseTheShopThumbnailOnTheChannelSite(): void
    {
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('getBrowserPath')->willReturnCallback(
            static function (string $path, string $filter): string {
                if ('broken.jpg' === $path) {
                    throw new NonExistingFilterException('Filter failed.');
                }

                return 'http://localhost/media/cache/' . $filter . '/' . $path;
            },
        );

        $productRepository = $this->productWithImages(['ab/shirt.jpg', 'broken.jpg', '']);

        $data = $this->reader($productRepository, $cacheManager)
            ->readOne(1, $this->createMock(ChannelInterface::class), 'https://shop.test');

        $this->assertNotNull($data);
        $this->assertSame([
            'https://shop.test/media/cache/sylius_shop_product_large_thumbnail/ab/shirt.jpg',
            'https://shop.test/media/image/broken.jpg',
        ], $data['images']);
    }

    public function testUnexpectedImageErrorsAreNotHidden(): void
    {
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('getBrowserPath')->willThrowException(new \LogicException('Bug.'));

        $this->expectException(\LogicException::class);

        $this->reader($this->productWithImages(['ab/shirt.jpg']), $cacheManager)
            ->readOne(1, $this->createMock(ChannelInterface::class), 'https://shop.test');
    }

    private function reader(ProductRepository $productRepository, ?CacheManager $cacheManager = null): ProductReader
    {
        return new ProductReader(
            $productRepository,
            $this->createMock(UrlGeneratorInterface::class),
            $cacheManager ?? $this->createMock(CacheManager::class),
        );
    }

    /**
     * @param list<string> $paths
     */
    private function productWithImages(array $paths): ProductRepository
    {
        $images = [];
        foreach ($paths as $path) {
            $image = $this->createMock(ProductImageInterface::class);
            $image->method('getPath')->willReturn($path);
            $images[] = $image;
        }

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(1);
        $product->method('isEnabled')->willReturn(true);
        $product->method('hasChannel')->willReturn(true);
        $product->method('getTaxons')->willReturn(new ArrayCollection([]));
        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('isEnabled')->willReturn(true);
        $variant->method('isTracked')->willReturn(false);

        $product->method('isSimple')->willReturn(false);
        $product->method('getImages')->willReturn(new ArrayCollection($images));
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant]));

        $productRepository = $this->createMock(ProductRepository::class);
        $productRepository->method('find')->willReturn($product);

        return $productRepository;
    }
}
