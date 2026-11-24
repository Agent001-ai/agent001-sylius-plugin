<?php

declare(strict_types=1);

namespace Agent001\SyliusAiAssistantPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260922000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates Agent001 plugin settings.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('agent001_setting');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('channel_code', 'string', ['length' => 64, 'notnull' => false]);
        $table->addColumn('api_key', 'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn('widget_key', 'string', ['length' => 64, 'notnull' => false]);
        $table->addColumn('connected_site', 'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn('sync_taxon_codes', 'json');
        $table->addColumn('selected_agent_ids', 'json');
        $table->addColumn('selected_agent_embed_token', 'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn('last_sync_at', 'datetime_immutable', ['notnull' => false]);
        $table->addColumn('last_sync_status', 'string', ['length' => 32, 'notnull' => false]);
        $table->addColumn('last_sync_message', 'text', ['notnull' => false]);
        $table->addColumn('last_sync_count', 'integer', ['default' => 0]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['channel_code'], 'uniq_agent001_setting_channel_code');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('agent001_setting');
    }
}
