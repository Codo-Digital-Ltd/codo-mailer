<?php
namespace CodoDigital\Mailer\Tests\Unit\Transport;

use CodoDigital\Mailer\Tests\TestCase;
use CodoDigital\Mailer\Transport\AwsSigV4;

class AwsSigV4Test extends TestCase {

	const ACCESS = 'AKIDEXAMPLE';
	const SECRET = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';

	private function sign( $method, $url, array $headers = array(), $body = '' ) {
		return ( new AwsSigV4() )->sign( $method, $url, $headers, $body, 'us-east-1', 'service', self::ACCESS, self::SECRET, gmmktime( 12, 36, 0, 8, 30, 2015 ) );
	}

	/**
	 * AWS Signature V4 test suite, "get-vanilla".
	 */
	public function test_matches_the_official_aws_test_vector() {
		$headers = $this->sign( 'GET', 'https://example.amazonaws.com/' );

		$this->assertSame( '20150830T123600Z', $headers['x-amz-date'] );
		$this->assertSame(
			'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, SignedHeaders=host;x-amz-date, Signature=5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31',
			$headers['Authorization']
		);
		$this->assertArrayNotHasKey( 'host', $headers, 'the HTTP client sets Host itself' );
	}

	public function test_missing_path_is_treated_as_root() {
		$this->assertSame(
			$this->sign( 'GET', 'https://example.amazonaws.com/' )['Authorization'],
			$this->sign( 'GET', 'https://example.amazonaws.com' )['Authorization']
		);
	}

	public function test_query_parameters_are_sorted() {
		$this->assertSame(
			$this->sign( 'GET', 'https://example.amazonaws.com/?b=2&a=1&a=0&flag' )['Authorization'],
			$this->sign( 'GET', 'https://example.amazonaws.com/?a=0&a=1&b=2&flag=' )['Authorization']
		);
	}

	public function test_header_names_are_case_insensitive_and_values_trimmed() {
		$this->assertSame(
			$this->sign( 'POST', 'https://example.amazonaws.com/x', array( 'Content-Type' => '  application/json ' ), '{}' )['Authorization'],
			$this->sign( 'POST', 'https://example.amazonaws.com/x', array( 'content-type' => 'application/json' ), '{}' )['Authorization']
		);
	}

	public function test_body_is_part_of_the_signature() {
		$this->assertNotSame(
			$this->sign( 'POST', 'https://example.amazonaws.com/x', array(), '{"a":1}' )['Authorization'],
			$this->sign( 'POST', 'https://example.amazonaws.com/x', array(), '{"a":2}' )['Authorization']
		);
	}

	public function test_signed_headers_include_custom_headers() {
		$headers = $this->sign( 'POST', 'https://example.amazonaws.com/v2/x', array( 'content-type' => 'application/json' ), '{}' );
		$this->assertStringContainsString( 'SignedHeaders=content-type;host;x-amz-date', $headers['Authorization'] );
		$this->assertSame( 'application/json', $headers['content-type'] );
	}
}
