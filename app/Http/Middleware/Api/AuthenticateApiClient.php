<?php

namespace App\Http\Middleware\Api;

use App\Companies\CurrentCompany;
use App\Models\ApiClient;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Laravel\Passport\AccessToken;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Lets a request through only with a valid, unexpired and unrevoked access token of an active API client,
 * and opens the client's company: the request sees that company's data and nothing else.
 *
 * Implementing AuthenticatesRequests makes Laravel run this before route model binding,
 * so callers without a token cannot learn which records exist from 404 answers, and records in
 * the address are looked up inside the client's company.
 */
class AuthenticateApiClient implements AuthenticatesRequests
{
    public function __construct(
        private readonly ResourceServer $server,
        private readonly CurrentCompany $currentCompany,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->validToken($request);
        $client = $this->activeClientOf($token);
        $request->attributes->set(ApiClient::class, $client);

        // A token issued just before the company was suspended is still unexpired for a few minutes.
        if ($client->company->isSuspended()) {
            throw new AccessDeniedHttpException('This company\'s account is suspended.');
        }

        $this->currentCompany->set($client->company);
        $client->recordUse();
        $request->attributes->set(AccessToken::class, $token);

        return $next($request);
    }

    /**
     * @return AccessToken<mixed>
     */
    private function validToken(Request $request): AccessToken
    {
        try {
            $psrRequest = $this->server->validateAuthenticatedRequest((new PsrHttpFactory)->createRequest($request));
        } catch (OAuthServerException) {
            throw new AuthenticationException;
        }

        return AccessToken::fromPsrRequest($psrRequest);
    }

    /**
     * A client credentials token names the client itself as its subject. A token issued to a person
     * would name their user id instead; this API serves only systems acting for themselves.
     *
     * @param  AccessToken<mixed>  $token
     */
    private function activeClientOf(AccessToken $token): ApiClient
    {
        $isClientToken = $token->oauth_user_id === $token->oauth_client_id;
        $client = $isClientToken ? ApiClient::query()->active()->with('company')->find($token->oauth_client_id) : null;

        return $client ?? throw new AuthenticationException;
    }
}
