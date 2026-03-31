<?php

declare(strict_types=1);

namespace App\Approov\Exceptions;

enum ApproovErrorCode: string
{
    case MissingApproovToken = 'missing_approov_token';
    case InvalidTokenFormat = 'invalid_token_format';
    case UnsupportedTokenAlgorithm = 'unsupported_token_algorithm';
    case InvalidTokenSignature = 'invalid_token_signature';
    case TokenExpired = 'token_expired';
    case MissingBindingHeader = 'missing_binding_header';
    case MissingPayClaim = 'missing_pay_claim';
    case BindingMismatch = 'binding_mismatch';
    case ApproovSecretMissing = 'approov_secret_missing';
    case ApproovSecretInvalid = 'approov_secret_invalid';
    case InternalVerificationError = 'internal_verification_error';
}
