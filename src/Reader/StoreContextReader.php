<?php

declare(strict_types=1);

namespace Agent001\SyliusAiAssistantPlugin\Reader;

use Doctrine\ORM\EntityRepository;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Product\Model\ProductOptionInterface;
use Sylius\Component\Product\Repository\ProductOptionRepositoryInterface;
use Sylius\Component\Taxonomy\Repository\TaxonRepositoryInterface;
use Sylius\Resource\Model\TranslatableInterface;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class StoreContextReader implements StoreContextReaderInterface
{
    private const MAX_TITLE = 500;

    private const MAX_DESCRIPTION = 1000;

    /**
     * @param TaxonRepositoryInterface<TaxonInterface> $taxonRepository
     * @param ProductOptionRepositoryInterface<ProductOptionInterface> $optionRepository
     */
    public function __construct(
        private readonly TaxonRepositoryInterface $taxonRepository,
        private readonly ProductOptionRepositoryInterface $optionRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @return list<TaxonInterface>
     */
    public function taxons(ChannelInterface $channel): array
    {
        /** @var EntityRepository<TaxonInterface> $repository */
        $repository = $this->taxonRepository;
        $qb = $repository->createQueryBuilder('t')
            ->andWhere('t.parent IS NOT NULL')
            ->orderBy('t.root', 'ASC')
            ->addOrderBy('t.left', 'ASC');

        $menu = $channel->getMenuTaxon();
        if (null !== $menu) {
            $qb->andWhere('t.root = :root')
               ->andWhere('t.left > :left')
               ->andWhere('t.right < :right')
               ->setParameter('root', $menu->getRoot() ?? $menu)
               ->setParameter('left', $menu->getLeft())
               ->setParameter('right', $menu->getRight());
        }

        /** @var list<TaxonInterface> $taxons */
        $taxons = $qb->getQuery()->getResult();
        $locale = $this->locale($channel);
        foreach ($taxons as $taxon) {
            $this->localize($taxon, $locale);
        }

        return $taxons;
    }

    /**
     * @return list<array{type: string, id: string, title: string, content: string, url: string}>
     */
    public function read(ChannelInterface $channel, string $site): array
    {
        $context = [];
        $locale = $this->locale($channel);

        foreach ($this->taxons($channel) as $taxon) {
            $context[] = $this->taxonEntry($taxon, $locale, $site);
        }

        /** @var ProductOptionInterface $option */
        foreach ($this->optionRepository->findAll() as $option) {
            $this->localize($option, $locale);
            $name = (string) ($option->getName() ?? $option->getCode());
            $context[] = [
                'type' => 'attribute',
                'id' => 'option:' . $option->getCode(),
                'title' => mb_substr($name, 0, self::MAX_TITLE),
                'content' => 'Product option.',
                'url' => '',
            ];
            foreach ($option->getValues() as $value) {
                $this->localize($value, $locale);
                $context[] = [
                    'type' => 'attribute',
                    'id' => 'option:' . $option->getCode() . ':' . $value->getCode(),
                    'title' => mb_substr((string) ($value->getValue() ?? $value->getCode()), 0, self::MAX_TITLE),
                    'content' => sprintf('Option: %s. Code: %s.', $name, (string) $value->getCode()),
                    'url' => '',
                ];
            }
        }

        return $context;
    }

    /**
     * @return array{type: string, id: string, title: string, content: string, url: string}
     */
    private function taxonEntry(TaxonInterface $taxon, string $locale, string $site): array
    {
        $content = sprintf('Taxonomy: category. Slug: %s.', (string) $taxon->getSlug());
        $description = trim(strip_tags((string) $taxon->getDescription()));
        if ('' !== $description) {
            $content .= ' Description: ' . mb_substr($description, 0, self::MAX_DESCRIPTION);
        }
        $parent = $taxon->getParent();
        if (null !== $parent && null !== $parent->getParent()) {
            $this->localize($parent, $locale);
            $content .= ' Parent: ' . (string) ($parent->getName() ?? $parent->getCode()) . '.';
        }

        $url = '';

        try {
            if (null !== $taxon->getSlug()) {
                $url = $site . $this->urlGenerator->generate(
                    'sylius_shop_product_index',
                    ['_locale' => $locale, 'slug' => $taxon->getSlug()],
                    UrlGeneratorInterface::ABSOLUTE_PATH,
                );
            }
        } catch (RoutingException) {
            $url = '';
        }

        return [
            'type' => 'category',
            'id' => 'taxon:' . $taxon->getCode(),
            'title' => mb_substr((string) ($taxon->getName() ?? $taxon->getCode()), 0, self::MAX_TITLE),
            'content' => $content,
            'url' => $url,
        ];
    }

    private function locale(ChannelInterface $channel): string
    {
        return $channel->getDefaultLocale()?->getCode() ?? 'en_US';
    }

    private function localize(object $translatable, string $locale): void
    {
        if ($translatable instanceof TranslatableInterface && $translatable->getTranslations()->containsKey($locale)) {
            $translatable->setCurrentLocale($locale);
        }
    }
}
