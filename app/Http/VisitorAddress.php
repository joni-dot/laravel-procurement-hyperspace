<?php

namespace App\Http;

use Illuminate\Http\Request;

/**
 * The visitor's address as rate limits should count it. An IPv6 visitor usually gets a whole /64 block
 * of addresses from their provider and can pick a new one for every request, so a limit per address
 * would never be reached: IPv6 addresses count per /64 block. IPv4 addresses count one by one.
 */
final class VisitorAddress
{
    public static function forLimits(Request $request): string
    {
        $address = (string) $request->ip();
        $packed = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? inet_pton($address) : false;

        if ($packed === false || str_starts_with($packed, "\0\0\0\0\0\0\0\0\0\0\xff\xff")) {
            return $address;
        }

        return (string) inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8)).'/64';
    }
}
