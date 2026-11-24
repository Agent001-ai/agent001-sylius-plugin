<?php

declare(strict_types=1);

namespace Tests\Agent001\SyliusAiAssistantPlugin\Unit\Reader;

use Agent001\SyliusAiAssistantPlugin\Reader\StoreContextReader;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Sylius\Bundle\TaxonomyBundle\Doctrine\ORM\TaxonRepository;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\Taxon;
use Sylius\Component\Locale\Model\Locale;
use Sylius\Component\Product\Model\ProductOption;
use Sylius\Component\Product\Model\ProductOptionValue;
use Sylius\Component\Product\Repository\ProductOptionRepositoryInterface;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class StoreContextReaderTest extends TestCase
{
    public function testItDescribesTaxonsAndOptionsInTheChannelLocale(): void
    {
        $root = $this->taxon('MENU', ['en_US' => 'Menu'], 'menu');
        $shirts = $this->taxon('SHIRTS', ['en_US' => 'Shirts', 'pl_PL' => 'Koszulki'], 'shirts', $root);
        $men = $this->taxon('MEN', ['en_US' => 'Men'], 'shirts/men', $shirts, '<p>Cotton <b>shirts</b></p>');

        $color = new ProductOption();
        $color->setCode('COLOR');
        $this->translate($color, ['pl_PL' => 'Kolor'], static function (ProductOption $option, string $name): void {
            $option->setName($name);
        });
        $red = new ProductOptionValue();
        $red->setCode('RED');
        $setValue = static function (ProductOptionValue $value, string $name): void {
            $value->setValue($name);
        };
        $this->translate($red, ['pl_PL' => 'Czerwony'], $setValue);
        $color->addValue($red);

        $context = $this->reader([$shirts, $men], [$color], $root)->read($this->channel($root), 'https://shop.test');

        $this->assertSame([
            [
                'type' => 'category',
                'id' => 'taxon:SHIRTS',
                'title' => 'Koszulki',
                'content' => 'Taxonomy: category. Slug: shirts.',
                'url' => 'https://shop.test/pl_PL/taxons/shirts',
            ],
            [
                'type' => 'category',
                'id' => 'taxon:MEN',
                'title' => 'Men',
                'content' => 'Taxonomy: category. Slug: shirts/men. Description: Cotton shirts Parent: Koszulki.',
                'url' => 'https://shop.test/pl_PL/taxons/shirts/men',
            ],
            [
                'type' => 'attribute',
                'id' => 'option:COLOR',
                'title' => 'Kolor',
                'content' => 'Product option.',
                'url' => '',
            ],
            [
                'type' => 'attribute',
                'id' => 'option:COLOR:RED',
                'title' => 'Czerwony',
                'content' => 'Option: Kolor. Code: RED.',
                'url' => '',
            ],
        ], $context);
    }

    public function testATaxonWithoutAShopRouteHasNoUrl(): void
    {
        $taxon = $this->taxon('SHIRTS', ['en_US' => 'Shirts'], 'shirts');
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willThrowException(new RouteNotFoundException());

        $context = $this->reader([$taxon], [], null, $urlGenerator)->read($this->channel(null), 'https://shop.test');

        $this->assertSame('', $context[0]['url']);
    }

    public function testLongTitlesAndDescriptionsAreTruncated(): void
    {
        $taxon = $this->taxon('LONG', ['en_US' => str_repeat('t', 600)], 'long', null, str_repeat('d', 1200));

        $entry = $this->reader([$taxon], [], null)->read($this->channel(null), 'https://shop.test')[0];

        $this->assertSame(500, mb_strlen($entry['title']));
        $this->assertSame(
            'Taxonomy: category. Slug: long. Description: ' . str_repeat('d', 1000),
            $entry['content'],
        );
    }

    /**
     * @param list<Taxon> $taxons
     * @param list<ProductOption> $options
     */
    private function reader(
        array $taxons,
        array $options,
        ?Taxon $menu,
        ?UrlGeneratorInterface $urlGenerator = null,
    ): StoreContextReader {
        $query = $this->createMock(Query::class);
        $query->method('getResult')->willReturn($taxons);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('addOrderBy')->willReturnSelf();
        $qb->expects(null === $menu ? $this->never() : $this->atLeastOnce())
            ->method('setParameter')
            ->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $taxonRepository = $this->createMock(TaxonRepository::class);
        $taxonRepository->method('createQueryBuilder')->with('t')->willReturn($qb);

        $optionRepository = $this->createMock(ProductOptionRepositoryInterface::class);
        $optionRepository->method('findAll')->willReturn($options);

        if (null === $urlGenerator) {
            $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
            $urlGenerator->method('generate')->willReturnCallback(
                static fn (string $route, array $parameters): string => sprintf(
                    '/%s/taxons/%s',
                    $parameters['_locale'],
                    $parameters['slug'],
                ),
            );
        }

        return new StoreContextReader($taxonRepository, $optionRepository, $urlGenerator);
    }

    private function channel(?Taxon $menu): Channel
    {
        $locale = new Locale();
        $locale->setCode('pl_PL');
        $channel = new Channel();
        $channel->setDefaultLocale($locale);
        $channel->setMenuTaxon($menu);

        return $channel;
    }

    /**
     * @param array<string, string> $names
     */
    private function taxon(
        string $code,
        array $names,
        string $slug,
        ?Taxon $parent = null,
        ?string $description = null,
    ): Taxon {
        $taxon = new Taxon();
        $taxon->setCode($code);
        $taxon->setParent($parent);
        $this->translate($taxon, $names, static function (Taxon $taxon, string $name) use ($slug, $description): void {
            $taxon->setName($name);
            $taxon->setSlug($slug);
            $taxon->setDescription($description);
        });

        return $taxon;
    }

    /**
     * @template T of Taxon|ProductOption|ProductOptionValue
     *
     * @param T $translatable
     * @param array<string, string> $names
     * @param \Closure(T, string): void $apply
     */
    private function translate(object $translatable, array $names, \Closure $apply): void
    {
        $translatable->setFallbackLocale((string) array_key_first($names));
        foreach ($names as $locale => $name) {
            $translatable->setCurrentLocale($locale);
            $apply($translatable, $name);
        }
        $translatable->setCurrentLocale((string) array_key_first($names));
    }
}
