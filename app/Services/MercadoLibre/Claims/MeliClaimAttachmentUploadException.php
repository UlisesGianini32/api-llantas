<?php

namespace App\Services\MercadoLibre\Claims;

use RuntimeException;
use Throwable;

class MeliClaimAttachmentUploadException extends RuntimeException
{
    public function __construct(
        public readonly int $remoteStatus,
        public readonly string $errorCode = 'attachment_upload_failed',
        ?Throwable $previous = null,
    )
    {
        parent::__construct('No fue posible cargar todos los archivos.', $remoteStatus, $previous);
    }
}
