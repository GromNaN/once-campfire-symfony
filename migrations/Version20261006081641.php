<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the schema shared with the original Rails application.
 *
 * Table names, column names, nullability, defaults, indexes and foreign keys
 * mirror db/schema.rb so the same SQLite file can be opened by either
 * application. The message_search_index table is a SQLite FTS5 virtual table
 * with the porter tokenizer, exactly as declared in the Rails schema.
 */
final class Version20261006081641 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the Campfire schema shared with the Rails application.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE accounts (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            created_at datetime NOT NULL,
            custom_styles text DEFAULT NULL,
            join_code varchar NOT NULL,
            name varchar NOT NULL,
            settings json DEFAULT NULL,
            singleton_guard integer DEFAULT 0 NOT NULL,
            updated_at datetime NOT NULL
        )');
        $this->addSql('CREATE UNIQUE INDEX index_accounts_on_singleton_guard ON accounts (singleton_guard)');

        $this->addSql('CREATE TABLE action_text_rich_texts (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            body text DEFAULT NULL,
            created_at datetime NOT NULL,
            name varchar NOT NULL,
            record_id bigint NOT NULL,
            record_type varchar NOT NULL,
            updated_at datetime NOT NULL
        )');
        $this->addSql('CREATE UNIQUE INDEX index_action_text_rich_texts_uniqueness ON action_text_rich_texts (record_type, record_id, name)');

        $this->addSql('CREATE TABLE active_storage_attachments (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            blob_id bigint NOT NULL,
            created_at datetime NOT NULL,
            name varchar NOT NULL,
            record_id bigint NOT NULL,
            record_type varchar NOT NULL,
            CONSTRAINT fk_rails_c3b3935057 FOREIGN KEY (blob_id) REFERENCES active_storage_blobs (id)
        )');
        $this->addSql('CREATE INDEX index_active_storage_attachments_on_blob_id ON active_storage_attachments (blob_id)');
        $this->addSql('CREATE UNIQUE INDEX index_active_storage_attachments_uniqueness ON active_storage_attachments (record_type, record_id, name, blob_id)');

        $this->addSql('CREATE TABLE active_storage_blobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            byte_size bigint NOT NULL,
            checksum varchar DEFAULT NULL,
            content_type varchar DEFAULT NULL,
            created_at datetime NOT NULL,
            filename varchar NOT NULL,
            "key" varchar NOT NULL,
            metadata text DEFAULT NULL,
            service_name varchar NOT NULL
        )');
        $this->addSql('CREATE UNIQUE INDEX index_active_storage_blobs_on_key ON active_storage_blobs ("key")');

        // Rails' active_storage_variant_records has no variant_blob_id column:
        // it serves a variant from a key derived from the source blob, so there
        // is nothing to store. This port materialises the variant as a blob of
        // its own, so the record needs its own link to that blob, both to find
        // it on a cache hit and to purge it.
        $this->addSql('CREATE TABLE active_storage_variant_records (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            blob_id bigint NOT NULL,
            variant_blob_id bigint DEFAULT NULL,
            variation_digest varchar NOT NULL,
            CONSTRAINT fk_rails_993965df05 FOREIGN KEY (blob_id) REFERENCES active_storage_blobs (id),
            CONSTRAINT fk_active_storage_variant_records_variant_blob FOREIGN KEY (variant_blob_id) REFERENCES active_storage_blobs (id)
        )');
        $this->addSql('CREATE UNIQUE INDEX index_active_storage_variant_records_uniqueness ON active_storage_variant_records (blob_id, variation_digest)');

        $this->addSql('CREATE TABLE bans (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            created_at datetime NOT NULL,
            ip_address varchar NOT NULL,
            updated_at datetime NOT NULL,
            user_id integer NOT NULL,
            CONSTRAINT fk_rails_6f5f9b0a3f FOREIGN KEY (user_id) REFERENCES users (id)
        )');
        $this->addSql('CREATE INDEX index_bans_on_ip_address ON bans (ip_address)');
        $this->addSql('CREATE INDEX index_bans_on_user_id ON bans (user_id)');

        $this->addSql('CREATE TABLE boosts (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            booster_id integer NOT NULL,
            content varchar(16) NOT NULL,
            created_at datetime NOT NULL,
            message_id integer NOT NULL,
            updated_at datetime NOT NULL,
            CONSTRAINT fk_rails_0e0e0d9b5f FOREIGN KEY (message_id) REFERENCES messages (id)
        )');
        $this->addSql('CREATE INDEX index_boosts_on_booster_id ON boosts (booster_id)');
        $this->addSql('CREATE INDEX index_boosts_on_message_id ON boosts (message_id)');

        $this->addSql('CREATE TABLE memberships (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            connected_at datetime DEFAULT NULL,
            connections integer DEFAULT 0 NOT NULL,
            created_at datetime NOT NULL,
            involvement varchar DEFAULT \'mentions\',
            room_id integer NOT NULL,
            unread_at datetime DEFAULT NULL,
            updated_at datetime NOT NULL,
            user_id integer NOT NULL
        )');
        $this->addSql('CREATE INDEX index_memberships_on_room_id_and_created_at ON memberships (room_id, created_at)');
        $this->addSql('CREATE UNIQUE INDEX index_memberships_on_room_id_and_user_id ON memberships (room_id, user_id)');
        $this->addSql('CREATE INDEX index_memberships_on_room_id ON memberships (room_id)');
        $this->addSql('CREATE INDEX index_memberships_on_user_id ON memberships (user_id)');

        // messages.creator_id is an integer in the Rails schema too, so it
        // matches the Doctrine mapping, which points it at User (id integer).
        $this->addSql('CREATE TABLE messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            client_message_id varchar NOT NULL,
            created_at datetime NOT NULL,
            creator_id integer NOT NULL,
            room_id integer NOT NULL,
            updated_at datetime NOT NULL,
            CONSTRAINT fk_rails_0f6f0c1a1e FOREIGN KEY (room_id) REFERENCES rooms (id),
            CONSTRAINT fk_rails_9d9f0b4c1f FOREIGN KEY (creator_id) REFERENCES users (id)
        )');
        $this->addSql('CREATE INDEX index_messages_on_creator_id ON messages (creator_id)');
        $this->addSql('CREATE INDEX index_messages_on_room_id_and_created_at ON messages (room_id, created_at)');
        $this->addSql('CREATE INDEX index_messages_on_room_id ON messages (room_id)');

        $this->addSql('CREATE TABLE push_subscriptions (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            auth_key varchar DEFAULT NULL,
            created_at datetime NOT NULL,
            endpoint varchar DEFAULT NULL,
            p256dh_key varchar DEFAULT NULL,
            updated_at datetime NOT NULL,
            user_agent varchar DEFAULT NULL,
            user_id integer NOT NULL,
            CONSTRAINT fk_rails_4a2a4a5f8f FOREIGN KEY (user_id) REFERENCES users (id)
        )');
        $this->addSql('CREATE INDEX idx_on_endpoint_p256dh_key_auth_key_7553014576 ON push_subscriptions (endpoint, p256dh_key, auth_key)');
        $this->addSql('CREATE INDEX index_push_subscriptions_on_user_id ON push_subscriptions (user_id)');

        // Rails declares rooms.creator_id as bigint, but the Doctrine mapping
        // points it at User, whose id is integer, so Doctrine expects integer
        // here and doctrine:schema:validate would flag a bigint. SQLite is
        // dynamically typed, so keeping integer on both sides has no runtime
        // effect and keeps the schema in sync with the mapping.
        $this->addSql('CREATE TABLE rooms (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            created_at datetime NOT NULL,
            creator_id integer NOT NULL,
            name varchar DEFAULT NULL,
            type varchar NOT NULL,
            updated_at datetime NOT NULL
        )');

        $this->addSql('CREATE TABLE searches (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            created_at datetime NOT NULL,
            "query" varchar NOT NULL,
            updated_at datetime NOT NULL,
            user_id integer NOT NULL,
            CONSTRAINT fk_rails_4b2c2c9e8f FOREIGN KEY (user_id) REFERENCES users (id)
        )');
        $this->addSql('CREATE INDEX index_searches_on_user_id ON searches (user_id)');

        $this->addSql('CREATE TABLE sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            created_at datetime NOT NULL,
            ip_address varchar DEFAULT NULL,
            last_active_at datetime NOT NULL,
            token varchar NOT NULL,
            updated_at datetime NOT NULL,
            user_agent varchar DEFAULT NULL,
            user_id integer NOT NULL,
            CONSTRAINT fk_rails_758836b4f0 FOREIGN KEY (user_id) REFERENCES users (id)
        )');
        $this->addSql('CREATE UNIQUE INDEX index_sessions_on_token ON sessions (token)');
        $this->addSql('CREATE INDEX index_sessions_on_user_id ON sessions (user_id)');

        $this->addSql('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            bio text DEFAULT NULL,
            bot_token varchar DEFAULT NULL,
            created_at datetime NOT NULL,
            email_address varchar DEFAULT NULL,
            name varchar NOT NULL,
            password_digest varchar DEFAULT NULL,
            role integer DEFAULT 0 NOT NULL,
            status integer DEFAULT 0 NOT NULL,
            updated_at datetime NOT NULL
        )');
        $this->addSql('CREATE UNIQUE INDEX index_users_on_bot_token ON users (bot_token)');
        $this->addSql('CREATE UNIQUE INDEX index_users_on_email_address ON users (email_address)');

        $this->addSql('CREATE TABLE webhooks (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            url varchar DEFAULT NULL,
            user_id integer NOT NULL,
            CONSTRAINT fk_rails_2e5d5a2a1f FOREIGN KEY (user_id) REFERENCES users (id)
        )');
        $this->addSql('CREATE INDEX index_webhooks_on_user_id ON webhooks (user_id)');

        // Full text search index. The rowid of each row is the message id.
        $this->addSql('CREATE VIRTUAL TABLE message_search_index USING fts5(body, tokenize=porter)');

        // Queue used by Messenger for webhook delivery and push notifications.
        $this->addSql('CREATE TABLE messenger_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            body clob NOT NULL,
            headers clob NOT NULL,
            queue_name varchar(190) NOT NULL,
            created_at datetime NOT NULL,
            available_at datetime NOT NULL,
            delivered_at datetime DEFAULT NULL
        )');
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (queue_name, available_at, delivered_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE message_search_index');
        $this->addSql('DROP TABLE messenger_messages');
        $this->addSql('DROP TABLE webhooks');
        $this->addSql('DROP TABLE sessions');
        $this->addSql('DROP TABLE searches');
        $this->addSql('DROP TABLE rooms');
        $this->addSql('DROP TABLE push_subscriptions');
        $this->addSql('DROP TABLE messages');
        $this->addSql('DROP TABLE memberships');
        $this->addSql('DROP TABLE boosts');
        $this->addSql('DROP TABLE bans');
        $this->addSql('DROP TABLE users');
        $this->addSql('DROP TABLE active_storage_variant_records');
        $this->addSql('DROP TABLE active_storage_blobs');
        $this->addSql('DROP TABLE active_storage_attachments');
        $this->addSql('DROP TABLE action_text_rich_texts');
        $this->addSql('DROP TABLE accounts');
    }
}
