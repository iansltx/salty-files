<?php

namespace App;

use Aura\Sql\ExtendedPdo;
use League\Flysystem\Filesystem;
use ParagonIE\Halite\Asymmetric\Crypto as AsymmCrypto;
use ParagonIE\Halite\Asymmetric\EncryptionPublicKey;
use ParagonIE\Halite\Halite;
use ParagonIE\Halite\KeyFactory;
use ParagonIE\Halite\Symmetric\Crypto as SymmCrypto;
use ParagonIE\Halite\Symmetric\EncryptionKey;
use ParagonIE\HiddenString\HiddenString;
use Psr\Http\Message\UploadedFileInterface;
use Ramsey\Uuid\Uuid;
use Slim\Http\Response;

class FileRepository
{
    protected ExtendedPdo $db;
    protected Filesystem $fs;

    public function __construct(ExtendedPdo $db, Filesystem $fs)
    {
        $this->db = $db;
        $this->fs = $fs;
    }

    /**
     * @param User $user
     * @return FileMeta[]
     */
    public function forUser(User $user): array
    {
        $filesById = [];

        // grab all files accessible to the current user
        foreach ($this->db->fetchAll('SELECT file.id, filename, content_type, size, user.id owner_id, username
                FROM file JOIN user ON user.id = file.user_id JOIN file_key ON file_key.file_id = file.id
                WHERE file_key.user_id = ?', [$user->getId()->toString()]) as $file) {
            $file['shares'] = [];
            $filesById[$file['id']] = $file;
        }

        foreach ($this->db->fetchAll('SELECT file_id, username FROM file_key
                JOIN file ON file_key.file_id = file.id && file_key.user_id != file.user_id
                JOIN user ON user.id = file_key.user_id
                WHERE file.user_id = ?', [$user->getId()->toString()]) as $share) {
            $filesById[$share['file_id']]['shares'][] = $share['username'];
        }

        return array_values(array_map(function ($row) use ($user) {
            return new FileMeta(
                $user, Uuid::fromString($row['id']), $row['filename'], $row['content_type'], $row['size'],
                Uuid::fromString($row['owner_id']), $row['username'], $row['shares']
            );
        }, $filesById));
    }

    public function retrieve(string $id, User $user, Response $response): Response
    {
        // ensure we have access to the file
        if (!($fileRow = $this->db->fetchOne('SELECT encrypted_key, filename, content_type, public_key owner_pubkey
                FROM file_key JOIN file ON file.id = file_key.file_id JOIN user ON file.user_id = user.id
                WHERE file_id = ? && file_key.user_id = ?', [$id, $user->getId()->toString()]))) {
            throw new \InvalidArgumentException('File does not exist or is inaccessible', 404);
        }
        [
            'owner_pubkey' => $ownerPublicKey, 'encrypted_key' => $encryptedFileKey,
            'content_type' => $contentType, 'filename' => $filename
        ] = $fileRow;

        // decrypt + verify the file key
        $fileKey = new EncryptionKey(AsymmCrypto::decrypt(
            $encryptedFileKey,
            $user->getKey(),
            new EncryptionPublicKey(new HiddenString($ownerPublicKey))
        ));

        // we now have the decrypted per-file symmetric key, so we can retrieve and decrypt the file;
        // we're using strings rather than files here because it's easier to make Flysystem and Halite
        // work together that way
        return $response
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '")')
            ->write(SymmCrypto::decrypt($this->fs->read($id), $fileKey)->getString());
    }

    public function delete(string $id, User $user)
    {
        $this->assertUserOwnsFile($user, $id);

        $this->db->perform('DELETE FROM file_key WHERE file_id = ?', [$id]);
        $this->db->perform('DELETE FROM file WHERE id = ?', [$id]);
        $this->fs->delete($id);
    }

    public function share(string $id, string $username, User $user): void
    {
        if ($username === $user->getUsername()) {
            throw new \InvalidArgumentException('You cannot share a file with yourself');
        }

        // only owners can share files
        if (!($encryptedFileKey = $this->db->fetchValue('SELECT encrypted_key FROM file_key
                    JOIN file ON file.id = file_key.file_id WHERE file.id = ? && file.user_id = ?',
                [$id, $user->getId()->toString()]))) {
            throw new \InvalidArgumentException('File does not exist or is inaccessible', 404);
        }

        if (!($userInfo = $this->db->fetchOne('SELECT id, public_key FROM user WHERE username = ?', [$username]))) {
            throw new \InvalidArgumentException('The username you specified does not exist');
        }
        ['id' => $userId, 'public_key' => $recipientPublicKeyStr] = $userInfo;

        if ($this->db->fetchValue('SELECT id FROM file_key WHERE user_id = ? && file_id = ?', [$userId, $id])) {
            return; // file is already shared with the specified user; we're done here
        }

        // if we get here, we're allowed to share the file, the user exists, and they don't already have access;
        // decrypt the file key with our private key, as we need to re-encrypt it for the recipient user
        $decryptedFileKey = AsymmCrypto::decrypt(
            $encryptedFileKey,
            $user->getKey(),
            new EncryptionPublicKey(new HiddenString($user->getKey()->derivePublicKey()))
        );

        // re-encrypt with the recipient user's public key
        $reEncryptedFileKey = AsymmCrypto::encrypt(
            $decryptedFileKey,
            $user->getKey(),
            new EncryptionPublicKey(new HiddenString($recipientPublicKeyStr))
        );

        $this->db->perform('INSERT INTO file_key (user_id, file_id, encrypted_key) VALUES (?, ?, ?)',
                [$userId, $id, $reEncryptedFileKey]);
    }

    public function unshare(string $id, string $username, User $user): void
    {
        $this->assertUserOwnsFile($user, $id);
        if ($username === $user->getUsername()) {
            throw new \InvalidArgumentException('You cannot unshare a file with yourself. Delete it instead.');
        }

        $this->db->perform('DELETE FROM user_file WHERE file_id = ?
                                && user_id IN (SELECT id FROM user WHERE username = ?)', [$id, $username]);
    }

    public function upload(?UploadedFileInterface $input, User $user): FileMeta
    {
        // set up IDs and per-file encryption key
        $id = Uuid::uuid4();
        $fileKey = KeyFactory::generateEncryptionKey();

        // encrypt file key with our own public key for storage
        $encryptedFileKey = AsymmCrypto::encrypt(
            new HiddenString($fileKey->getRawKeyMaterial()),
            $user->getKey(),
            $user->getKey()->derivePublicKey()
        );

        // encrypt file and write to storage
        $this->fs->write(
            $id->toString(),
            SymmCrypto::encrypt(new HiddenString($input->getStream()->getContents()), $fileKey)
        );

        // persist file metadata and our (encrypted) per-file key
        $this->db->perform('INSERT INTO file (id, user_id, size, content_type, filename) VALUES (?, ?, ?, ?, ?)', [
            $id->toString(), $user->getId()->toString(),
            $input->getSize(), $input->getClientMediaType(), $input->getClientFilename()
        ]);
        $this->db->perform('INSERT INTO file_key (file_id, user_id, encrypted_key) VALUES (?, ?, ?)', [
            $id->toString(), $user->getId()->toString(), $encryptedFileKey
        ]);

        return new FileMeta($user, $id, $input->getClientFilename(), $input->getClientMediaType(), $input->getSize(),
                $user->getId(), $user->getUsername());
    }

    protected function assertUserOwnsFile(User $user, string $id)
    {
        if (!$this->db->fetchValue(
                'SELECT id FROM file WHERE user_id = ? && id = ?', [$user->getId()->toString(), $id])) {
            throw new \InvalidArgumentException('File does not exist or is inaccessible', 404);
        }
    }
}
