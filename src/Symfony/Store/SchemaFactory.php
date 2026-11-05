<?php

declare(strict_types=1);

namespace Whistleblower\Symfony\Store;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

/** The tables behind DbalReportStore. Personal fields hold SodiumCipher output, never plain text. */
// shortcut: Schema::createTable() is deprecated since DBAL 4.5 (use Schema::edit()); kept so DBAL 4.0-4.4 still work, revisit when the minimum becomes 4.5.
final class SchemaFactory
{
    public static function create(): Schema
    {
        $schema = new Schema;

        $reports = $schema->createTable('whistleblower_reports');
        $reports->addColumn('id', Types::STRING, ['length' => 26]);
        $reports->addColumn('tracking_code_hash', Types::STRING, ['length' => 64]);
        $reports->addColumn('status', Types::STRING, ['length' => 20]);
        $reports->addColumn('category', Types::STRING, ['length' => 255, 'notnull' => false]);
        foreach (['subject', 'description', 'reporter_name', 'reporter_email', 'resolution'] as $column) {
            $reports->addColumn($column, Types::TEXT, ['notnull' => false]);
        }
        $reports->addColumn('anonymous', Types::BOOLEAN);
        foreach (['received_at', 'acknowledgement_due_at', 'resolution_due_at'] as $column) {
            $reports->addColumn($column, Types::DATETIME_IMMUTABLE);
        }
        foreach (['acknowledged_at', 'extended_at', 'investigation_started_at', 'resolved_at', 'archived_at', 'anonymized_at'] as $column) {
            $reports->addColumn($column, Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        }
        $reports->setPrimaryKey(['id']);
        $reports->addUniqueIndex(['tracking_code_hash'], 'uniq_wb_reports_tracking_code_hash');
        $reports->addIndex(['status'], 'idx_wb_reports_status');

        $messages = $schema->createTable('whistleblower_messages');
        $messages->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $messages->addColumn('report_id', Types::STRING, ['length' => 26]);
        $messages->addColumn('author', Types::STRING, ['length' => 10]);
        $messages->addColumn('user_id', Types::STRING, ['length' => 255, 'notnull' => false]);
        $messages->addColumn('body', Types::TEXT);
        $messages->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $messages->setPrimaryKey(['id']);
        $messages->addIndex(['report_id'], 'idx_wb_messages_report_id');

        $audit = $schema->createTable('whistleblower_audit_logs');
        $audit->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $audit->addColumn('report_id', Types::STRING, ['length' => 26]);
        $audit->addColumn('actor_type', Types::STRING, ['length' => 10]);
        $audit->addColumn('actor_id', Types::STRING, ['length' => 255, 'notnull' => false]);
        $audit->addColumn('action', Types::STRING, ['length' => 40]);
        $audit->addColumn('context', Types::TEXT, ['notnull' => false]);
        $audit->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $audit->setPrimaryKey(['id']);
        $audit->addIndex(['report_id'], 'idx_wb_audit_report_id');

        return $schema;
    }
}
