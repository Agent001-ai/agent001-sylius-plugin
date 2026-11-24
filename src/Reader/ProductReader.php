<?php

declare(strict_types=1);

namespace Agent001\SyliusAiAssistantPlugin\Reader;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Liip\ImagineBundle\Exception\ExceptionInterface as ImagineException;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Sylius\Resource\Model\TranslatableInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class ProductReader implements ProductReaderInterface
{
    /**
     * @param ProductRepositoryInterface<ProductInterface> $productRepository
     */
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly CacheManager $cacheManager,
    ) {
    }

    /**
     * @param list<string> $taxonCodes
     */
    public function count(ChannelInterface $channel, array $taxonCodes = []): int
    {
        /** @var EntityRepository<ProductInterface> $repo */
        $repo = $this->productRepository;
        $qb = $repo->createQueryBuilder('p')
            ->select('COUNT(DISTINCT p.id)')
            ->andWhere('p.enabled = true');

        $this->applyFilters($qb, $channel, $taxonCodes);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @param list<string> $taxonCodes
     *
     * @return list<array<string, mixed>>
     */
    public function readBatchAfterId(
        int $afterId,
        int $limit,
        ChannelInterface $channel,
        string $site,
        array $taxonCodes = [],
    ): array {
        /** @var EntityRepository<ProductInterface> $repo */
        $repo = $this->productRepository;
        $qb = $repo->createQueryBuilder('p')
            ->andWhere('p.enabled = true')
            ->andWhere('p.id > :afterId')
            ->setParameter('afterId', $afterId)
            ->groupBy('p.id')
            ->orderBy('p.id', 'ASC')
            ->setMaxResults($limit);

        $this->applyFilters($qb, $channel, $taxonCodes);

        /** @var list<ProductInterface> $products */
        $products = $qb->getQuery()->getResult();

        $result = [];
        foreach ($products as $product) {
            $result[] = $this->serializeProduct($product, $channel, $site);
        }

        return $result;
    }

    /**
     * @param list<string> $taxonCodes
     *
     * @return array<string, mixed>|null
     */
    public function readOne(int $id, ChannelInterface $channel, string $site, array $taxonCodes = []): ?array
    {
        /** @var ProductInterface|null $product */
        $product = $this->productRepository->find($id);
        if (
            null === $product ||
            !$product->isEnabled() ||
            !$product->hasChannel($channel) ||
            !$this->hasAvailableVariant($product)
        ) {
            return null;
        }

        if ([] !== $taxonCodes && !$this->hasAnyTaxon($product, $taxonCodes)) {
            return null;
        }

        return $this->serializeProduct($product, $channel, $site);
    }

    /**
     * @param list<string> $taxonCodes
     */
    private function applyFilters(QueryBuilder $qb, ChannelInterface $channel, array $taxonCodes): void
    {
        $qb->andWhere(sprintf(
            'EXISTS (SELECT 1 FROM %s availableVariant WHERE availableVariant.product = p'
            . ' AND availableVariant.enabled = true AND (availableVariant.tracked = false'
            . ' OR availableVariant.onHand - availableVariant.onHold > 0))',
            ProductVariantInterface::class,
        ));

        $qb->innerJoin('p.channels', 'channel')
           ->andWhere('channel.id = :channelId')
           ->setParameter('channelId', $channel->getId());

        if ([] !== $taxonCodes) {
            $qb->leftJoin('p.productTaxons', 'productTaxon')
               ->leftJoin('productTaxon.taxon', 'taxon')
               ->leftJoin('p.mainTaxon', 'mainTaxon')
               ->andWhere('taxon.code IN (:taxonCodes) OR mainTaxon.code IN (:taxonCodes)')
               ->setParameter('taxonCodes', $taxonCodes);
        }
    }

    private function hasAvailableVariant(ProductInterface $product): bool
    {
        /** @var ProductVariantInterface $variant */
        foreach ($product->getVariants() as $variant) {
            if ($this->isVariantAvailable($variant)) {
                return true;
            }
        }

        return false;
    }

    private function isVariantAvailable(ProductVariantInterface $variant): bool
    {
        return $variant->isEnabled() && (!$variant->isTracked() || $variant->getOnHand() - $variant->getOnHold() > 0);
    }

    /**
     * @param list<string> $taxonCodes
     */
    private function hasAnyTaxon(ProductInterface $product, array $taxonCodes): bool
    {
        if (null !== $product->getMainTaxon() && in_array($product->getMainTaxon()->getCode(), $taxonCodes, true)) {
            return true;
        }

        foreach ($product->getTaxons() as $taxon) {
            if (in_array($taxon->getCode(), $taxonCodes, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeProduct(ProductInterface $product, ChannelInterface $channel, string $site): array
    {
        $locale = $channel->getDefaultLocale()?->getCode() ?? 'en_US';
        $this->localize($product, $locale);
        foreach ([...$product->getTaxons(), $product->getMainTaxon()] as $taxon) {
            for ($node = $taxon; null !== $node; $node = $node->getParent()) {
                $this->localize($node, $locale);
            }
        }

        $productId = (int) $product->getId();
        $title = (string) ($product->getName() ?? $product->getCode());
        $description = strip_tags((string) ($product->getDescription() ?? ($product->getShortDescription() ?? '')));

        $taxonNames = [];
        foreach ($product->getTaxons() as $taxon) {
            /** @var TaxonInterface $taxon */
            $taxonNames[] = (string) ($taxon->getName() ?? $taxon->getCode());
        }
        if (null !== $product->getMainTaxon()) {
            $taxonNames[] = (string) ($product->getMainTaxon()->getName() ?? $product->getMainTaxon()->getCode());
        }

        $url = '';

        try {
            if (null !== $product->getSlug()) {
                $url = $site . $this->urlGenerator->generate(
                    'sylius_shop_product_show',
                    ['_locale' => $locale, 'slug' => $product->getSlug()],
                    UrlGeneratorInterface::ABSOLUTE_PATH,
                );
            }
        } catch (RoutingException) {
            $url = '';
        }

        $images = [];
        foreach ($product->getImages() as $image) {
            $path = $image->getPath();
            if (null === $path || '' === $path) {
                continue;
            }

            try {
                $images[] = $this->onSite(
                    $site,
                    $this->cacheManager->getBrowserPath($path, 'sylius_shop_product_large_thumbnail'),
                );

                continue;
            } catch (ImagineException|RoutingException|NotFoundHttpException|\OutOfBoundsException) {
            }
            $images[] = $site . '/media/image/' . ltrim($path, '/');
        }

        $variations = [];
        $primarySku = null;
        $primaryPrice = null;
        $primaryRegularPrice = null;
        $primaryStock = null;
        $primaryStockStatus = 'outofstock';

        /** @var ProductVariantInterface $variant */
        foreach ($product->getVariants() as $variant) {
            if (!$this->isVariantAvailable($variant)) {
                continue;
            }
            $this->localize($variant, $locale);

            $pricing = $variant->getChannelPricingForChannel($channel);
            $variantPrice = null !== $pricing && null !== $pricing->getPrice()
                ? number_format($pricing->getPrice() / 100, 2, '.', '')
                : null;
            $variantRegularPrice = null !== $pricing && null !== $pricing->getOriginalPrice()
                ? number_format($pricing->getOriginalPrice() / 100, 2, '.', '')
                : $variantPrice;

            $stockQuantity = $variant->isTracked() ? max(0, $variant->getOnHand() - $variant->getOnHold()) : null;

            if (null === $primaryPrice && null !== $variantPrice) {
                $primarySku = $variant->getCode();
                $primaryPrice = $variantPrice;
                $primaryRegularPrice = $variantRegularPrice;
                $primaryStock = null !== $stockQuantity ? (string) $stockQuantity : null;
                $primaryStockStatus = 'instock';
            }

            if ($product->isSimple()) {
                continue;
            }

            $variations[] = [
                'id' => (int) $variant->getId(),
                'title' => (string) ($variant->getName() ?? $variant->getCode()),
                'sku' => $variant->getCode(),
                'price' => $variantPrice,
                'regular_price' => $variantRegularPrice,
                'stock' => null !== $stockQuantity ? (string) $stockQuantity : null,
                'stock_status' => 'instock',
                'url' => $url,
                'attributes' => $this->variantAttributes($variant, $locale),
            ];
        }

        $data = [
            'id' => $productId,
            'type' => 'product',
            'title' => $title,
            'description' => $description,
            'parent_id' => 0,
            'sku' => $primarySku,
            'price' => $primaryPrice,
            'regular_price' => $primaryRegularPrice,
            'stock' => $primaryStock,
            'stock_status' => $primaryStockStatus,
            'currency' => $channel->getBaseCurrency()?->getCode(),
            'categories' => array_values(array_unique($taxonNames)),
            'category_paths' => $this->taxonPaths($product),
            'images' => $images,
            'image_url' => $images[0] ?? null,
            'url' => $url,
            'product_type' => $product->isSimple() ? 'simple' : 'variable',
            'on_sale' => null !== $primaryPrice &&
                null !== $primaryRegularPrice &&
                (float) $primaryPrice < (float) $primaryRegularPrice,
            'attributes' => $this->productAttributes($product, $locale),
            'variations' => $variations,
        ];

        $shortDescription = trim(strip_tags((string) $product->getShortDescription()));
        if ('' !== $shortDescription) {
            $data['short_description'] = $shortDescription;
        }

        return $data;
    }

    /**
     * @return list<list<string>>
     */
    private function taxonPaths(ProductInterface $product): array
    {
        $taxons = [];
        foreach ($product->getTaxons() as $taxon) {
            $taxons[] = $taxon;
        }
        if (null !== $product->getMainTaxon()) {
            $taxons[] = $product->getMainTaxon();
        }

        $paths = [];
        foreach ($taxons as $taxon) {
            $path = [];
            for ($node = $taxon; null !== $node && null !== $node->getParent(); $node = $node->getParent()) {
                array_unshift($path, (string) ($node->getName() ?? $node->getCode()));
            }
            if ([] !== $path) {
                $paths[implode("\0", $path)] = $path;
            }
        }

        return array_values($paths);
    }

    /**
     * @return list<array{name: string, options: list<string>}>
     */
    private function productAttributes(ProductInterface $product, string $locale): array
    {
        $attributes = [];
        foreach ($product->getAttributesByLocale($locale, $locale) as $attribute) {
            $options = $this->attributeOptions($attribute->getValue());
            $name = (string) $attribute->getName();
            if ('' === $name || [] === $options) {
                continue;
            }
            $attributes[] = ['name' => $name, 'options' => $options];
        }

        return $attributes;
    }

    /**
     * @return list<string>
     */
    private function attributeOptions(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];

        $options = [];
        foreach ($values as $entry) {
            $text = match (true) {
                is_bool($entry) => $entry ? 'yes' : 'no',
                $entry instanceof \DateTimeInterface => $entry->format('Y-m-d'),
                is_scalar($entry) => trim((string) $entry),
                default => '',
            };
            if ('' !== $text) {
                $options[] = $text;
            }
        }

        return $options;
    }

    /**
     * @return list<array{name: string, option: string}>
     */
    private function variantAttributes(ProductVariantInterface $variant, string $locale): array
    {
        $attributes = [];
        foreach ($variant->getOptionValues() as $optionValue) {
            $this->localize($optionValue, $locale);
            if (null !== $optionValue->getOption()) {
                $this->localize($optionValue->getOption(), $locale);
            }
            $name = (string) ($optionValue->getOption()?->getName() ?? '');
            $option = (string) ($optionValue->getValue() ?? '');
            if ('' !== $name && '' !== $option) {
                $attributes[] = ['name' => $name, 'option' => $option];
            }
        }

        return $attributes;
    }

    private function localize(object $translatable, string $locale): void
    {
        if ($translatable instanceof TranslatableInterface && $translatable->getTranslations()->containsKey($locale)) {
            $translatable->setCurrentLocale($locale);
        }
    }

    private function onSite(string $site, string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['path'])) {
            return $url;
        }

        return $site . $parts['path'] . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
}
