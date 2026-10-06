<?php

namespace Korbytes\AiGateway;

/** Closed list of failure reason codes. Never carries prompt, response or key text. */
enum AiFailure: string
{
    case Auth = 'auth';
    case RateLimit = 'rate_limit';
    case Timeout = 'timeout';
    case InvalidRequest = 'invalid_request';
    case ContentFiltered = 'content_filtered';
    case ServerError = 'server_error';
    case Connection = 'connection';
    case BadResponse = 'bad_response';
    case MissingCredentials = 'missing_credentials';
    case CredentialsUnavailable = 'credentials_unavailable';
    case UnknownDriver = 'unknown_driver';
    case UnknownProvider = 'unknown_provider';
    case ProviderInactive = 'provider_inactive';
    case UnknownModel = 'unknown_model';
}
