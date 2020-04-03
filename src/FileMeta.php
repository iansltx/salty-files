<?php

namespace App;

use Ramsey\Uuid\UuidInterface;

class FileMeta implements \JsonSerializable
{
    protected UuidInterface $id;
    protected string $filename;
    protected string $contentType;
    protected int $size;
    protected UuidInterface $ownerId;
    protected string $ownerName;
    protected array $sharedWith;
    protected bool $isMine;

    public function __construct(
        User $currentUser, UuidInterface $id, string $filename, string $contentType, int $size,
        UuidInterface $ownerId, string $ownerName, array $sharedWith = []
    ) {
        $this->id = $id;
        $this->filename = $filename;
        $this->contentType = $contentType;
        $this->size = $size;
        $this->ownerId = $ownerId;
        $this->ownerName = $ownerName;
        $this->sharedWith = $sharedWith;
        $this->isMine = $currentUser->getId()->compareTo($ownerId) === 0;
    }

    public function jsonSerialize()
    {
        return [
            'id' => $this->id->toString(),
            'filename' => $this->filename,
            'content_type' => $this->contentType,
            'size' => $this->size,
            'owner' => [
                'id' => $this->ownerId->toString(),
                'username' => $this->ownerName,
                'is_self' => $this->isMine
            ],
            'shared_with' => array_map(fn ($username) => ['username' => $username], $this->sharedWith)
        ];
    }
}
