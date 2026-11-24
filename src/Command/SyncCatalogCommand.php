<?php

declare(strict_types=1);

namespace Agent001\SyliusAiAssistantPlugin\Command;

use Agent001\SyliusAiAssistantPlugin\Message\SyncCatalogMessage;
use Agent001\SyliusAiAssistantPlugin\Repository\Agent001ConnectionRepository;
use Agent001\SyliusAiAssistantPlugin\Repository\Agent001SettingRepository;
use Agent001\SyliusAiAssistantPlugin\Sync\NightlySyncPolicy;
use Agent001\SyliusAiAssistantPlugin\Sync\Synchronizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'agent001:sync',
    description: 'Synchronizes the Sylius product catalog of each channel with Agent001.',
)]
final class SyncCatalogCommand extends Command
{
    public function __construct(
        private readonly Agent001ConnectionRepository $connectionRepository,
        private readonly Agent001SettingRepository $settingRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly Synchronizer $synchronizer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('channel', 'c', InputOption::VALUE_REQUIRED, 'Channel code to synchronize')
            ->addOption(
                'due',
                null,
                InputOption::VALUE_NONE,
                'Run only inside the channel nightly window and at most once a day',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('' === $this->connectionRepository->apiKey()) {
            $io->error('The store is not connected to Agent001.');

            return Command::FAILURE;
        }

        $channelCode = $input->getOption('channel');
        $due = (bool) $input->getOption('due');
        $now = new \DateTimeImmutable();
        $queued = [];

        foreach ($this->settingRepository->findWithAgent() as $setting) {
            if (null !== $channelCode && $channelCode !== $setting->getChannelCode()) {
                continue;
            }
            $this->synchronizer->refresh($setting);
            if ($due && !NightlySyncPolicy::isDue($setting, $now)) {
                continue;
            }
            if ($setting->isSyncRunning()) {
                $io->writeln(sprintf('Channel %s is already synchronizing.', $setting->getChannelCode()));

                continue;
            }

            $setting->queueSync();
            $queued[] = $setting->getChannelCode();
        }

        $this->entityManager->flush();
        foreach ($queued as $code) {
            $this->messageBus->dispatch(new SyncCatalogMessage($code));
            $io->writeln(sprintf('Queued synchronization of channel %s.', $code));
        }

        if ([] === $queued) {
            $io->writeln($due ? 'Nightly synchronization is not due.' : 'Nothing to synchronize.');
        }

        return Command::SUCCESS;
    }
}
