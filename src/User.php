<?php

namespace App;

use ParagonIE\Halite\Asymmetric\EncryptionSecretKey;
use Ramsey\Uuid\UuidInterface;

class User
{
    protected UuidInterface $id;
    protected string $username;
    protected EncryptionSecretKey $key;

    public function __construct(UuidInterface $id, string $username, EncryptionSecretKey $key)
    {
        $this->id = $id;
        $this->username = $username;
        $this->key = $key;
    }

    public function getId(): UuidInterface
    {
        return $this->id;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getKey(): EncryptionSecretKey
    {
        return $this->key;
    }
}
