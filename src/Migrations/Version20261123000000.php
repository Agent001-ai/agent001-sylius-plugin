<?php

declare(strict_types=1);

namespace Agent001\SyliusAiAssistantPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261123000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stores the parameters of the translatable synchronization message of Agent001 channel settings.';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('agent001_setting')->addColumn('sync_message_parameters', 'json', ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('agent001_setting')->dropColumn('sync_message_parameters');
    }
}
