<?php

declare(strict_types=1);

namespace ShowOfHands\Http;

use ShowOfHands\Domain\VoiceGroupError;

/**
 * The form that adds a voice group, with the values as entered and, after a refused add, the
 * field the message belongs to.
 */
final readonly class VoiceGroupForm
{
    /**
     * @param string|null $errorField key or label
     * @param string|null $errorKey the message key for that field, also shown as the toast
     */
    public function __construct(
        public string $key = '',
        public string $label = '',
        public ?string $errorField = null,
        public ?string $errorKey = null,
    ) {}

    public function refused(VoiceGroupError $error): self
    {
        [$field, $message] = match ($error) {
            VoiceGroupError::KeyMissing => ['key', 'toast.group.missing'],
            VoiceGroupError::LabelMissing => ['label', 'toast.group.missing'],
            VoiceGroupError::KeyInvalid => ['key', 'toast.group.key_invalid'],
            VoiceGroupError::KeyTaken => ['key', 'toast.group.taken'],
        };

        return new self($this->key, $this->label, $field, $message);
    }
}
