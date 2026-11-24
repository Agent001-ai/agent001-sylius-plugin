<?php

declare(strict_types=1);

namespace Agent001\SyliusAiAssistantPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Splits the Agent001 connection from per-channel settings.';
    }

    public function up(Schema $schema): void
    {
        $target = new Schema([], [], $this->sm->createSchemaConfig());

        $connection = $target->createTable('agent001_connection');
        $connection->addColumn('id', 'integer', ['autoincrement' => true]);
        $connection->addColumn('api_key', 'string', ['length' => 255, 'notnull' => false]);
        $connection->addColumn('account_name', 'string', ['length' => 255, 'notnull' => false]);
        $connection->addColumn('site', 'string', ['length' => 255, 'notnull' => false]);
        $connection->addColumn('connect_revision', 'string', ['length' => 32, 'notnull' => false]);
        $connection->setPrimaryKey(['id']);

        $setting = $target->createTable('agent001_setting');
        $setting->addColumn('id', 'integer', ['autoincrement' => true]);
        $setting->addColumn('channel_code', 'string', ['length' => 255]);
        $setting->addColumn('widget_key', 'string', ['length' => 64]);
        $setting->addColumn('site', 'string', ['length' => 255, 'notnull' => false]);
        $setting->addColumn('agent_id', 'string', ['length' => 36, 'notnull' => false]);
        $setting->addColumn('embed_token', 'string', ['length' => 255, 'notnull' => false]);
        $setting->addColumn('agent_active', 'boolean', ['default' => true]);
        $setting->addColumn('taxon_codes', 'json');
        $setting->addColumn('setup_completed', 'boolean', ['default' => false]);
        $setting->addColumn('sync_status', 'string', ['length' => 16, 'notnull' => false]);
        $setting->addColumn('sync_message', 'text', ['notnull' => false]);
        $setting->addColumn('sync_count', 'integer', ['default' => 0]);
        $setting->addColumn('sync_session_id', 'string', ['length' => 32, 'notnull' => false]);
        $setting->addColumn('sync_updated_at', 'datetime_immutable', ['notnull' => false]);
        $setting->addColumn('last_sync_at', 'datetime_immutable', ['notnull' => false]);
        $setting->setPrimaryKey(['id']);
        $setting->addUniqueIndex(['channel_code'], 'uniq_agent001_setting_channel_code');

        $this->create($connection);
        $this->addSql(
            'INSERT INTO agent001_connection (api_key, account_name, site)'
            . ' SELECT api_key, account_name, connected_site FROM agent001_setting'
            . " WHERE id = (SELECT MIN(id) FROM agent001_setting WHERE api_key IS NOT NULL AND api_key <> '')",
        );
        $this->addSql($this->platform->getDropTableSQL('agent001_setting'));
        $this->create($setting);
    }

    public function down(Schema $schema): void
    {
        $target = new Schema([], [], $this->sm->createSchemaConfig());

        $table = $target->createTable('agent001_setting');
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
        $table->addColumn('selected_agent_active', 'boolean', ['default' => true]);
        $table->addColumn('setup_completed', 'boolean', ['default' => false]);
        $table->addColumn('account_name', 'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn('last_sync_session_id', 'string', ['length' => 64, 'notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['channel_code'], 'uniq_agent001_setting_channel_code');

        $this->addSql($this->platform->getDropTableSQL('agent001_connection'));
        $this->addSql($this->platform->getDropTableSQL('agent001_setting'));
        $this->create($table);
    }

    private function create(Table $table): void
    {
        foreach ($this->platform->getCreateTableSQL($table) as $sql) {
            $this->addSql($sql);
        }
    }
}
