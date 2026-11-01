<?php

declare(strict_types=1);

namespace ShowOfHands\Domain;

/** Why a new voice group was refused. */
enum VoiceGroupError
{
    case KeyMissing;
    case LabelMissing;
    case KeyInvalid;
    case KeyTaken;
}
