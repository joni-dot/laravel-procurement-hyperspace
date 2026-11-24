<?php

namespace Tests\Unit;

use App\Http\VisitorAddress;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class VisitorAddressTest extends TestCase
{
    public function test_ipv4_addresses_count_one_by_one()
    {
        $this->assertSame('203.0.113.7', VisitorAddress::forLimits($this->from('203.0.113.7')));
    }

    public function test_ipv6_addresses_in_one_64_block_count_as_one_visitor()
    {
        $this->assertSame('2001:db8:1:2::/64', VisitorAddress::forLimits($this->from('2001:db8:1:2:aaaa::1')));
        $this->assertSame('2001:db8:1:2::/64', VisitorAddress::forLimits($this->from('2001:db8:1:2:ffff:ffff:ffff:ffff')));
        $this->assertSame('2001:db8:1:3::/64', VisitorAddress::forLimits($this->from('2001:db8:1:3::1')));
    }

    public function test_ipv4_addresses_written_as_ipv6_still_count_one_by_one()
    {
        $this->assertSame('::ffff:203.0.113.7', VisitorAddress::forLimits($this->from('::ffff:203.0.113.7')));
    }

    private function from(string $address): Request
    {
        return Request::create('/', server: ['REMOTE_ADDR' => $address]);
    }
}
