<?php

declare(strict_types=1);

namespace Agent001\SyliusAiAssistantPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261023000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds agent status, account name and sync session to Agent001 plugin settings.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('agent001_setting');
        $table->addColumn('selected_agent_active', 'boolean', ['default' => true]);
        $table->addColumn('setup_completed', 'boolean', ['default' => false]);
        $table->addColumn('account_name', 'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn('last_sync_session_id', 'string', ['length' => 64, 'notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('agent001_setting');
        $table->dropColumn('selected_agent_active');
        $table->dropColumn('setup_completed');
        $table->dropColumn('account_name');
        $table->dropColumn('last_sync_session_id');
    }
}
