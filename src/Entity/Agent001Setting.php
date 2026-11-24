<?php

declare(strict_types=1);

namespace Agent001\SyliusAiAssistantPlugin\Entity;

use Agent001\SyliusAiAssistantPlugin\Agent\AgentId;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'agent001_setting')]
#[ORM\UniqueConstraint(name: 'uniq_agent001_setting_channel_code', columns: ['channel_code'])]
class Agent001Setting
{
    public const CATALOG_PREFIX = 'channel:';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_READING = 'reading';

    public const STATUS_INDEXING = 'indexing';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const RUNNING = [self::STATUS_QUEUED, self::STATUS_READING, self::STATUS_INDEXING];

    public const STALE_AFTER_SECONDS = 1800;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(name: 'channel_code', type: Types::STRING, length: 255)]
    private string $channelCode;

    #[ORM\Column(name: 'widget_key', type: Types::STRING, length: 64)]
    private string $widgetKey;

    #[ORM\Column(name: 'site', type: Types::STRING, length: 255, nullable: true)]
    private ?string $site = null;

    #[ORM\Column(name: 'agent_id', type: Types::STRING, length: 36, nullable: true)]
    private ?string $agentId = null;

    #[ORM\Column(name: 'embed_token', type: Types::STRING, length: 255, nullable: true)]
    private ?string $embedToken = null;

    #[ORM\Column(name: 'agent_active', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $agentActive = true;

    /** @var list<string> */
    #[ORM\Column(name: 'taxon_codes', type: Types::JSON)]
    private array $taxonCodes = [];

    #[ORM\Column(name: 'setup_completed', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $setupCompleted = false;

    #[ORM\Column(name: 'sync_status', type: Types::STRING, length: 16, nullable: true)]
    private ?string $syncStatus = null;

    #[ORM\Column(name: 'sync_message', type: Types::TEXT, nullable: true)]
    private ?string $syncMessage = null;

    /** @var array<string, int|string>|null */
    #[ORM\Column(name: 'sync_message_parameters', type: Types::JSON, nullable: true)]
    private ?array $syncMessageParameters = null;

    #[ORM\Column(name: 'sync_count', type: Types::INTEGER, options: ['default' => 0])]
    private int $syncCount = 0;

    #[ORM\Column(name: 'sync_session_id', type: Types::STRING, length: 32, nullable: true)]
    private ?string $syncSessionId = null;

    #[ORM\Column(name: 'sync_updated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $syncUpdatedAt = null;

    #[ORM\Column(name: 'last_sync_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastSyncAt = null;

    public function __construct(string $channelCode)
    {
        $this->channelCode = $channelCode;
        $this->widgetKey = bin2hex(random_bytes(32));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getChannelCode(): string
    {
        return $this->channelCode;
    }

    public function getCatalogChannel(): string
    {
        return self::CATALOG_PREFIX . $this->channelCode;
    }

    public function getWidgetKey(): string
    {
        return $this->widgetKey;
    }

    public function getSite(): ?string
    {
        return $this->site;
    }

    public function getAgentId(): ?string
    {
        return $this->agentId;
    }

    public function hasAgent(): bool
    {
        return null !== $this->agentId;
    }

    public function getEmbedToken(): ?string
    {
        return $this->embedToken;
    }

    public function isAgentActive(): bool
    {
        return $this->agentActive;
    }

    public function setAgentActive(bool $agentActive): void
    {
        $this->agentActive = $agentActive;
    }

    public function assignAgent(string $agentId, string $embedToken, bool $active, string $site): void
    {
        $this->agentId = AgentId::normalize($agentId) ?: null;
        $this->embedToken = $embedToken;
        $this->agentActive = $active;
        $this->site = $site;
        $this->setupCompleted = false;
        $this->syncStatus = null;
        $this->syncMessage = null;
        $this->syncMessageParameters = null;
        $this->syncCount = 0;
        $this->syncSessionId = null;
    }

    public function isWidgetVisible(): bool
    {
        return $this->hasAgent() && $this->setupCompleted && $this->agentActive && '' !== (string) $this->embedToken;
    }

    /**
     * @return list<string>
     */
    public function getTaxonCodes(): array
    {
        return $this->taxonCodes;
    }

    /**
     * @param array<mixed> $taxonCodes
     */
    public function setTaxonCodes(array $taxonCodes): void
    {
        $codes = array_filter($taxonCodes, static fn (mixed $code): bool => is_string($code) && '' !== $code);
        $this->taxonCodes = array_values(array_unique($codes));
    }

    public function isSetupCompleted(): bool
    {
        return $this->setupCompleted;
    }

    public function setSetupCompleted(bool $setupCompleted): void
    {
        $this->setupCompleted = $setupCompleted;
    }

    public function getSyncStatus(): ?string
    {
        return $this->syncStatus;
    }

    public function isSyncRunning(): bool
    {
        return in_array($this->syncStatus, self::RUNNING, true);
    }

    public function getSyncMessage(): ?string
    {
        return $this->syncMessage;
    }

    /**
     * @return array<string, int|string>
     */
    public function getSyncMessageParameters(): array
    {
        return $this->syncMessageParameters ?? [];
    }

    public function getSyncCount(): int
    {
        return $this->syncCount;
    }

    public function getSyncSessionId(): ?string
    {
        return $this->syncSessionId;
    }

    public function getSyncUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->syncUpdatedAt;
    }

    public function isSyncStale(\DateTimeImmutable $now): bool
    {
        return null === $this->syncUpdatedAt ||
            $now->getTimestamp() - $this->syncUpdatedAt->getTimestamp() > self::STALE_AFTER_SECONDS;
    }

    public function getLastSyncAt(): ?\DateTimeImmutable
    {
        return $this->lastSyncAt;
    }

    public function setLastSyncAt(?\DateTimeImmutable $lastSyncAt): void
    {
        $this->lastSyncAt = $lastSyncAt;
    }

    public function queueSync(): void
    {
        $this->syncStatus = self::STATUS_QUEUED;
        $this->syncMessage = null;
        $this->syncMessageParameters = null;
        $this->syncUpdatedAt = new \DateTimeImmutable();
    }

    /**
     * @param array<string, int|string> $parameters
     */
    public function reportSync(string $status, string $message, int $count, array $parameters = []): void
    {
        $this->syncStatus = $status;
        $this->syncMessage = $message;
        $this->syncMessageParameters = $parameters ?: null;
        $this->syncCount = $count;
        $this->syncUpdatedAt = new \DateTimeImmutable();
    }

    /**
     * @param array<string, int|string> $parameters
     */
    public function finishSync(
        bool $success,
        string $message,
        \DateTimeImmutable $now,
        array $parameters = [],
    ): void {
        $this->syncStatus = $success ? self::STATUS_SUCCESS : self::STATUS_FAILED;
        $this->syncMessage = $message;
        $this->syncMessageParameters = $parameters ?: null;
        $this->lastSyncAt = $now;
        if ($success) {
            $this->setupCompleted = true;
        }
    }
}
