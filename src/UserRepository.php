<?php

namespace App;

use Aura\Sql\ExtendedPdo;
use ParagonIE\Halite\Alerts\InvalidKey;
use ParagonIE\Halite\Asymmetric\EncryptionSecretKey;
use ParagonIE\Halite\KeyFactory;
use ParagonIE\Halite\Password;
use ParagonIE\Halite\Symmetric\Crypto as SymmCrypto;
use ParagonIE\Halite\Symmetric\EncryptionKey;
use ParagonIE\HiddenString\HiddenString;
use Ramsey\Uuid\Uuid;

class UserRepository
{
    protected ExtendedPdo $db;
    protected EncryptionKey $key;

    public function __construct(ExtendedPdo $db, EncryptionKey $key)
    {
        $this->db = $db;
        $this->key = $key;
    }

    public function create(?string $username, ?string $password, ?string $passwordConfirmation)
    {
        // perform basic validation
        if (!$username || !$password || !$passwordConfirmation) {
            throw new \InvalidArgumentException('Username, password, and password confirmation are required');
        }

        if ($this->db->fetchValue('SELECT COUNT(*) FROM user WHERE username = ?', [$username])) {
            throw new \InvalidArgumentException('Username is already taken');
        }

        if (strlen($password) < 12) {
            throw new \InvalidArgumentException('Password must be at least 12 characters');
        }

        if ($password !== $passwordConfirmation) {
            throw new \InvalidArgumentException('Passwords do not match');
        }

        // set up encrypted + hashed password, key pair used to interact with per-file symmetric encryption keys
        $encryptedAndHashedPassword = Password::hash($hiddenPassword = new HiddenString($password), $this->key);
        $keyPair = KeyFactory::generateEncryptionKeyPair();

        // we use symmetric key to encrypt the private half of the key pair
        $derivedKey = KeyFactory::deriveEncryptionKey($hiddenPassword, substr($encryptedAndHashedPassword, -16));

        $this->db->perform('INSERT INTO user (id, username, password, public_key, private_key) VALUES (?, ?, ?, ?, ?)', [
                Uuid::uuid4()->toString(),
                $username,
                $encryptedAndHashedPassword,
                $keyPair->getPublicKey()->getRawKeyMaterial(),
                SymmCrypto::encrypt(
                    new HiddenString($keyPair->getSecretKey()->getRawKeyMaterial()), $derivedKey
                ),
        ]);
    }

    public function updatePassword(User $user, ?string $currentPW, ?string $newPW, ?string $newPWConfirmation)
    {
        // basic validation
        if (!$currentPW || !$newPW || !$newPWConfirmation) {
            throw new \InvalidArgumentException('Current password, new password, and password confirmation are required');
        }

        if (strlen($newPW) < 12) {
            throw new \InvalidArgumentException('Password must be at least 12 characters');
        }

        if ($newPW !== $newPWConfirmation) {
            throw new \InvalidArgumentException('New password and confirmation do not match');
        }

        // done with basic validation; grab existing password from the database
        if (!($oldEncryptedAndHashedPassword =
                $this->db->fetchValue('SELECT password FROM user WHERE id = ?', [$user->getId()->toString()]))) {
            throw new \InvalidArgumentException('User not found');
        }

        $hiddenCurrentPassword = new HiddenString($currentPW);
        if (!Password::verify($hiddenCurrentPassword, $oldEncryptedAndHashedPassword, $this->key)) {
            throw new \InvalidArgumentException('Current password does not match');
        }

        // derive a new symmetric key from our new password to re-encrypt our private key,
        // which we have via the user's active session
        $hiddenNewPassword = new HiddenString($newPW);
        $newEncryptedAndHashedPassword = Password::hash($hiddenNewPassword, $this->key);
        $derivedKey = KeyFactory::deriveEncryptionKey($hiddenNewPassword, substr($newEncryptedAndHashedPassword, -16));

        // re-encrypt our private key with the new password
        $encryptedPrivateKey = SymmCrypto::encrypt(new HiddenString($user->getKey()->getRawKeyMaterial()), $derivedKey);

        // save the updated hashed password and encrypted private key
        $this->db->perform('UPDATE user SET password = ?, private_key = ? WHERE id = ?', [
            $newEncryptedAndHashedPassword, $encryptedPrivateKey, $id
        ]);
    }

    public function resetPassword(?string $username, ?string $key, ?string $newPW, ?string $newPWConfirmation)
    {
        if (!$username || !$key || !$newPW || !$newPWConfirmation) {
            throw new \InvalidArgumentException('Username and key are required');
        }

        if (strlen($newPW) < 12) {
            throw new \InvalidArgumentException('Password must be at least 12 characters');
        }

        if ($newPW !== $newPWConfirmation) {
            throw new \InvalidArgumentException('Passwords do not match');
        }

        try {
            $privateKey = new EncryptionSecretKey(new HiddenString(base64_decode($key)));
        } catch (InvalidKey $e) {
            throw new \InvalidArgumentException('They key you entered does not match this account');
        }

        if (!($publicKeyStr = $this->db->fetchValue('SELECT public_key FROM user WHERE username = ?', [$username]))) {
            throw new \InvalidArgumentException('User does not exist');
        }

        // make sure the supplied private key matches the one for this user
        if (!hash_equals($publicKeyStr, $privateKey->derivePublicKey()->getRawKeyMaterial())) {
            throw new \InvalidArgumentException('They key you entered does not match this account');
        }

        // at this point, we've confirmed that the key provided is the correct one,
        // so we can build out a new derived key from the new password and re-encrypt the key with it
        $hiddenNewPassword = new HiddenString($newPW);
        $newEncryptedAndHashedPassword = Password::hash($hiddenNewPassword, $this->key);
        $derivedKey = KeyFactory::deriveEncryptionKey($hiddenNewPassword, substr($newEncryptedAndHashedPassword, -16));

        // encrypt our private key with the new password
        $encryptedPrivateKey = SymmCrypto::encrypt(new HiddenString($privateKey->getRawKeyMaterial()), $derivedKey);

        // save the updated hashed password and encrypted private key
        $this->db->perform('UPDATE user SET password = ?, private_key = ? WHERE username = ?', [
            $newEncryptedAndHashedPassword, $encryptedPrivateKey, $username
        ]);
    }
}
