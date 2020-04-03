<?php

namespace App;

use Aura\Sql\ExtendedPdo;
use ParagonIE\Halite\Asymmetric\EncryptionSecretKey;
use ParagonIE\Halite\KeyFactory;
use ParagonIE\Halite\Password;
use ParagonIE\Halite\Symmetric\Crypto as SymmCrypto;
use ParagonIE\Halite\Symmetric\EncryptionKey;
use ParagonIE\HiddenString\HiddenString;
use ParagonIE\Paseto\Builder;
use ParagonIE\Paseto\Exception\PasetoException;
use ParagonIE\Paseto\Keys\Version2\SymmetricKey;
use ParagonIE\Paseto\Parser;
use ParagonIE\Paseto\Protocol\Version2;
use ParagonIE\Paseto\ProtocolCollection;
use ParagonIE\Paseto\Rules\IssuedBy;
use Ramsey\Uuid\Uuid;
use Slim\Http\ServerRequest;

class AuthRepository
{
    protected ExtendedPdo $db;
    protected EncryptionKey $passwordKey;
    protected SymmetricKey $tokenKey;
    protected string $issuer;

    public function __construct(ExtendedPdo $db, EncryptionKey $passwordKey, SymmetricKey $tokenKey, string $issuer)
    {
        $this->db = $db;
        $this->passwordKey = $passwordKey;
        $this->tokenKey = $tokenKey;
        $this->issuer = $issuer;
    }

    public function login(?string $username, ?string $password)
    {
        if (!$username || !$password) {
            throw new \InvalidArgumentException('Username and password are required');
        }

        $userInfo = $this->db->fetchOne('SELECT id, password, private_key FROM user WHERE username = ?', [$username]);
        if (!$userInfo) {
            throw new \InvalidArgumentException('The credentials you provided do not match');
        }

        ['password' => $encryptedAndHashedPassword, 'private_key' => $encryptedPrivateKey, 'id' => $userId] = $userInfo;
        if (!Password::verify($hiddenPassword = new HiddenString($password), $encryptedAndHashedPassword, $this->passwordKey)) {
            throw new \InvalidArgumentException('The credentials you provided do not match');
        }

        // username and password have been verified; derive the key used to encrypt the user's private key from
        // the password and the hashed/encrypted version of the password, then decrypt the asymmetric private key
        $derivedKey = KeyFactory::deriveEncryptionKey($hiddenPassword, substr($encryptedAndHashedPassword, -16));
        $privateKey = new EncryptionSecretKey(SymmCrypto::decrypt($encryptedPrivateKey, $derivedKey));

        // create session in the database
        $expiresAt = new \DateTimeImmutable('+1 hour');
        $sessionId = bin2hex(random_bytes(32));
        $this->db->perform('INSERT INTO session (id, user_id, expires_at) VALUES (?, ?, ?)',
            [$sessionId, $userId, $expiresAt->format('Y-m-d H:i:s')]);

        // generate PASETO token, including user private key; token is encrypted using a key known
        // only to the server, so the private key is only readable with the server's help, and changing
        // the server-side key invalidates all outstanding tokens
        return [
            'token' => Builder::getLocal($this->tokenKey, new Version2())
                ->withExpiration(new \DateTime('+1 hour'))
                ->withIssuer($this->issuer)
                ->withJti($sessionId)
                ->with('key', base64_encode($privateKey->getRawKeyMaterial()))
                ->toString()
        ];
    }

    public function validateSession(string $token): User
    {
        $parser = Parser::getLocal($this->tokenKey, ProtocolCollection::v2())->addRule(new IssuedBy($this->issuer));

        try {
            $validatedToken = $parser->parse($token);
        } catch (PasetoException $e) {
            throw new \InvalidArgumentException('Authentication failed', 401);
        }

        // validate session against database as well, pulling user info in the process
        if (!($userInfo = $this->db->fetchOne('SELECT user.id, user.username
                FROM session JOIN user ON user.id = session.user_id WHERE expires_at > NOW() && session.id = ?',
                [$validatedToken->getJti()]))) {
            throw new \InvalidArgumentException('Authentication failed', 401);
        }

        return new User(
            Uuid::fromString($userInfo['id']),
            $userInfo['username'],
            new EncryptionSecretKey(new HiddenString(base64_decode($validatedToken->get('key'))))
        );
    }
}
