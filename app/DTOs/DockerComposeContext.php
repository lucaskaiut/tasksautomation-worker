<?php

namespace App\DTOs;

readonly class DockerComposeContext extends DataTransferObject
{
    public function __construct(
        public bool $enabled,
        public string $composeFilePath,
        public ?string $execService,
    ) {}
}
