<?php

declare(strict_types=1);

namespace Agent001\SyliusAiAssistantPlugin\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'agent001_connection')]
class Agent001Connection
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(name: 'api_key', type: Types::STRING, length: 255, nullable: true)]
    private ?string $apiKey = null;

    #[ORM\Column(name: 'account_name', type: Types::STRING, length: 255, nullable: true)]
    private ?string $accountName = null;

    #[ORM\Column(name: 'site', type: Types::STRING, length: 255, nullable: true)]
    private ?string $site = null;

    #[ORM\Column(name: 'connect_revision', type: Types::STRING, length: 32, nullable: true)]
    private ?string $connectRevision = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getApiKey(): ?string
    {
        return $this->apiKey;
    }

    public function isConnected(): bool
    {
        return null !== $this->apiKey && '' !== trim($this->apiKey);
    }

    public function connect(string $apiKey, ?string $accountName, string $site): void
    {
        $this->apiKey = $apiKey;
        $this->accountName = '' === (string) $accountName ? null : $accountName;
        $this->site = $site;
    }

    public function disconnect(): void
    {
        $this->apiKey = null;
        $this->accountName = null;
        $this->site = null;
        $this->connectRevision = null;
    }

    public function getAccountName(): ?string
    {
        return $this->accountName;
    }

    public function setAccountName(?string $accountName): void
    {
        $this->accountName = $accountName;
    }

    public function getSite(): ?string
    {
        return $this->site;
    }

    public function getConnectRevision(): ?string
    {
        return $this->connectRevision;
    }

    public function rotateConnectRevision(): string
    {
        return $this->connectRevision = bin2hex(random_bytes(16));
    }
}
