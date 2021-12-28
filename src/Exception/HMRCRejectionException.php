<?php

declare(strict_types=1);

namespace ClaimBot\Exception;

class HMRCRejectionException extends ClaimException
{
    protected array $rawHMRCErrors = [];

    public function setRawHMRCErrors(array $rawHMRCErrors): void
    {
        $this->rawHMRCErrors = $rawHMRCErrors;
    }
}
