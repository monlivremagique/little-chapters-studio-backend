<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260422161000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Harden personalization photo storage with private paths, access tokens, metadata and deletion lifecycle.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_uploaded_photo ADD storage_path VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE app_uploaded_photo ADD access_token VARCHAR(128) DEFAULT NULL');
        $this->addSql('ALTER TABLE app_uploaded_photo ADD image_width INT DEFAULT NULL');
        $this->addSql('ALTER TABLE app_uploaded_photo ADD image_height INT DEFAULT NULL');
        $this->addSql('ALTER TABLE app_uploaded_photo ADD sha256_checksum VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE app_uploaded_photo ADD deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');

        $projectDir = dirname(__DIR__);
        $legacyDirectory = $projectDir . '/public/uploads/personalizations';
        $privateDirectory = $projectDir . '/var/storage/personalizations/photos';

        if (!is_dir($privateDirectory)) {
            mkdir($privateDirectory, 0775, true);
        }

        $rows = $this->connection->fetchAllAssociative('SELECT id, stored_filename, public_path FROM app_uploaded_photo');

        foreach ($rows as $row) {
            $id = (string) $row['id'];
            $storedFilename = (string) $row['stored_filename'];
            $publicPath = (string) ($row['public_path'] ?? '');
            $legacyPath = $legacyDirectory . '/' . $storedFilename;
            $targetPath = $privateDirectory . '/' . $storedFilename;
            $storagePath = 'var/storage/personalizations/photos/' . $storedFilename;
            $accessToken = strtolower(bin2hex(random_bytes(24)));
            $width = null;
            $height = null;
            $checksum = null;

            if (is_file($legacyPath) && !is_file($targetPath)) {
                @rename($legacyPath, $targetPath);

                if (is_file($legacyPath) && !is_file($targetPath)) {
                    copy($legacyPath, $targetPath);
                    @unlink($legacyPath);
                }
            }

            if (!is_file($targetPath) && str_starts_with($publicPath, '/api/personalization/photos/')) {
                $fallbackPath = $legacyDirectory . '/' . basename(parse_url($publicPath, PHP_URL_PATH) ?: $storedFilename);

                if (is_file($fallbackPath)) {
                    @rename($fallbackPath, $targetPath);
                }
            }

            if (is_file($targetPath)) {
                $imageInfo = @getimagesize($targetPath);

                if (false !== $imageInfo) {
                    $width = (int) ($imageInfo[0] ?? 0) ?: null;
                    $height = (int) ($imageInfo[1] ?? 0) ?: null;
                }

                $checksum = hash_file('sha256', $targetPath) ?: null;
            }

            $this->addSql(
                'UPDATE app_uploaded_photo SET storage_path = :storagePath, access_token = :accessToken, image_width = :imageWidth, image_height = :imageHeight, sha256_checksum = :sha256Checksum, public_path = :publicPath WHERE id = :id',
                [
                    'storagePath' => $storagePath,
                    'accessToken' => $accessToken,
                    'imageWidth' => $width,
                    'imageHeight' => $height,
                    'sha256Checksum' => $checksum,
                    'publicPath' => sprintf('/api/personalization/photos/%s?token=%s', $id, $accessToken),
                    'id' => $id,
                ],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_uploaded_photo DROP storage_path');
        $this->addSql('ALTER TABLE app_uploaded_photo DROP access_token');
        $this->addSql('ALTER TABLE app_uploaded_photo DROP image_width');
        $this->addSql('ALTER TABLE app_uploaded_photo DROP image_height');
        $this->addSql('ALTER TABLE app_uploaded_photo DROP sha256_checksum');
        $this->addSql('ALTER TABLE app_uploaded_photo DROP deleted_at');
    }
}
