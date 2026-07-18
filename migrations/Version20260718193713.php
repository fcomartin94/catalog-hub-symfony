<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260718193713 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE price_list (id INT AUTO_INCREMENT NOT NULL, price NUMERIC(10, 2) NOT NULL, product_id INT NOT NULL, channel_id INT NOT NULL, INDEX IDX_399A0AA24584665A (product_id), INDEX IDX_399A0AA272F5A1AA (channel_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE store_channel (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, platform VARCHAR(30) NOT NULL, base_url VARCHAR(255) NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE price_list ADD CONSTRAINT FK_399A0AA24584665A FOREIGN KEY (product_id) REFERENCES product (id)');
        $this->addSql('ALTER TABLE price_list ADD CONSTRAINT FK_399A0AA272F5A1AA FOREIGN KEY (channel_id) REFERENCES store_channel (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE price_list DROP FOREIGN KEY FK_399A0AA24584665A');
        $this->addSql('ALTER TABLE price_list DROP FOREIGN KEY FK_399A0AA272F5A1AA');
        $this->addSql('DROP TABLE price_list');
        $this->addSql('DROP TABLE store_channel');
    }
}
