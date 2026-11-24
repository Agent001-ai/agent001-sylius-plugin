<?php

declare(strict_types=1);

namespace Tests\Agent001\SyliusAiAssistantPlugin\Functional;

use Agent001\SyliusAiAssistantPlugin\Entity\Agent001Connection;
use Agent001\SyliusAiAssistantPlugin\Entity\Agent001Setting;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Currency\Model\CurrencyInterface;
use Sylius\Component\Locale\Model\LocaleInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionFactoryInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Tests\Agent001\SyliusAiAssistantPlugin\Http\FakeAgent001Backend;

abstract class FunctionalTestCase extends WebTestCase
{
    protected const CHANNEL_CODE = 'AGENT001_FT';

    protected const HOST = 'agent001-ft.localhost';

    protected const OTHER_HOST = 'b2b.agent001-ft.localhost';

    protected const PRODUCT_PREFIX = 'AGENT001_FT_';

    protected const ORDER_PREFIX = 'AGENT001-FT-';

    protected const CUSTOMER_DOMAIN = '@agent001-ft.test';

    protected const ADMIN_EMAIL = 'agent001-ft@example.com';

    protected const AGENT_ID = '0198a6c2-1b3e-7c11-9d2a-3f4b5c6d7e8f';

    protected const OTHER_AGENT_ID = '0198a6c2-1b3e-7c11-9d2a-3f4b5c6d7e90';

    protected const LOCALE = 'en_US';

    protected const TRANSPORT = 'messenger.transport.agent001_sylius_ai_assistant_test';

    protected function setUp(): void
    {
        FakeAgent001Backend::reset();
    }

    protected function tearDown(): void
    {
        FakeAgent001Backend::reset();
        if (static::$booted) {
            $this->purge();
        }

        parent::tearDown();
    }

    protected function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    protected function backend(?KernelBrowser $client = null): FakeAgent001Backend
    {
        $backend = ($client?->getContainer() ?? static::getContainer())->get(FakeAgent001Backend::class);
        self::assertInstanceOf(FakeAgent001Backend::class, $backend);

        return $backend;
    }

    /**
     * @return list<object>
     */
    protected function queuedMessages(?KernelBrowser $client = null): array
    {
        $container = $client?->getContainer() ?? static::getContainer();
        $transport = $container->get(self::TRANSPORT);
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        $messages = [];
        foreach ($transport->getSent() as $envelope) {
            $messages[] = $envelope->getMessage();
        }

        return $messages;
    }

    protected function endRequest(): void
    {
        $dispatcher = static::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        self::assertInstanceOf(KernelInterface::class, static::$kernel);
        $dispatcher->dispatch(
            new TerminateEvent(static::$kernel, new Request(), new Response()),
            KernelEvents::TERMINATE,
        );
    }

    /**
     * @return list<object>
     */
    protected function takeQueuedMessages(?KernelBrowser $client = null): array
    {
        $messages = $this->queuedMessages($client);
        $transport = ($client?->getContainer() ?? static::getContainer())->get(self::TRANSPORT);
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();

        return $messages;
    }

    protected function createAdministrator(): AdminUserInterface
    {
        $administrator = $this->factory('sylius.factory.admin_user')->createNew();
        self::assertInstanceOf(AdminUserInterface::class, $administrator);
        $administrator->setUsername(self::ADMIN_EMAIL);
        $administrator->setEmail(self::ADMIN_EMAIL);
        $administrator->setPassword('not-used');
        $administrator->setLocaleCode(self::LOCALE);
        $administrator->setEnabled(true);

        $this->entityManager()->persist($administrator);
        $this->entityManager()->flush();

        return $administrator;
    }

    protected function createChannel(string $code = self::CHANNEL_CODE, ?string $hostname = null): ChannelInterface
    {
        $entityManager = $this->entityManager();

        $locale = $entityManager->getRepository(LocaleInterface::class)->findOneBy(['code' => self::LOCALE]);
        if (!$locale instanceof LocaleInterface) {
            $locale = $this->factory('sylius.factory.locale')->createNew();
            self::assertInstanceOf(LocaleInterface::class, $locale);
            $locale->setCode(self::LOCALE);
            $entityManager->persist($locale);
        }

        $currency = $entityManager->getRepository(CurrencyInterface::class)->findOneBy(['code' => 'USD']);
        if (!$currency instanceof CurrencyInterface) {
            $currency = $this->factory('sylius.factory.currency')->createNew();
            self::assertInstanceOf(CurrencyInterface::class, $currency);
            $currency->setCode('USD');
            $entityManager->persist($currency);
        }

        $channel = $this->factory('sylius.factory.channel')->createNew();
        self::assertInstanceOf(ChannelInterface::class, $channel);
        $channel->setCode($code);
        $channel->setName('Agent001 ' . $code);
        $channel->setHostname($hostname);
        $channel->setEnabled(true);
        $channel->setBaseCurrency($currency);
        $channel->addCurrency($currency);
        $channel->setDefaultLocale($locale);
        $channel->addLocale($locale);
        $entityManager->persist($channel);
        $entityManager->flush();

        return $channel;
    }

    protected function connectStore(string $site = 'http://localhost'): Agent001Connection
    {
        $connection = new Agent001Connection();
        $connection->connect(FakeAgent001Backend::API_KEY, 'Test account', $site);
        $this->entityManager()->persist($connection);
        $this->entityManager()->flush();

        return $connection;
    }

    protected function createSetting(
        string $channelCode = self::CHANNEL_CODE,
        string $agentId = self::AGENT_ID,
        bool $active = true,
        bool $setupCompleted = true,
        string $site = 'http://localhost',
    ): Agent001Setting {
        $setting = new Agent001Setting($channelCode);
        $setting->assignAgent($agentId, 'embed-token-' . $channelCode, $active, $site);
        $setting->setSetupCompleted($setupCompleted);
        $this->entityManager()->persist($setting);
        $this->entityManager()->flush();

        return $setting;
    }

    protected function createOrder(ChannelInterface $channel, string $number, string $email): OrderInterface
    {
        $customer = $this->factory('sylius.factory.customer')->createNew();
        self::assertInstanceOf(CustomerInterface::class, $customer);
        $customer->setEmail($email);
        $customer->setEmailCanonical(strtolower($email));
        $customer->setFirstName('Jane');
        $customer->setLastName('Secret');

        $order = $this->factory('sylius.factory.order')->createNew();
        self::assertInstanceOf(OrderInterface::class, $order);
        $order->setNumber($number);
        $order->setChannel($channel);
        $order->setCurrencyCode('USD');
        $order->setLocaleCode(self::LOCALE);
        $order->setCustomer($customer);
        $order->setState(OrderInterface::STATE_NEW);

        $this->entityManager()->persist($customer);
        $this->entityManager()->persist($order);
        $this->entityManager()->flush();

        return $order;
    }

    protected function setting(string $channelCode = self::CHANNEL_CODE): ?Agent001Setting
    {
        $this->entityManager()->clear();

        return $this->entityManager()
            ->getRepository(Agent001Setting::class)
            ->findOneBy(['channelCode' => $channelCode]);
    }

    protected function storedApiKey(): ?string
    {
        $apiKey = $this->entityManager()
            ->getConnection()
            ->fetchOne('SELECT api_key FROM agent001_connection ORDER BY id ASC LIMIT 1');

        return is_string($apiKey) ? $apiKey : null;
    }

    protected function assertNoStore(KernelBrowser $client): void
    {
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
    }

    protected function csrfToken(KernelBrowser $client, string $tokenId): string
    {
        $sessionFactory = $client->getContainer()->get('session.factory');
        self::assertInstanceOf(SessionFactoryInterface::class, $sessionFactory);

        $session = $sessionFactory->createSession();
        $cookie = $client->getCookieJar()->get($session->getName());
        self::assertNotNull($cookie, 'Log the client in before asking for a CSRF token.');

        $token = bin2hex(random_bytes(16));
        $session->setId($cookie->getValue());
        $session->start();
        $session->set('_csrf/' . $tokenId, $token);
        $session->save();

        return $token;
    }

    /**
     * @return FactoryInterface<object>
     */
    protected function factory(string $id): FactoryInterface
    {
        $factory = static::getContainer()->get($id);
        self::assertInstanceOf(FactoryInterface::class, $factory);

        return $factory;
    }

    protected function purge(): void
    {
        $entityManager = $this->entityManager();
        $entityManager->clear();
        $entityManager->createQuery('DELETE FROM ' . Agent001Setting::class . ' s')->execute();
        $entityManager->createQuery('DELETE FROM ' . Agent001Connection::class . ' c')->execute();

        $this->removeMatching(OrderInterface::class, 'number', self::ORDER_PREFIX . '%');
        $this->removeMatching(CustomerInterface::class, 'email', '%' . self::CUSTOMER_DOMAIN);
        $this->removeMatching(ProductInterface::class, 'code', self::PRODUCT_PREFIX . '%');
        $this->removeMatching(ChannelInterface::class, 'code', self::CHANNEL_CODE . '%');
        $roots = $entityManager->getRepository(TaxonInterface::class)->createQueryBuilder('t')
            ->andWhere('t.parent IS NULL')
            ->andWhere('t.code LIKE :pattern')
            ->setParameter('pattern', self::PRODUCT_PREFIX . '%')
            ->getQuery()
            ->getResult();
        foreach ($roots as $root) {
            $entityManager->remove($root);
        }
        $entityManager->flush();
        $this->removeMatching(AdminUserInterface::class, 'email', self::ADMIN_EMAIL);

        $cache = static::getContainer()->get('cache.app');
        self::assertInstanceOf(CacheItemPoolInterface::class, $cache);
        $cache->clear();
    }

    /**
     * @param class-string $class
     */
    private function removeMatching(string $class, string $field, string $pattern): void
    {
        $entityManager = $this->entityManager();
        $entities = $entityManager->getRepository($class)->createQueryBuilder('e')
            ->andWhere(sprintf('e.%s LIKE :pattern', $field))
            ->setParameter('pattern', $pattern)
            ->getQuery()
            ->getResult();
        foreach ($entities as $entity) {
            self::assertIsObject($entity);
            $entityManager->remove($entity);
        }

        $entityManager->flush();
        $entityManager->clear();
    }
}
